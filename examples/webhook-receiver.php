<?php

/**
 * A plain-PHP receiver for PopNow task callbacks and account endpoint events.
 *
 * Point `callBackUrl` (task callbacks) or an account endpoint in the API console at this script,
 * served over public HTTPS. For local testing, a tunnel such as ngrok or Cloudflare Tunnel works:
 *
 *   POPNOW_WEBHOOK_SECRET=... POPNOW_ENDPOINT_SECRET=whsec_... php -S 127.0.0.1:8080 examples/webhook-receiver.php
 *
 * Task callbacks carry X-Webhook-Signature and are signed with the account's webhook signing
 * secret; account endpoints carry webhook-id / webhook-signature (Standard Webhooks) and are signed
 * with that endpoint's own whsec_ secret.
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use PopNow\EndpointWebhook;
use PopNow\Webhook;
use PopNow\WebhookError;

// Read the exact bytes once; both verifiers need the raw body, never a re-encoded copy.
$rawBody = (string) file_get_contents('php://input');

try {
    if (isset($_SERVER['HTTP_WEBHOOK_ID'])) {
        $event = EndpointWebhook::verifyRequest(getenv('POPNOW_ENDPOINT_SECRET') ?: '', rawBody: $rawBody);
        $dedupeKey = $event->webhookId;
        $task = $event->taskRecord() ?? [];
        $summary = sprintf('%s %s %s', $event->type() ?? '?', $task['taskId'] ?? '?', $task['state'] ?? '?');
    } else {
        $delivery = Webhook::verifyRequest(getenv('POPNOW_WEBHOOK_SECRET') ?: '', rawBody: $rawBody);
        $dedupeKey = $delivery->deliveryId ?? $delivery->taskId;
        $summary = sprintf('callback %s %s', $delivery->taskId, $delivery->state()?->value ?? '?');
    }
} catch (WebhookError $error) {
    // Never process an unverified delivery. 401 makes PopNow retry it later.
    http_response_code(401);
    error_log('rejected webhook: ' . $error->reason);
    exit;
}

// Acknowledge within 15 seconds, then do the slow work (downloads, notifications) elsewhere.
// Deliveries are retried, so store $dedupeKey with a unique index and skip repeats.
http_response_code(200);
error_log(sprintf('webhook %s: %s', $dedupeKey, $summary));
