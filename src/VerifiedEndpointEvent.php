<?php

declare(strict_types=1);

namespace PopNow;

/** An account endpoint event (Standard Webhooks) that passed every check. */
final class VerifiedEndpointEvent
{
    /**
     * @param array<string,mixed> $event     The decoded body, exactly as delivered.
     * @param string              $webhookId `webhook-id`: stable across retries, use it to drop duplicates.
     * @param int                 $timestamp Unix seconds from `webhook-timestamp`.
     */
    public function __construct(
        public readonly array $event,
        public readonly string $webhookId,
        public readonly int $timestamp,
    ) {
    }

    /** `task.completed`, `task.succeeded`, `task.failed`, `task.expired` or `task.canceled`. */
    public function type(): ?string
    {
        return is_string($this->event['type'] ?? null) ? $this->event['type'] : null;
    }

    /**
     * `data.object`: the task record at the moment the event was created.
     *
     * @return array<string,mixed>|null
     */
    public function taskRecord(): ?array
    {
        $data = $this->event['data'] ?? null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;

        return is_array($object) ? $object : null;
    }
}
