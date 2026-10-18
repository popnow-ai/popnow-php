<?php

declare(strict_types=1);

namespace PopNow;

/**
 * Verify an account endpoint delivery, which follows the Standard Webhooks format.
 *
 * Account endpoints are registered once in the API console and receive events
 * (`task.completed`, `task.succeeded`, ...) for every task on the account. From
 * https://popnow.ai/docs/webhooks#account-endpoints:
 *
 *   webhook-id: msg_...
 *   webhook-timestamp: 1787045531
 *   webhook-signature: v1,base64( HMAC-SHA256( key, "<webhook-id>.<webhook-timestamp>.<raw_body>" ) )
 *
 * Two differences from {@see Webhook}: the raw body itself is signed (no
 * SHA-256 step), and the HMAC key is the Base64-decoded part of the endpoint
 * secret after `whsec_`, not the secret string. During a secret rotation the
 * header can hold several space-separated signatures; any one matching is
 * enough.
 */
final class EndpointWebhook
{
    public const ID_HEADER = 'webhook-id';
    public const TIMESTAMP_HEADER = 'webhook-timestamp';
    public const SIGNATURE_HEADER = 'webhook-signature';

    public const DEFAULT_TOLERANCE_SECONDS = 300;
    public const DEFAULT_MAX_BODY_BYTES = 10 * 1024 * 1024;

    /**
     * Build the `v1,<base64>` signature. Exposed so tests can forge a valid delivery.
     */
    public static function computeSignature(
        string $webhookId,
        string $timestamp,
        string $rawBody,
        #[\SensitiveParameter] string $secret,
    ): string {
        $mac = hash_hmac('sha256', $webhookId . '.' . $timestamp . '.' . $rawBody, self::signingKey($secret), true);

        return 'v1,' . base64_encode($mac);
    }

    /**
     * Verify a delivery whose headers have already been pulled out of the request.
     *
     * @throws WebhookError on any failure. Respond 401 and do not process.
     */
    public static function verify(
        string $rawBody,
        string $webhookId,
        string $timestamp,
        string $signatureHeader,
        #[\SensitiveParameter] string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
        int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
    ): VerifiedEndpointEvent {
        if (strlen($rawBody) > $maxBodyBytes) {
            throw new WebhookError('body_too_large', sprintf('the webhook body exceeds %d bytes', $maxBodyBytes));
        }
        if ($toleranceSeconds < 0) {
            throw new \InvalidArgumentException('toleranceSeconds must not be negative');
        }
        $webhookId = trim($webhookId);
        if ($webhookId === '' || trim($signatureHeader) === '') {
            throw new WebhookError('missing_header', 'webhook-id and webhook-signature are required');
        }
        $timestamp = trim($timestamp);
        if (preg_match('/^\d+$/', $timestamp) !== 1) {
            throw new WebhookError('invalid_timestamp', 'the webhook timestamp must be Unix seconds');
        }

        $expected = self::computeSignature($webhookId, $timestamp, $rawBody, $secret);
        $matched = false;
        foreach (preg_split('/\s+/', trim($signatureHeader)) ?: [] as $candidate) {
            // Every candidate is compared, so the time taken does not reveal which one matched.
            if (hash_equals($expected, $candidate)) {
                $matched = true;
            }
        }
        if (!$matched) {
            throw new WebhookError('invalid_signature', 'the webhook signature does not match');
        }

        $seconds = (int) $timestamp;
        if (abs(($now ?? time()) - $seconds) > $toleranceSeconds) {
            throw new WebhookError('stale_timestamp', sprintf(
                'the webhook timestamp is outside the %d second tolerance window',
                $toleranceSeconds,
            ));
        }

        $event = json_decode($rawBody, true);
        if (!is_array($event) || array_is_list($event)) {
            throw new WebhookError('invalid_json', 'the webhook body is not a JSON object');
        }

        return new VerifiedEndpointEvent($event, $webhookId, $seconds);
    }

    /**
     * Verify the delivery currently being served, reading headers from `$_SERVER`
     * and the body from `php://input` unless `$rawBody` is given.
     *
     * @param array<string,mixed>|null $server Defaults to `$_SERVER`.
     */
    public static function verifyRequest(
        #[\SensitiveParameter] string $secret,
        ?array $server = null,
        ?string $rawBody = null,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
    ): VerifiedEndpointEvent {
        $server ??= $_SERVER;
        $headers = [];
        foreach ([self::ID_HEADER, self::TIMESTAMP_HEADER, self::SIGNATURE_HEADER] as $name) {
            $value = $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? null;
            if (!is_string($value) || $value === '') {
                throw new WebhookError('missing_header', sprintf('%s is missing', $name));
            }
            $headers[$name] = $value;
        }

        return self::verify(
            rawBody: $rawBody ?? (string) file_get_contents('php://input'),
            webhookId: $headers[self::ID_HEADER],
            timestamp: $headers[self::TIMESTAMP_HEADER],
            signatureHeader: $headers[self::SIGNATURE_HEADER],
            secret: $secret,
            toleranceSeconds: $toleranceSeconds,
            maxBodyBytes: $maxBodyBytes,
        );
    }

    /** The HMAC key: the Base64-decoded part of the secret after `whsec_`. */
    private static function signingKey(#[\SensitiveParameter] string $secret): string
    {
        $trimmed = trim($secret);
        if (str_starts_with($trimmed, 'whsec_')) {
            $trimmed = substr($trimmed, strlen('whsec_'));
        }
        $key = base64_decode($trimmed, true);
        if ($trimmed === '' || $key === false || $key === '') {
            throw new WebhookError('invalid_secret', 'the endpoint secret must be whsec_ followed by Base64');
        }

        return $key;
    }
}
