<?php

declare(strict_types=1);

namespace PopNow;

/**
 * Task lifecycle states.
 *
 * openapi.yaml `TaskRecord.state`: queued, running, succeeded, failed,
 * canceled, expired. Four of the six are terminal. `canceled` only appears on
 * old records, but it is still a state a client must recognise.
 */
enum TaskState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Expired = 'expired';

    /** A terminal task never changes state again; stop polling. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Canceled, self::Expired => true,
            self::Queued, self::Running => false,
        };
    }
}
