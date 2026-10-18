<?php

declare(strict_types=1);

namespace PopNow;

/** A task callback delivery that passed every check. Safe to act on. */
final class VerifiedWebhook
{
    /**
     * @param array<string,mixed> $payload    The decoded body, exactly as delivered.
     * @param int                 $timestamp  Unix seconds from `X-Webhook-Timestamp`.
     * @param string|null         $deliveryId Stable delivery ID (`request_id`), payload version 2 only.
     */
    public function __construct(
        public readonly array $payload,
        public readonly int $payloadVersion,
        public readonly string $taskId,
        public readonly int $timestamp,
        public readonly ?string $deliveryId = null,
    ) {
    }

    /** The task state. Both payload versions spell this key the same way. */
    public function state(): ?TaskState
    {
        $record = $this->payloadVersion === 2 ? ($this->payload['data'] ?? null) : $this->payload;
        if (!is_array($record) || !is_string($record['state'] ?? null)) {
            return null;
        }

        return TaskState::tryFrom($record['state']);
    }

    /**
     * The version 2 task record, the same shape `recordInfo` returns.
     *
     * Null for the legacy version 1, whose payload is a different, snake_case
     * shape (`task_id`, `error_code`, ...).
     *
     * @return array<string,mixed>|null
     */
    public function taskRecord(): ?array
    {
        if ($this->payloadVersion !== 2) {
            return null;
        }
        $record = $this->payload['data'] ?? null;

        return is_array($record) ? $record : null;
    }
}
