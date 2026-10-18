<?php

declare(strict_types=1);

namespace PopNow;

/**
 * A local deadline elapsed. This says nothing about the remote task.
 *
 * A wait timeout is not a failure of the generation: the task is probably still
 * running and will still be billed. Persist `taskId` and reconcile it later, or
 * use a webhook.
 */
final class TimeoutError extends ApiError
{
    public function __construct(
        string $message,
        public readonly ?string $taskId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
