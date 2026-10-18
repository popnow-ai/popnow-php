<?php

declare(strict_types=1);

namespace PopNow;

/**
 * Any failure of a PopNow API call, carrying everything support needs.
 *
 * Catch this one type to catch every error this client raises for an API call:
 * transport failures, local deadlines and the storage upload leg all extend it.
 *
 * `$businessCode` is the envelope's **business** code, which is not the HTTP
 * status. The two differ exactly where it matters; see {@see self::guidance()}.
 * It is spelled out rather than called `code` because `\Exception` already owns
 * that name; `getCode()` mirrors it, and reads 0 when there was no envelope at
 * all, which `$businessCode` reports honestly as null.
 *
 * `$reason` is the machine-readable `data.reason` a few failures add (asset
 * library limits, a prompt declined before anything was charged). Treat an
 * unknown value like an absent one.
 */
class ApiError extends \RuntimeException
{
    /** HTTP statuses this client may retry on its own. */
    public const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?int $businessCode = null,
        public readonly string $requestId = '',
        public readonly ?float $retryAfterSeconds = null,
        public readonly ?string $reason = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $businessCode ?? 0, $previous);
    }

    /**
     * Whether an automatic retry of the same call is allowed by transport
     * rules alone.
     *
     * This answers "is the status retryable", not "is this request safe to
     * repeat". Task creation is only safe to repeat when it carried an
     * `Idempotency-Key`; without one, a retry can create and bill a second
     * task. {@see Client::createTask()} encodes that rule, so callers normally
     * never need this method; it exists for code that retries at a higher level.
     */
    public function isRetryable(): bool
    {
        return in_array($this->status, self::RETRYABLE_STATUSES, true);
    }

    /**
     * What to actually do about this error, per business code.
     *
     * openapi.yaml `ErrorEnvelope.code` and the `Unavailable` response. The
     * three 503 codes are the reason this method exists: `503`, `50301` and
     * `50302` share one HTTP status and call for three different responses, so
     * branching on the status alone is wrong.
     */
    public function guidance(): string
    {
        return match ($this->businessCode) {
            401 => 'The API key is missing, unknown or revoked. Create a key at '
                . 'https://popnow.ai/api/console/keys and export it as POPNOW_API_KEY.',
            40003 => 'The uploaded bytes do not match the ticket: size, media type or file signature '
                . 'differs from what the ticket declared. Request a fresh upload ticket and upload the '
                . 'file again. Retrying the commit alone will fail identically.',
            40004 => 'The request is valid but the model cannot serve this exact parameter combination. '
                . 'Change the parameter named in the message, then resubmit. Do not retry unchanged.',
            40201 => 'Not enough credits for this task\'s hold. Buy credits at https://popnow.ai/pricing, '
                . 'then resubmit.',
            40202 => 'A key spend cap was reached. Raise the cap in the API console or wait for it to reset.',
            40301 => 'This API key is not allowed to call this model. Add the model to the key\'s allowed list.',
            40302 => 'The caller IP is outside this API key\'s allow list. Retrying from the same '
                . 'address will keep failing.',
            40303 => 'The service is not offered in this region. Not retryable.',
            40310 => 'The account has not verified its email address yet, so it cannot run models. Open the '
                . 'verification link sent at sign-up, or send a new one from the console; the same key '
                . 'starts working as soon as the address is verified.',
            409 => 'Idempotency conflict. This key was already used for a different request, or it '
                . 'belongs to a sibling API key of the same account. Resend the original request under '
                . 'the original key (that returns the first task rather than creating a second), or '
                . 'submit the changed request under a new key.',
            40901 => 'The quote expired or the price changed, and nothing was reserved. Quote again and '
                . 'resend with the new quoteId and expected amount, keeping the original Idempotency-Key '
                . 'so an already accepted task is recovered instead of created twice.',
            50301 => 'The model currently has no usable deployment or effective price. Retrying the same '
                . 'model later may work; a different model is the faster fix.',
            50302 => 'A synchronous generation failed and the charge was refunded. Send the request again '
                . 'under a new Idempotency-Key: the original key replays the recorded failure, so a retry '
                . 'that keeps it can never succeed.',
            503 => 'A dependency is temporarily unavailable. Back off, honouring Retry-After when present.',
            429 => 'Rate limit exceeded. Back off, honouring Retry-After.',
            413 => 'The request body exceeds the accepted size. Upload large media instead of inlining it.',
            default => $this->isRetryable()
                ? 'Transient failure; retry with backoff.'
                : 'Not retryable as sent. Fix the request, then resubmit.',
        };
    }

    /** One line safe to log. It never contains the API key. */
    public function describe(): string
    {
        return sprintf(
            '%s (http=%d code=%s request_id=%s%s)',
            $this->getMessage(),
            $this->status,
            $this->businessCode === null ? '-' : (string) $this->businessCode,
            $this->requestId === '' ? '-' : $this->requestId,
            $this->reason === null ? '' : ' reason=' . $this->reason,
        );
    }
}
