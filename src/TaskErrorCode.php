<?php

declare(strict_types=1);

namespace PopNow;

/**
 * The closed set of public task failure identifiers.
 *
 * openapi.yaml `TaskRecord.errorCode`. The contract states the set is closed
 * and that any other value "should be handled as `upstream_failed`", which is
 * what {@see self::fromTask()} does: a new platform code must never make a
 * client crash on an unknown enum value.
 *
 * Branch on this, never on `errorMessage`: the message is localized and may
 * pass on the model's own reason verbatim, so its text is not stable.
 */
enum TaskErrorCode: string
{
    case InvalidRequest = 'invalid_request';
    case UnsupportedCombination = 'unsupported_combination';
    case ContentRejected = 'content_rejected';
    case RateLimited = 'rate_limited';
    case UpstreamUnavailable = 'upstream_unavailable';
    case GenerationFailed = 'generation_failed';
    case Timeout = 'timeout';
    case InvalidAsset = 'invalid_asset';
    case UpstreamFailed = 'upstream_failed';

    /**
     * Read `errorCode` off a task record, folding unknown values into
     * `upstream_failed` as the contract requires.
     *
     * @param array<string,mixed> $task
     */
    public static function fromTask(array $task): ?self
    {
        $raw = $task['errorCode'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        return self::tryFrom($raw) ?? self::UpstreamFailed;
    }

    /**
     * Whether resubmitting the identical request could plausibly succeed.
     *
     * `content_rejected` and `invalid_request` are decisions about this exact
     * request, so a retry only spends credits to reach the same answer.
     */
    public function isWorthRetrying(): bool
    {
        return match ($this) {
            self::InvalidRequest,
            self::UnsupportedCombination,
            self::ContentRejected,
            self::InvalidAsset => false,
            default => true,
        };
    }
}
