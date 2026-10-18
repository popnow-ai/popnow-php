<?php

declare(strict_types=1);

namespace PopNow;

/** A webhook delivery failed verification and must be discarded, not processed. */
final class WebhookError extends \RuntimeException
{
    /**
     * @param string $reason One of: body_too_large, invalid_json, invalid_payload,
     *                       invalid_secret, invalid_signature, invalid_timestamp,
     *                       stale_timestamp, missing_header.
     */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
