<?php

declare(strict_types=1);

namespace PopNow;

/**
 * Verify a signed task callback delivery (the per-task `callBackUrl`).
 *
 * The scheme, from https://popnow.ai/docs/webhooks and the `onTaskTerminal`
 * callback in openapi.yaml:
 *
 *   signature = base64( HMAC-SHA256( secret, "<taskId>.<timestamp>.<sha256_hex(raw_body)>" ) )
 *
 * `secret` is the account's webhook signing secret (`POPNOW_WEBHOOK_SECRET`,
 * from the Webhooks page of the API console), `taskId` is `data.taskId`
 * (top-level `task_id` for the legacy payload version 1), and `timestamp` is
 * the `X-Webhook-Timestamp` header.
 *
 * Three ways PHP code gets this wrong, all of which are silent:
 *
 *  1. **Re-serializing the body.** The digest covers the bytes that arrived;
 *     `json_encode(json_decode($body))` changes escaping and spacing. Read
 *     `php://input`, keep the string, and hand that exact string here.
 *  2. **Comparing with `==` or `===`.** String comparison leaks timing, and `==`
 *     compares two numeric-looking strings numerically. {@see hash_equals} is
 *     the only correct tool.
 *  3. **Skipping the timestamp.** A valid signature stays valid forever; each
 *     retry is signed again with a fresh timestamp, so check it against now.
 *
 * Deliveries are retried, so the handler must be idempotent regardless:
 * `request_id` is the stable delivery ID to deduplicate on.
 *
 * Account endpoints registered in the console use the Standard Webhooks format
 * instead; verify those with {@see EndpointWebhook}.
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'X-Webhook-Signature';
    public const TIMESTAMP_HEADER = 'X-Webhook-Timestamp';
    public const PAYLOAD_VERSION_HEADER = 'X-Webhook-Payload-Version';

    /** Five minutes, the window the PopNow docs recommend. */
    public const DEFAULT_TOLERANCE_SECONDS = 300;
    public const DEFAULT_MAX_BODY_BYTES = 10 * 1024 * 1024;

    /**
     * Build the expected signature. Exposed so tests can forge a valid delivery.
     *
     * @param string $timestamp The header value verbatim. It is signed as text,
     *                          so re-formatting it as a number breaks the match.
     */
    public static function computeSignature(
        string $taskId,
        string $timestamp,
        string $rawBody,
        #[\SensitiveParameter] string $secret,
    ): string {
        $digest = hash('sha256', $rawBody);

        return base64_encode(hash_hmac('sha256', $taskId . '.' . $timestamp . '.' . $digest, $secret, true));
    }

    /**
     * Verify a delivery whose parts have already been pulled out of the request.
     *
     * @param string $rawBody The body exactly as received. Never a re-encoded copy.
     *
     * @throws WebhookError on any failure. Respond 401 and do not process.
     */
    public static function verify(
        string $rawBody,
        string $timestamp,
        string $signature,
        #[\SensitiveParameter] string $secret,
        int $payloadVersion = 2,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
        int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
    ): VerifiedWebhook {
        if (strlen($rawBody) > $maxBodyBytes) {
            // Measure the length before computing the HMAC, or an oversized body becomes free CPU
            // for whoever sent it.
            throw new WebhookError('body_too_large', sprintf('the webhook body exceeds %d bytes', $maxBodyBytes));
        }
        if (trim($secret) === '') {
            throw new WebhookError('invalid_secret', 'a webhook signing secret is required');
        }
        if ($toleranceSeconds < 0) {
            throw new \InvalidArgumentException('toleranceSeconds must not be negative');
        }
        if ($payloadVersion !== 1 && $payloadVersion !== 2) {
            throw new WebhookError('invalid_payload', sprintf('unsupported payload version "%d"', $payloadVersion));
        }

        $timestamp = trim($timestamp);
        if (preg_match('/^\d+$/', $timestamp) !== 1) {
            throw new WebhookError('invalid_timestamp', 'the webhook timestamp must be Unix seconds');
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || array_is_list($payload)) {
            throw new WebhookError('invalid_json', 'the webhook body is not a JSON object');
        }

        $taskId = self::extractTaskId($payload, $payloadVersion);
        if ($taskId === null) {
            throw new WebhookError('invalid_payload', sprintf(
                'a payload version %d body must carry a task ID at %s',
                $payloadVersion,
                $payloadVersion === 1 ? 'task_id' : 'data.taskId',
            ));
        }

        $expected = self::computeSignature($taskId, $timestamp, $rawBody, $secret);
        if (!hash_equals($expected, trim($signature))) {
            throw new WebhookError('invalid_signature', 'the webhook signature does not match');
        }

        // The timestamp is checked after the signature: verifying first means never interpreting
        // a timestamp of unknown origin.
        $seconds = (int) $timestamp;
        $reference = $now ?? time();
        if (abs($reference - $seconds) > $toleranceSeconds) {
            throw new WebhookError('stale_timestamp', sprintf(
                'the webhook timestamp is outside the %d second tolerance window',
                $toleranceSeconds,
            ));
        }

        $deliveryId = null;
        if ($payloadVersion === 2 && is_string($payload['request_id'] ?? null)) {
            $deliveryId = $payload['request_id'];
        }

        return new VerifiedWebhook($payload, $payloadVersion, $taskId, $seconds, $deliveryId);
    }

    /**
     * Verify the delivery currently being served, reading the raw body and the
     * signature headers from the request itself.
     *
     * The body is read from `php://input`, the only place the exact delivered
     * bytes exist. Inside a framework that already consumed the stream, pass
     * `$rawBody` yourself.
     *
     *   try {
     *       $delivery = Webhook::verifyRequest(getenv('POPNOW_WEBHOOK_SECRET') ?: '');
     *   } catch (WebhookError $error) {
     *       http_response_code(401);
     *       exit;
     *   }
     *   http_response_code(200); // acknowledge first, then do the slow work
     *
     * @param array<string,mixed>|null $server Defaults to `$_SERVER`.
     */
    public static function verifyRequest(
        #[\SensitiveParameter] string $secret,
        ?array $server = null,
        ?string $rawBody = null,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
    ): VerifiedWebhook {
        $server ??= $_SERVER;
        $body = $rawBody ?? (string) file_get_contents('php://input');

        $signature = self::serverHeader($server, self::SIGNATURE_HEADER);
        $timestamp = self::serverHeader($server, self::TIMESTAMP_HEADER);
        $version = self::serverHeader($server, self::PAYLOAD_VERSION_HEADER);
        foreach ([self::SIGNATURE_HEADER => $signature, self::TIMESTAMP_HEADER => $timestamp] as $name => $value) {
            if ($value === null) {
                throw new WebhookError('missing_header', sprintf('%s is missing', $name));
            }
        }
        if ($version !== null && preg_match('/^\d+$/', trim($version)) !== 1) {
            throw new WebhookError('invalid_payload', sprintf('%s is not a number', self::PAYLOAD_VERSION_HEADER));
        }

        return self::verify(
            rawBody: $body,
            timestamp: (string) $timestamp,
            signature: (string) $signature,
            secret: $secret,
            // A missing header is treated as 2: version 2 is what every new integration receives.
            payloadVersion: $version === null ? 2 : (int) $version,
            toleranceSeconds: $toleranceSeconds,
            maxBodyBytes: $maxBodyBytes,
        );
    }

    /**
     * `X-Webhook-Signature` arrives in `$_SERVER` as `HTTP_X_WEBHOOK_SIGNATURE`.
     *
     * @param array<string,mixed> $server
     */
    private static function serverHeader(array $server, string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $server[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string,mixed> $payload */
    private static function extractTaskId(array $payload, int $payloadVersion): ?string
    {
        if ($payloadVersion === 1) {
            return is_string($payload['task_id'] ?? null) && $payload['task_id'] !== ''
                ? $payload['task_id']
                : null;
        }

        $data = $payload['data'] ?? null;
        if (!is_array($data) || !is_string($data['taskId'] ?? null) || $data['taskId'] === '') {
            return null;
        }

        return $data['taskId'];
    }
}
