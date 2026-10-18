<?php

declare(strict_types=1);

namespace PopNow;

/**
 * The network call never produced an HTTP response: DNS, TLS, connection reset.
 *
 * Distinct from an API error because the request may or may not have reached
 * the server. For a non-idempotent call, treat it as "unknown", not "failed".
 */
final class TransportError extends ApiError
{
}
