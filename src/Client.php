<?php

/**
 * PopNow client for PHP 8.1+.
 *
 * PopNow (https://popnow.ai) is a browser-based AI studio for videos, images
 * and music. Its developer API serves the same image, video and audio models
 * with the same credit prices. This client covers the asynchronous workflow end
 * to end: discover a model and its input schema, upload local reference
 * material, quote a request, submit it, wait for a terminal state, collect the
 * result, and keep files in the asset library.
 *
 * It depends on no library: ext-json, ext-hash and either ext-curl or the
 * `https://` stream wrapper, all of which ship with a default PHP build.
 *
 * Everything here follows the published OpenAPI contract at
 * https://popnow.ai/docs/openapi.yaml (pinned in `contracts/openapi.yaml`).
 * Field names, error codes and limits are quoted from it in the comments so a
 * reader can check them against the source instead of trusting this file.
 *
 * Usage (every optional argument is a named argument):
 *
 *   $client = new PopNow\Client();                    // reads POPNOW_API_KEY
 *   $task = $client->createTask(
 *       model: 'alibaba/z-image-turbo/text-to-image',
 *       input: ['prompt' => 'a paper-cut city at dusk'],
 *       idempotencyKey: PopNow\Client::idempotencyKey(),
 *   );
 *   $done = $client->waitForTerminal($task['taskId']);
 */

declare(strict_types=1);

namespace PopNow;

final class Client
{
    /**
     * The release of this package. Keep it equal to the newest CHANGELOG.md
     * heading and the git tag; `tests/unit.php` fails when the three disagree.
     */
    public const VERSION = '0.1.0';

    public const API_BASE_URL = 'https://api.popnow.ai/api/v1';

    /** The environment variable the key is read from when none is passed. */
    public const API_KEY_ENV = 'POPNOW_API_KEY';

    /** The product token of the `User-Agent`. */
    public const USER_AGENT_PRODUCT = 'popnow-php';

    /** Per-API-call ceiling. JSON requests have no reason to take longer. */
    public const REQUEST_TIMEOUT_SECONDS = 30.0;

    /**
     * The direct-to-storage PUT gets its own, much longer ceiling.
     *
     * 30 seconds is right for a JSON call and wrong for bytes: the audio/video
     * limit is 90 MiB, which 30 seconds would demand be pushed at ~25 Mbps.
     * Ten minutes covers 90 MiB at ~1.2 Mbps.
     */
    public const UPLOAD_TIMEOUT_SECONDS = 600.0;

    /**
     * LoRA weights can be 2 GiB, and their upload ticket stays valid for two
     * hours, so their PUT is allowed that long.
     */
    public const LORA_UPLOAD_TIMEOUT_SECONDS = 7200.0;

    /** Local polling deadline. A safety bound for examples, not a platform SLA. */
    public const WAIT_TIMEOUT_SECONDS = 600.0;

    public const WAIT_INTERVAL_SECONDS = 2.0;
    public const MAX_WAIT_INTERVAL_SECONDS = 10.0;

    /**
     * Below this much budget left, {@see self::waitForTerminal()} stops polling.
     *
     * A poll fired with a near-zero timeout is a poll that will time out, and
     * that timeout comes from the request layer, which does not know which task
     * it was polling for. Stopping here instead means the caller gets the wait
     * timeout, which carries the task ID: the one thing needed to reconcile a
     * task that is still running and still billable.
     */
    public const MIN_POLL_BUDGET_SECONDS = 1.0;

    /**
     * Ceiling on the encoded request body, from the `CreateTaskRequest.input`
     * description: the complete JSON request must fit within 2097152 bytes.
     * Checked locally so an oversized prompt fails with a clear message instead
     * of a 413.
     */
    public const MAX_REQUEST_BYTES = 2097152;

    /** Asset sources, from `AssetCreateRequest.source`. */
    public const ASSET_SOURCES = ['task_output', 'upload', 'url'];

    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly Transport $transport;

    /**
     * @param string|null $apiKey               The key, or null to read `$apiKeyEnvVar`. Prefer the
     *                                          environment: a key in an argument is a key in a stack
     *                                          trace (PHP 8.2+ redacts it via SensitiveParameter).
     * @param string|null $language             Sent as `Accept-Language`, which selects the language
     *                                          of `msg` and `errorMessage` (`en`, `de`, `ja`, ...).
     *                                          Codes never change with it.
     * @param int         $maxRetries           Automatic retries per call, on top of the first attempt.
     * @param float       $retryBaseDelaySeconds First backoff step; doubles per attempt with jitter.
     * @param float       $maxRetryDelaySeconds Backoff ceiling. A `Retry-After` longer than this is not
     *                                          waited out; the error is returned to the caller instead.
     */
    public function __construct(
        #[\SensitiveParameter] ?string $apiKey = null,
        string $baseUrl = self::API_BASE_URL,
        ?Transport $transport = null,
        private readonly ?string $language = null,
        private readonly float $requestTimeoutSeconds = self::REQUEST_TIMEOUT_SECONDS,
        private readonly float $uploadTimeoutSeconds = self::UPLOAD_TIMEOUT_SECONDS,
        private readonly int $maxRetries = 2,
        private readonly float $retryBaseDelaySeconds = 0.25,
        private readonly float $maxRetryDelaySeconds = 30.0,
        private readonly ?\Closure $sleeper = null,
        string $apiKeyEnvVar = self::API_KEY_ENV,
    ) {
        $key = $apiKey;
        if ($key === null) {
            // Under php-fpm, getenv() may be empty while the value is present in $_SERVER, so both
            // are checked.
            $fromEnv = getenv($apiKeyEnvVar);
            if (!is_string($fromEnv) || trim($fromEnv) === '') {
                $fromEnv = is_string($_SERVER[$apiKeyEnvVar] ?? null) ? $_SERVER[$apiKeyEnvVar] : '';
            }
            $key = $fromEnv;
        }
        $key = trim($key);
        if ($key === '') {
            throw new \InvalidArgumentException(sprintf(
                'No API key: pass apiKey or export %s (create one at https://popnow.ai/api/console/keys). '
                . 'Never hard-code it.',
                $apiKeyEnvVar,
            ));
        }
        // A line break would turn this value into a second header injected into the request.
        if (strpbrk($key, "\r\n") !== false) {
            throw new \InvalidArgumentException('the API key must not contain line breaks');
        }
        if ($language !== null && preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $language) !== 1) {
            throw new \InvalidArgumentException('language must be a language tag such as "en" or "pt-BR"');
        }
        $this->apiKey = $key;
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
        $this->transport = $transport ?? (extension_loaded('curl') ? new CurlTransport() : new StreamTransport());
    }

    /**
     * The API key travels on every call, so the base URL must be HTTPS.
     *
     * Loopback `http://` is the one exception, for a developer pointing this at
     * a local stub. Credentials, queries and fragments in a base URL are
     * rejected rather than silently dropped when paths are appended to it.
     */
    private static function normalizeBaseUrl(string $value): string
    {
        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('baseUrl must be an absolute URL');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('baseUrl must not contain credentials');
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('baseUrl must not contain a query or fragment');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $isLoopback = $host === 'localhost' || $host === '::1' || str_starts_with($host, '127.');
        if (strtolower($parts['scheme']) !== 'https' && !($isLoopback && strtolower($parts['scheme']) === 'http')) {
            throw new \InvalidArgumentException(
                'baseUrl must use HTTPS; plain HTTP is allowed only for loopback development',
            );
        }

        return rtrim($value, '/');
    }

    /* -- Catalog ------------------------------------------------------------ */

    /**
     * List every callable model with this account's credit prices.
     *
     * `GET /api/v1/models`. Returns `{total, items}`. The catalog is the only
     * contractual source of `model` values for `createTask`; do not hard-code
     * identifiers from a web page.
     *
     * @param string|null $modality image, video or audio.
     * @param string|null $task     Exact task, e.g. `text-to-image`. Image editing
     *                              accepts both `edit` and `image-to-image`.
     *
     * @return array<string,mixed>
     */
    public function listModels(
        ?string $modality = null,
        ?string $provider = null,
        ?string $task = null,
        ?string $search = null,
        ?bool $includeSchema = null,
        ?bool $includeExamples = null,
    ): array {
        $suffix = self::queryString([
            'modality' => $modality,
            'provider' => $provider,
            'task' => $task,
            'search' => $search,
            'includeSchema' => $includeSchema === null ? null : ($includeSchema ? '1' : '0'),
            'includeExamples' => $includeExamples === null ? null : ($includeExamples ? '1' : '0'),
        ]);

        return $this->requireArray($this->request('GET', '/models' . $suffix, retryable: true));
    }

    /**
     * Fetch one model, including its input JSON Schema.
     *
     * `GET /api/v1/models/{model}`. Model identifiers contain slashes, so the
     * value is URL-encoded into a single path segment.
     *
     * The returned `inputSchema` is what the request `input` is validated
     * against; render forms from it rather than from any static copy.
     *
     * @return array<string,mixed>
     */
    public function getModel(string $model): array
    {
        $encoded = rawurlencode(self::requireIdentifier($model, 'model'));

        return $this->requireArray($this->request('GET', '/models/' . $encoded, retryable: true));
    }

    /* -- Quotes and tasks --------------------------------------------------- */

    /**
     * Price a task without creating it.
     *
     * `POST /api/v1/jobs/quote`. Runs the same validation and admission checks
     * as creation, but reserves no credits and starts no generation. The
     * response is `{quoteId, model, maxCredits, expectedCredits, estimatedCost,
     * maxCharge, currency, quantity, unit, expiresAt}`. Credits are integers
     * (1 credit = $0.001); USD amounts are decimal **strings**, never floats; see
     * {@see self::compareUsd()}.
     *
     * The quote is signed and bound to this account, this API key and this exact
     * request, and is valid for five minutes. Pass `quoteId` and
     * `expectedCredits: $quote['maxCredits']` to {@see self::createTask()} to be
     * told (business code `40901`) when the price moved, instead of silently
     * paying the new one.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function quote(string $model, array $input, ?string $callBackUrl = null): array
    {
        $body = ['model' => self::requireIdentifier($model, 'model'), 'input' => self::jsonObject($input)];
        if ($callBackUrl !== null) {
            $body['callBackUrl'] = self::requireCallbackUrl($callBackUrl);
        }

        // A quote moves no credits and creates no task, so retrying is safe.
        return $this->requireArray($this->request('POST', '/jobs/quote', body: $body, retryable: true));
    }

    /**
     * Create an asynchronous generation task.
     *
     * `POST /api/v1/jobs/createTask`. HTTP 202 means accepted, not finished;
     * the response is `{taskId, state, estimatedCredits, estimatedCost,
     * deadlineAt}` with `state` = `queued`. `estimatedCredits` is the amount
     * **held**, and the final charge is capped at it.
     *
     * Always pass an `idempotencyKey`. Two things depend on it:
     *
     *  - the server deduplicates for 24 hours per API key, so a replay returns
     *    the original task rather than creating and billing a second one;
     *  - it is the only thing that makes this call safe for this client to
     *    retry automatically. Without a key, a retry after a timeout can create
     *    a second paid task, so the call is not retried and the error is handed
     *    straight back.
     *
     * Reusing a key with different request semantics returns business code `409`.
     * That is a different code from `40901`, which is only ever the price moving;
     * see {@see ApiError::guidance()}, where the two call for opposite fixes.
     *
     * @param array<string,mixed> $input            Validated against the model's `inputSchema`.
     *                                              Media fields take public HTTPS URLs, committed
     *                                              `popnow://f/fil_...` uploads or saved
     *                                              `popnow://a/ast_...` assets.
     * @param string|null         $callBackUrl      Public HTTPS endpoint for terminal delivery; verify
     *                                              what arrives with {@see Webhook}.
     * @param string|null         $quoteId          From {@see self::quote()}.
     * @param int|null            $expectedCredits  `maxCredits` from the same quote. Wins over
     *                                              `expectedCost` when both are sent.
     * @param string|null         $expectedCost     The same amount as a decimal USD string.
     * @param int|null            $retentionSeconds Shorten retention for this one task via
     *                                              `X-PopNow-Retention`. The effective value is
     *                                              `min(header, account, platform)` and is
     *                                              reported back in the task's `retention`.
     * @param int|null            $waitSeconds      Hold the connection open for up to this many
     *                                              seconds so the first poll is unnecessary.
     *                                              **It does not guarantee a finished task.**
     *
     * `$waitSeconds` changes the shape of what comes back, and that is the trap.
     * Reaching a terminal state inside the budget returns the full task record;
     * running out returns the ordinary accepted response. The two differ only in
     * `state`, so branch on {@see self::isTerminal()} and never on the fact that
     * the parameter was passed.
     *
     * @return array<string,mixed>
     */
    public function createTask(
        string $model,
        array $input,
        ?string $idempotencyKey = null,
        ?string $callBackUrl = null,
        ?string $quoteId = null,
        ?int $expectedCredits = null,
        ?string $expectedCost = null,
        ?int $retentionSeconds = null,
        ?int $waitSeconds = null,
    ): array {
        $body = ['model' => self::requireIdentifier($model, 'model'), 'input' => self::jsonObject($input)];
        if ($callBackUrl !== null) {
            $body['callBackUrl'] = self::requireCallbackUrl($callBackUrl);
        }
        if ($quoteId !== null) {
            $body['quoteId'] = self::requireIdentifier($quoteId, 'quoteId');
        }
        if ($expectedCredits !== null) {
            if ($expectedCredits < 0) {
                throw new \InvalidArgumentException('expectedCredits must not be negative');
            }
            $body['expectedCredits'] = $expectedCredits;
        }
        if ($expectedCost !== null) {
            if (preg_match('/^[0-9]+(\.[0-9]{1,9})?$/', $expectedCost) !== 1) {
                throw new \InvalidArgumentException('expectedCost must be a decimal USD string such as "0.34"');
            }
            $body['expectedCost'] = $expectedCost;
        }

        $headers = [];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = self::requireIdempotencyKey($idempotencyKey);
        }
        if ($retentionSeconds !== null) {
            if ($retentionSeconds < 0) {
                throw new \InvalidArgumentException('retentionSeconds must not be negative');
            }
            // No local ceiling: only the server knows the platform limit. Anything over it is
            // clamped server-side, and the effective value is read back from the record's retention.
            $headers['X-PopNow-Retention'] = (string) $retentionSeconds;
        }

        $path = '/jobs/createTask';
        $timeoutSeconds = null;
        if ($waitSeconds !== null) {
            if ($waitSeconds < 0) {
                throw new \InvalidArgumentException('waitSeconds must not be negative');
            }
            // Sent verbatim. The server clamps anything over 60 down to 60 and ignores
            // non-positive values rather than rejecting them; copying that ceiling here would bury
            // a constant that will expire.
            $path .= '?' . http_build_query(['wait' => $waitSeconds]);
            // The local request timeout has to accommodate the server-side wait, or the default
            // 30-second timeout cuts the call off while the server is still waiting, which looks
            // like "wait does nothing". Ten seconds of headroom covers creation and the round trip.
            $timeoutSeconds = max($this->requestTimeoutSeconds, (float) $waitSeconds + 10.0);
        }

        return $this->requireArray($this->request(
            'POST',
            $path,
            body: $body,
            headers: $headers,
            // Only the idempotency key decides this. Neither the retention header nor wait makes a
            // resend safe; with a key present, resending after a wait timeout returns the same task.
            retryable: $idempotencyKey !== null,
            timeoutSeconds: $timeoutSeconds,
        ));
    }

    /**
     * Read one task.
     *
     * `GET /api/v1/jobs/recordInfo?taskId=...`. Terminal and non-terminal states
     * share one shape. A task belonging to another account or another API key is
     * reported as 404, indistinguishable from one that never existed.
     *
     * @param float|null $timeoutSeconds Ceiling for this one call including its
     *                                   automatic retries.
     *
     * @return array<string,mixed>
     */
    public function getTask(string $taskId, ?float $timeoutSeconds = null): array
    {
        $query = http_build_query(['taskId' => self::requireIdentifier($taskId, 'taskId')]);

        return $this->requireArray($this->request(
            'GET',
            '/jobs/recordInfo?' . $query,
            retryable: true,
            timeoutSeconds: $timeoutSeconds,
        ));
    }

    /**
     * Poll until the task is terminal, or the local deadline elapses.
     *
     * Backs off from two seconds by 1.5x with jitter, capped at ten seconds.
     *
     *  - a `succeeded` task whose assets are still `pending` keeps being polled;
     *    returning it would hand the caller a result with no usable URL.
     *  - the timeout throws {@see TimeoutError}, which does **not** mean the
     *    generation failed. The task is still running and still billable.
     *    Persist the task ID and reconcile later, or use a webhook.
     *  - one poll timing out is not this wait failing: while budget remains,
     *    polling continues, and the exception thrown when the budget really runs
     *    out always carries the task ID.
     *
     * For anything longer than a request-response cycle, prefer `callBackUrl`
     * plus {@see Webhook}: a PHP-FPM worker blocked on a video for four minutes
     * is a worker that is not serving traffic.
     *
     * @param callable(array<string,mixed>):void|null $onUpdate Called with each poll result.
     *
     * @return array<string,mixed>
     */
    public function waitForTerminal(
        string $taskId,
        ?float $timeoutSeconds = null,
        ?callable $onUpdate = null,
    ): array {
        $id = self::requireIdentifier($taskId, 'taskId');
        $budget = $timeoutSeconds ?? self::WAIT_TIMEOUT_SECONDS;
        $deadline = $this->now() + $budget;
        $interval = self::WAIT_INTERVAL_SECONDS;

        while ($this->now() < $deadline) {
            $remaining = $deadline - $this->now();
            // When the remaining budget cannot fund a meaningful request, break out and throw the
            // timeout below, the one that carries the taskId.
            if ($remaining < self::MIN_POLL_BUDGET_SECONDS) {
                break;
            }

            $task = null;
            try {
                // Each attempt's timeout is clamped to the remaining budget, or a 30-second request
                // timeout plus retries could make a 5-second wait block for over ninety seconds.
                $task = $this->getTask($id, min($this->requestTimeoutSeconds, $remaining));
            } catch (TimeoutError) {
                // One polling attempt timing out is not this wait failing: keep polling while
                // budget remains. The request layer's timeout does not know the taskId; the one
                // thrown below does.
            }

            if ($task !== null) {
                if ($onUpdate !== null) {
                    $onUpdate($task);
                }

                $raw = is_string($task['state'] ?? null) ? $task['state'] : '';
                $state = TaskState::tryFrom($raw);
                if ($state === null) {
                    // An unknown state is not treated as terminal: calling it finished would
                    // silently discard a task that is still running.
                    throw new ApiError(sprintf('unknown task state "%s"', $raw), status: 200, businessCode: 200);
                }
                if ($state->isTerminal() && !self::hasPendingAssets($task)) {
                    return $task;
                }
            }

            $delay = min($this->jitter($interval), max(0.0, $deadline - $this->now()));
            if ($delay > 0) {
                $this->sleep($delay);
            }
            $interval = min($interval * 1.5, self::MAX_WAIT_INTERVAL_SECONDS);
        }

        throw new TimeoutError(
            sprintf(
                'task %s did not reach a terminal state within the local %.0fs deadline; '
                . 'its remote state is unknown and it may still be running',
                $id,
                $budget,
            ),
            taskId: $id,
        );
    }

    /**
     * Create a new task from a failed or expired one.
     *
     * `POST /api/v1/jobs/retry`. The source task is never modified; schema,
     * price, permissions, balance and availability are all evaluated again, so
     * this can cost a different amount than the original did, and it is a new
     * charge, not a free redo.
     *
     * The optional `Idempotency-Key` is scoped to the source task and the retry
     * action and, as in {@see self::createTask()}, is what makes automatic retry
     * of this call safe.
     *
     * @return array<string,mixed> `CreateTaskResponse` plus `sourceTaskId`.
     */
    public function retryTask(string $taskId, ?string $idempotencyKey = null): array
    {
        $headers = [];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = self::requireIdempotencyKey($idempotencyKey);
        }

        return $this->requireArray($this->request(
            'POST',
            '/jobs/retry',
            body: ['taskId' => self::requireIdentifier($taskId, 'taskId')],
            headers: $headers,
            retryable: $idempotencyKey !== null,
        ));
    }

    /**
     * Destroy a finished task's stored content.
     *
     * `POST /api/v1/jobs/purge`. Removes the generated media, the result payload
     * and the stored request text including the prompt.
     *
     * **The billing record is not touched.** The ledger, the charged amount, the
     * model, the state, the timestamps and the request_id all survive, and the
     * response repeats that as `billingRetained: true`.
     *
     *  - this endpoint does **not** read `Idempotency-Key`. The `taskId` is the
     *    idempotency key, so a repeat returns 200 with the original `purgedAt`
     *    and a retry after a timeout is always safe.
     *  - a queued or running task returns 400: wait for a terminal state first.
     *
     * @return array<string,mixed>
     */
    public function purgeTask(string $taskId): array
    {
        return $this->requireArray($this->request(
            'POST',
            '/jobs/purge',
            body: ['taskId' => self::requireIdentifier($taskId, 'taskId')],
            retryable: true,
        ));
    }

    /* -- Account and history ------------------------------------------------ */

    /**
     * This API key's balance, held credits and funding sources.
     *
     * `GET /api/v1/chat/credit` (the path is historical; it is the balance).
     * Returns `{availableCredits, heldCredits, totalCredits}` as integers and
     * `{available, held, total}` as decimal USD **strings**, plus an optional
     * `funding` overview.
     *
     * Held credits are reserved by tasks that have not settled yet. They are not
     * spent and not available, which is why a balance that looks unchanged after
     * a submission is not evidence that nothing was reserved.
     *
     * @return array<string,mixed>
     */
    public function getBalance(): array
    {
        return $this->requireArray($this->request('GET', '/chat/credit', retryable: true));
    }

    /**
     * Settled spend and call counts for this API key over `[from, to)`.
     *
     * `GET /api/v1/usage?from&to`. Dates are UTC calendar days forming a
     * half-open interval of at most 92 days; `to` defaults to tomorrow in UTC
     * and an omitted `from` to seven days before `to`.
     *
     * **For reconciliation, not for progress.** This endpoint has an
     * account-wide budget of 30 requests per minute shared by every key, and
     * that limiter fails closed. Follow a task with {@see self::getTask()}.
     *
     * @param string|null $from Inclusive UTC date, `YYYY-MM-DD`.
     * @param string|null $to   Exclusive UTC date, `YYYY-MM-DD`.
     *
     * @return array<string,mixed>
     */
    public function getUsage(?string $from = null, ?string $to = null): array
    {
        $suffix = self::queryString([
            'from' => self::requireDate($from, 'from'),
            'to' => self::requireDate($to, 'to'),
        ]);

        return $this->requireArray($this->request('GET', '/usage' . $suffix, retryable: true));
    }

    /**
     * One page of this API key's task history, newest first.
     *
     * `GET /api/v1/jobs`. Returns `{items, hasMore, nextCursor}`; `nextCursor`
     * is present only while `hasMore` is true. Metadata only: no input, no
     * output, no signed media URL. Use {@see self::getTask()} for a result.
     *
     * **Keep every filter identical while paginating and change only the
     * cursor.** {@see self::iterTasks()} does this for you.
     *
     * @param string|null $state  queued, running, succeeded, failed, canceled, expired.
     * @param int|null    $limit  Page size. Only a non-positive value is refused here;
     *                            the ceiling belongs to the server.
     * @param string|null $cursor The previous page's `nextCursor`, passed through unchanged.
     *
     * @return array<string,mixed>
     */
    public function listTasks(
        ?string $from = null,
        ?string $to = null,
        ?string $state = null,
        ?string $model = null,
        ?int $limit = null,
        ?string $cursor = null,
    ): array {
        if ($limit !== null && $limit < 1) {
            throw new \InvalidArgumentException('limit must be a positive integer');
        }
        $suffix = self::queryString([
            'from' => self::requireDate($from, 'from'),
            'to' => self::requireDate($to, 'to'),
            'state' => $state,
            'model' => $model,
            'limit' => $limit,
            'cursor' => $cursor,
        ]);

        return $this->requireArray($this->request('GET', '/jobs' . $suffix, retryable: true));
    }

    /**
     * Walk every page of {@see self::listTasks()}, yielding one task at a time.
     *
     * The walk stops when `hasMore` is false **or** `nextCursor` is missing or
     * empty. Trusting `hasMore` alone means a server sending `hasMore: true`
     * with no usable cursor turns into an endless loop of API calls.
     *
     * @return \Generator<int, array<string,mixed>>
     */
    public function iterTasks(
        ?string $from = null,
        ?string $to = null,
        ?string $state = null,
        ?string $model = null,
        ?int $limit = null,
    ): \Generator {
        $cursor = null;
        while (true) {
            $page = $this->listTasks(
                from: $from,
                to: $to,
                state: $state,
                model: $model,
                limit: $limit,
                cursor: $cursor,
            );

            foreach ($page['items'] ?? [] as $task) {
                if (is_array($task)) {
                    yield $task;
                }
            }

            $next = $page['nextCursor'] ?? null;
            if (($page['hasMore'] ?? false) !== true || !is_string($next) || $next === '') {
                return;
            }
            $cursor = $next;
        }
    }

    /* -- The three upload steps --------------------------------------------- */

    /**
     * Step 1 of 3: ask for a presigned upload ticket.
     *
     * `POST /api/v1/common/upload-url`, body `{contentType, bytes}`. The server
     * picks the object key. The response is `{fileId, key, uploadUrl, method,
     * headers, expiresAt, maxBytes}`.
     *
     * `bytes` must be the exact length that will be PUT. The commit step
     * compares it, and a mismatch is business code `40003`.
     *
     * Not retried automatically: each call issues a new ticket, so a blind retry
     * quietly leaves an abandoned one behind.
     *
     * @return array<string,mixed>
     */
    public function createUploadUrl(UploadContentType $contentType, int $bytes): array
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException('bytes must be at least 1');
        }
        if ($bytes > $contentType->maxBytes()) {
            throw new UploadError(sprintf(
                '%s uploads are limited to %d bytes; this file is %d',
                $contentType->value,
                $contentType->maxBytes(),
                $bytes,
            ), status: 413);
        }

        return $this->requireArray($this->request(
            'POST',
            '/common/upload-url',
            body: ['contentType' => $contentType->value, 'bytes' => $bytes],
        ));
    }

    /**
     * Step 3 of 3: commit the uploaded object.
     *
     * `POST /api/v1/files/{fileId}/commit`, no request body. The server compares
     * the stored byte count and media type against the ticket, checks the file
     * signature, hashes it, and copies it to an immutable private key.
     *
     * The returned `uri` (`popnow://f/fil_...`) is the value to put in a model
     * input field. Business code `40003` here means the bytes did not match the
     * ticket; request a new ticket and upload again rather than retrying the
     * commit. Repeating a successful commit is idempotent, so this call is
     * retryable.
     *
     * @return array<string,mixed>
     */
    public function commitUploadedFile(string $fileId): array
    {
        $id = rawurlencode(self::requireIdentifier($fileId, 'fileId'));

        return $this->requireArray($this->request('POST', '/files/' . $id . '/commit', retryable: true));
    }

    /**
     * All three upload steps for a local file, streaming the bytes.
     *
     * With {@see CurlTransport} the file is never loaded into memory, so a
     * 90 MiB video (or a 2 GiB `.safetensors` file) works under a default
     * `memory_limit`.
     *
     * @return array<string,mixed> The committed file; `['uri']` goes into model input.
     */
    public function uploadFile(string $path, ?UploadContentType $contentType = null): array
    {
        $resolved = $contentType ?? UploadContentType::forPath($path);
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size < 1) {
            throw new UploadError(sprintf('"%s" is not a readable, non-empty file', $path));
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new UploadError(sprintf('could not open "%s" for reading', $path));
        }

        try {
            $ticket = $this->createUploadUrl($resolved, $size);
            $this->putToStorage($ticket, $handle, $size, $resolved);
        } finally {
            fclose($handle);
        }

        return $this->commitUploadedFile((string) $ticket['fileId']);
    }

    /**
     * All three upload steps for bytes already in memory.
     *
     * @return array<string,mixed>
     */
    public function uploadBytes(string $data, UploadContentType $contentType): array
    {
        $length = strlen($data);
        $ticket = $this->createUploadUrl($contentType, $length);
        $this->putToStorage($ticket, $data, $length, $contentType);

        return $this->commitUploadedFile((string) $ticket['fileId']);
    }

    /**
     * Import a `.safetensors` LoRA file from a link, without uploading it.
     *
     * `POST /api/v1/files/import`, body `{url, token?}`. `url` accepts a
     * Hugging Face repo id (`owner/repo`, when the repo holds exactly one
     * `.safetensors` file), a Hugging Face repo or file page, or any direct
     * HTTPS link. `token` is optional, a Hugging Face access token or a Civitai
     * API key, used only for this download and never stored.
     *
     * The response matches {@see self::commitUploadedFile()}: put its `uri` in
     * `loras[].path`. Not retried automatically, because each call downloads and
     * stores the file again.
     *
     * @return array<string,mixed>
     */
    public function importLoraFile(string $url, #[\SensitiveParameter] ?string $token = null): array
    {
        $source = self::requireIdentifier($url, 'url');
        if (strlen($source) > 2048) {
            throw new \InvalidArgumentException('url must be at most 2048 characters');
        }
        $body = ['url' => $source];
        if ($token !== null) {
            $secret = self::requireIdentifier($token, 'token');
            if (strlen($secret) > 512) {
                throw new \InvalidArgumentException('token must be at most 512 characters');
            }
            $body['token'] = $secret;
        }

        return $this->requireArray($this->request('POST', '/files/import', body: $body));
    }

    /**
     * Step 2 of 3: PUT the bytes straight to object storage.
     *
     *  1. **Forward the ticket's `headers` one for one, unchanged.** They are
     *     part of what the URL's signature covers.
     *  2. **Do not send the PopNow `Authorization` header.** The URL is already
     *     signed; the storage host is a different origin, and attaching the key
     *     hands it out for no benefit. This is why the PUT goes through the raw
     *     transport instead of {@see self::request()}.
     *  3. **No automatic retry.** The body stream has been consumed by the time
     *     a failure is visible; retry the whole three-step flow instead.
     *
     * @param array<string,mixed> $ticket
     * @param string|resource     $body
     */
    private function putToStorage(array $ticket, $body, int $length, UploadContentType $contentType): void
    {
        $maxBytes = is_int($ticket['maxBytes'] ?? null) ? $ticket['maxBytes'] : 0;
        if ($maxBytes > 0 && $length > $maxBytes) {
            throw new UploadError(
                sprintf('file is %d bytes; the ticket allows %d', $length, $maxBytes),
                status: 413,
            );
        }

        $uploadUrl = is_string($ticket['uploadUrl'] ?? null) ? $ticket['uploadUrl'] : '';
        if ($uploadUrl === '') {
            throw new UploadError('the upload ticket did not contain an uploadUrl');
        }

        $headers = [];
        foreach ((array) ($ticket['headers'] ?? []) as $name => $value) {
            $headers[(string) $name] = (string) $value;
        }
        // The signed headers come first and win; a User-Agent is added only when the ticket does
        // not set one, because an edge in front of storage may refuse requests that carry none.
        if (!array_key_exists('user-agent', array_change_key_case($headers, CASE_LOWER))) {
            $headers['User-Agent'] = self::userAgent();
        }
        $method = strtoupper(is_string($ticket['method'] ?? null) ? $ticket['method'] : 'PUT');
        $timeout = $contentType->isLoraWeights()
            ? max($this->uploadTimeoutSeconds, self::LORA_UPLOAD_TIMEOUT_SECONDS)
            : $this->uploadTimeoutSeconds;

        $response = $this->transport->send($method, $uploadUrl, $headers, $body, $timeout);
        if ($response->status < 200 || $response->status >= 300) {
            // The storage side's body is its own XML and is not part of the API error contract,
            // so it is not passed on.
            throw new UploadError(
                sprintf('the presigned upload failed with HTTP %d', $response->status),
                status: $response->status,
            );
        }
    }

    /**
     * Mint a fresh download URL for a finished task's output.
     *
     * `POST /api/v1/common/download-url`, body `{taskId, key?}`. Leave `key`
     * empty to select the first output.
     *
     * Usually unnecessary: a ready asset in `output.assets` already carries a
     * usable `url`, and polling the task again refreshes an expired one. The URL
     * is a bearer grant: fetch it with no Authorization header, and do not log it.
     *
     * @return array<string,mixed> `{key, url, expiresAt}`
     */
    public function createDownloadUrl(string $taskId, ?string $key = null): array
    {
        $body = ['taskId' => self::requireIdentifier($taskId, 'taskId')];
        if ($key !== null) {
            $body['key'] = $key;
        }

        return $this->requireArray($this->request('POST', '/common/download-url', body: $body, retryable: true));
    }

    /* -- Asset library ------------------------------------------------------- */

    /**
     * Save a file to the asset library and get a stable `popnow://a/ast_...` URI.
     *
     * `POST /api/v1/assets`. Choose one `source`:
     *
     *  - `task_output` with `taskId` and the output's `key` or zero-based `index`;
     *  - `upload` with the `fileId` of a committed upload;
     *  - `url` with a public `http`/`https` address.
     *
     * `Idempotency-Key` is **required** by the contract, and saving the same
     * source again returns the existing asset, so this call is safe to retry.
     * The `uri` works only in generation requests made with keys of the same
     * workspace; it is never a download link. Library limits arrive as an
     * {@see ApiError} with `$reason` set (`asset_library_locked`,
     * `asset_library_full`, `asset_file_too_large`, `asset_daily_limit`, ...).
     *
     * @return array<string,mixed> The asset: `id`, `uri`, `name`, `kind`, `previewUrl`, ...
     */
    public function saveAsset(
        string $source,
        string $idempotencyKey,
        ?string $taskId = null,
        ?string $key = null,
        ?int $index = null,
        ?string $fileId = null,
        ?string $url = null,
        ?string $name = null,
    ): array {
        if (!in_array($source, self::ASSET_SOURCES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'source must be one of %s, not "%s"',
                implode(', ', self::ASSET_SOURCES),
                $source,
            ));
        }
        $body = ['source' => $source];
        switch ($source) {
            case 'task_output':
                $body['taskId'] = self::requireIdentifier((string) $taskId, 'taskId');
                if (($key === null) === ($index === null)) {
                    throw new \InvalidArgumentException('source task_output needs exactly one of key or index');
                }
                if ($index !== null) {
                    if ($index < 0) {
                        throw new \InvalidArgumentException('index must not be negative');
                    }
                    $body['index'] = $index;
                } else {
                    $body['key'] = self::requireIdentifier((string) $key, 'key');
                }
                break;
            case 'upload':
                $body['fileId'] = self::requireIdentifier((string) $fileId, 'fileId');
                break;
            case 'url':
                $address = self::requireIdentifier((string) $url, 'url');
                if (preg_match('#^https?://#i', $address) !== 1) {
                    throw new \InvalidArgumentException('url must be a public http or https address');
                }
                if (strlen($address) > 2048) {
                    throw new \InvalidArgumentException('url must be at most 2048 characters');
                }
                $body['url'] = $address;
                break;
        }
        if ($name !== null) {
            if (self::utf8Length($name) > 80) {
                throw new \InvalidArgumentException('name must be at most 80 characters');
            }
            $body['name'] = $name;
        }

        return $this->requireArray($this->request(
            'POST',
            '/assets',
            body: $body,
            headers: ['Idempotency-Key' => self::requireIdempotencyKey($idempotencyKey)],
            retryable: true,
        ));
    }

    /**
     * One page of saved assets.
     *
     * `GET /api/v1/assets`. Returns `{items, nextCursor?}`; `nextCursor` is
     * absent on the last page and only works with the `sort` it came from.
     *
     * @param string|null       $kind   image, video or audio.
     * @param list<string>|null $source Any of task_output, upload, url.
     * @param string|null       $q      Case-insensitive substring of the name.
     * @param string|null       $sort   recent (default), oldest, name or size.
     *
     * @return array<string,mixed>
     */
    public function listAssets(
        ?string $kind = null,
        ?array $source = null,
        ?string $q = null,
        ?bool $favorite = null,
        ?string $collectionId = null,
        ?string $entityId = null,
        ?string $sort = null,
        ?int $limit = null,
        ?string $cursor = null,
    ): array {
        if ($limit !== null && $limit < 1) {
            throw new \InvalidArgumentException('limit must be a positive integer');
        }
        if ($source !== null) {
            foreach ($source as $item) {
                if (!in_array($item, self::ASSET_SOURCES, true)) {
                    throw new \InvalidArgumentException(sprintf('unknown asset source "%s"', (string) $item));
                }
            }
        }
        $suffix = self::queryString([
            'kind' => $kind,
            'source' => $source === null ? null : implode(',', $source),
            'q' => $q,
            'favorite' => $favorite === null ? null : ($favorite ? 'true' : 'false'),
            'collectionId' => $collectionId,
            'entityId' => $entityId,
            'sort' => $sort,
            'limit' => $limit,
            'cursor' => $cursor,
        ]);

        return $this->requireArray($this->request('GET', '/assets' . $suffix, retryable: true));
    }

    /**
     * Walk every page of {@see self::listAssets()}, yielding one asset at a time.
     *
     * @param list<string>|null $source
     *
     * @return \Generator<int, array<string,mixed>>
     */
    public function iterAssets(
        ?string $kind = null,
        ?array $source = null,
        ?string $q = null,
        ?bool $favorite = null,
        ?string $collectionId = null,
        ?string $entityId = null,
        ?string $sort = null,
        ?int $limit = null,
    ): \Generator {
        $cursor = null;
        while (true) {
            $page = $this->listAssets(
                kind: $kind,
                source: $source,
                q: $q,
                favorite: $favorite,
                collectionId: $collectionId,
                entityId: $entityId,
                sort: $sort,
                limit: $limit,
                cursor: $cursor,
            );
            foreach ($page['items'] ?? [] as $asset) {
                if (is_array($asset)) {
                    yield $asset;
                }
            }
            $next = $page['nextCursor'] ?? null;
            if (!is_string($next) || $next === '' || $next === $cursor) {
                return;
            }
            $cursor = $next;
        }
    }

    /**
     * One asset with a fresh private `previewUrl` (valid for at most 20 minutes).
     *
     * `GET /api/v1/assets/{assetId}`. Persist `uri`, never the preview.
     *
     * @return array<string,mixed>
     */
    public function getAsset(string $assetId): array
    {
        $id = rawurlencode(self::requireIdentifier($assetId, 'assetId'));

        return $this->requireArray($this->request('GET', '/assets/' . $id, retryable: true));
    }

    /**
     * Rename an asset and/or mark it as a favorite.
     *
     * `PATCH /api/v1/assets/{assetId}`. Only what you send changes; the `uri`
     * stays the same. Repeating the same request is safe.
     *
     * @return array<string,mixed>
     */
    public function updateAsset(string $assetId, ?string $name = null, ?bool $favorite = null): array
    {
        $id = rawurlencode(self::requireIdentifier($assetId, 'assetId'));
        $body = [];
        if ($name !== null) {
            $length = self::utf8Length($name);
            if ($length < 1 || $length > 80) {
                throw new \InvalidArgumentException('name must be 1 to 80 characters');
            }
            $body['name'] = $name;
        }
        if ($favorite !== null) {
            $body['favorite'] = $favorite;
        }
        if ($body === []) {
            throw new \InvalidArgumentException('pass name, favorite or both');
        }

        return $this->requireArray($this->request('PATCH', '/assets/' . $id, body: $body, retryable: true));
    }

    /**
     * Delete an asset. Its `uri` stops working in new requests.
     *
     * `DELETE /api/v1/assets/{assetId}`. Deleting an unknown or already deleted
     * asset returns the same result, so this call is safe to retry.
     *
     * @return array<string,mixed> `{id, deleted: true}`
     */
    public function deleteAsset(string $assetId): array
    {
        $id = rawurlencode(self::requireIdentifier($assetId, 'assetId'));

        return $this->requireArray($this->request('DELETE', '/assets/' . $id, retryable: true));
    }

    /**
     * The storage plan, what is used, its limits and what the next plan unlocks.
     *
     * `GET /api/v1/assets/usage`.
     *
     * @return array<string,mixed>
     */
    public function getAssetUsage(): array
    {
        return $this->requireArray($this->request('GET', '/assets/usage', retryable: true));
    }

    /**
     * One page of characters: named, ordered sets of library images.
     *
     * `GET /api/v1/assets/entities`. Returns `{items, nextCursor?}`. Characters
     * are created in the website; the API only reads them.
     *
     * @param string|null $assetId Only characters that contain this asset.
     *
     * @return array<string,mixed>
     */
    public function listCharacters(?string $assetId = null, ?int $limit = null, ?string $cursor = null): array
    {
        if ($limit !== null && $limit < 1) {
            throw new \InvalidArgumentException('limit must be a positive integer');
        }
        $suffix = self::queryString(['assetId' => $assetId, 'cursor' => $cursor, 'limit' => $limit]);

        return $this->requireArray($this->request('GET', '/assets/entities' . $suffix, retryable: true));
    }

    /**
     * One character with its images in order.
     *
     * `GET /api/v1/assets/entities/{entityId}`.
     *
     * @return array<string,mixed>
     */
    public function getCharacter(string $entityId): array
    {
        $id = rawurlencode(self::requireIdentifier($entityId, 'entityId'));

        return $this->requireArray($this->request('GET', '/assets/entities/' . $id, retryable: true));
    }

    /* -- Reading outputs ----------------------------------------------------- */

    /**
     * Whether this task has reached a state it will never leave.
     *
     * The judgement to branch on after {@see self::createTask()} with
     * `waitSeconds`. An unknown state reads as false: treating it as terminal
     * would drop a task that is still running and still being billed.
     *
     * @param array<string,mixed> $task
     */
    public static function isTerminal(array $task): bool
    {
        $state = $task['state'] ?? null;

        return is_string($state) && (TaskState::tryFrom($state)?->isTerminal() ?? false);
    }

    /**
     * The text answer of a task, when it has one.
     *
     * **Not every successful task produces a file.** A transcription answers in
     * `output.text` and may carry no `assets` key at all, so check both. Null
     * means the field was absent; an empty string means it was there and empty.
     *
     * @param array<string,mixed> $task
     */
    public static function outputText(array $task): ?string
    {
        $output = $task['output'] ?? null;
        if (!is_array($output)) {
            return null;
        }
        $text = $output['text'] ?? null;

        return is_string($text) ? $text : null;
    }

    /**
     * The asset list of a task, always an array, empty when there is none.
     *
     * Assets may be `pending` (still being stored) or `unavailable` (gone), and
     * both of those lack a `url`. {@see self::readyAssets()} filters them out.
     *
     * @param array<string,mixed> $task
     *
     * @return list<array<string,mixed>>
     */
    public static function outputAssets(array $task): array
    {
        $output = $task['output'] ?? null;
        if (!is_array($output) || !is_array($output['assets'] ?? null)) {
            return [];
        }

        return array_values(array_filter($output['assets'], 'is_array'));
    }

    /**
     * Assets that have a usable URL right now.
     *
     * The URLs are signed GETs, normally valid for about 20 minutes. They are
     * bearer grants: fetch them without the API key, and do not put them in
     * logs or emails. To keep a file, save it with {@see self::saveAsset()}.
     *
     * @param array<string,mixed> $task
     *
     * @return list<array<string,mixed>>
     */
    public static function readyAssets(array $task): array
    {
        return array_values(array_filter(
            self::outputAssets($task),
            static fn (array $asset): bool => is_string($asset['url'] ?? null)
                && ($asset['pending'] ?? false) !== true
                && ($asset['unavailable'] ?? false) !== true,
        ));
    }

    /** True when the task succeeded but at least one asset has not landed yet. */
    private static function hasPendingAssets(array $task): bool
    {
        if (($task['state'] ?? null) !== TaskState::Succeeded->value) {
            return false;
        }
        foreach (self::outputAssets($task) as $asset) {
            if (($asset['pending'] ?? false) === true && ($asset['unavailable'] ?? false) !== true) {
                return true;
            }
        }

        return false;
    }

    /* -- Helpers -------------------------------------------------------------- */

    /**
     * A random idempotency key.
     *
     * Random is right for "submit this once" and wrong for "submit this order
     * once": if the key is regenerated on retry it deduplicates nothing. Derive
     * it from something stable in your own domain (an order ID, a row ID)
     * whenever one exists.
     */
    public static function idempotencyKey(string $prefix = 'popnow'): string
    {
        return $prefix . '-' . bin2hex(random_bytes(16));
    }

    /**
     * Compare two USD amounts from the API.
     *
     * Every USD amount in this API is a decimal string with up to nine decimal
     * places, and `(float) '0.000000001'` is already lossy. Uses ext-bcmath when
     * present and falls back to an exact string comparison.
     *
     * @return int -1, 0 or 1, like the spaceship operator.
     */
    public static function compareUsd(string $left, string $right): int
    {
        if (function_exists('bccomp')) {
            return bccomp($left, $right, 12);
        }

        $normalize = static function (string $value): array {
            $negative = str_starts_with($value, '-');
            $digits = ltrim($value, '+-');
            [$whole, $fraction] = array_pad(explode('.', $digits, 2), 2, '');

            return [$negative, ltrim($whole, '0'), rtrim($fraction, '0')];
        };

        [$leftNegative, $leftWhole, $leftFraction] = $normalize($left);
        [$rightNegative, $rightWhole, $rightFraction] = $normalize($right);
        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        $sign = $leftNegative ? -1 : 1;
        if (strlen($leftWhole) !== strlen($rightWhole)) {
            return strlen($leftWhole) < strlen($rightWhole) ? -$sign : $sign;
        }
        if ($leftWhole !== $rightWhole) {
            return ($leftWhole < $rightWhole ? -1 : 1) * $sign;
        }

        $width = max(strlen($leftFraction), strlen($rightFraction));
        $leftFraction = str_pad($leftFraction, $width, '0');
        $rightFraction = str_pad($rightFraction, $width, '0');
        if ($leftFraction === $rightFraction) {
            return 0;
        }

        return ($leftFraction < $rightFraction ? -1 : 1) * $sign;
    }

    /**
     * The `User-Agent` this client sends: `popnow-php/<version> (php/<version>)`.
     *
     * Sent explicitly on every call. The API's edge refuses requests that carry
     * a default library User-Agent or none at all.
     */
    public static function userAgent(): string
    {
        return sprintf('%s/%s (php/%s; +https://popnow.ai)', self::USER_AGENT_PRODUCT, self::VERSION, PHP_VERSION);
    }

    /* -- Request core ---------------------------------------------------------- */

    /**
     * One authenticated API call, with retries and envelope unwrapping.
     *
     * Every JSON response uses `{code, msg, data, request_id}`. A successful
     * HTTP response has `code: 200`; asynchronous success or failure is carried
     * by `data.state`, not by the envelope. So both the status **and** the code
     * are checked, and only `data` is handed back.
     *
     * @param array<string,mixed>|null $body
     * @param array<string,string>     $headers
     * @param bool                     $retryable      Whether repeating this exact call is safe.
     *                                                 Never true for a call that can spend twice.
     * @param float|null               $timeoutSeconds Ceiling for the whole call, retries included.
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        array $headers = [],
        bool $retryable = false,
        ?float $timeoutSeconds = null,
    ): mixed {
        $requestHeaders = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
            'User-Agent' => self::userAgent(),
        ];
        if ($this->language !== null) {
            $requestHeaders['Accept-Language'] = $this->language;
        }
        if ($body !== null) {
            $requestHeaders['Content-Type'] = 'application/json';
        }
        foreach ($headers as $name => $value) {
            $requestHeaders[$name] = $value;
        }

        $encoded = null;
        if ($body !== null) {
            // JSON_THROW_ON_ERROR is not optional: without it, a prompt containing invalid UTF-8
            // makes json_encode return false silently, so an empty body goes out and an
            // inexplicable 400 comes back. JSON_UNESCAPED_UNICODE keeps the prompt as written.
            $encoded = json_encode(
                $body,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            if (strlen($encoded) > self::MAX_REQUEST_BYTES) {
                throw new ApiError(sprintf(
                    'the encoded request is %d bytes; the limit is %d. Upload large media and '
                    . 'reference its popnow:// URI instead of inlining it.',
                    strlen($encoded),
                    self::MAX_REQUEST_BYTES,
                ), status: 413, businessCode: 413);
            }
        }

        $url = $this->baseUrl . $path;
        $lastError = null;
        $budget = $timeoutSeconds ?? $this->requestTimeoutSeconds;
        $deadline = $this->now() + $budget;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            $remaining = $deadline - $this->now();
            if ($remaining <= 0) {
                throw new TimeoutError(sprintf(
                    'the request exceeded the local %.1fs timeout; whether it reached the server is unknown',
                    $budget,
                ));
            }
            try {
                // Every attempt is clamped to the remaining budget, or retries would multiply the
                // caller's ceiling by the number of attempts.
                $response = $this->transport->send($method, $url, $requestHeaders, $encoded, $remaining);
            } catch (TimeoutError | TransportError $error) {
                // No response: whether the request reached the server is unknown, so only calls
                // declared retryable are resent. The type propagates unchanged, because
                // waitForTerminal relies on it to tell one slow poll from the whole wait failing.
                if (!$retryable || $attempt === $this->maxRetries) {
                    throw $error;
                }
                $lastError = $error;
                $this->sleep(min($this->retryDelay($attempt, null), max(0.0, $deadline - $this->now())));
                continue;
            }

            $decoded = json_decode($response->body, true);
            $error = self::envelopeError($response, $decoded);
            if ($error === null) {
                /** @var array<string,mixed> $decoded */
                return self::envelopeData($response, $decoded);
            }

            $canRetry = $retryable
                && $attempt < $this->maxRetries
                && in_array($response->status, ApiError::RETRYABLE_STATUSES, true);
            if (!$canRetry) {
                throw $error;
            }
            // A Retry-After longer than our own ceiling is not sat out: the caller gets the error
            // and can schedule the retry, rather than holding a PHP process asleep.
            if ($error->retryAfterSeconds !== null && $error->retryAfterSeconds > $this->maxRetryDelaySeconds) {
                throw $error;
            }
            $lastError = $error;
            $this->sleep(min(
                $this->retryDelay($attempt, $error->retryAfterSeconds),
                max(0.0, $deadline - $this->now()),
            ));
        }

        throw $lastError ?? new ApiError('the request failed after all retries');
    }

    /**
     * Null when the response is a success envelope; otherwise the error to throw.
     *
     * @param mixed $decoded The body decoded once and passed in, not decoded again.
     */
    private static function envelopeError(HttpResponse $response, mixed $decoded): ?ApiError
    {
        $isObject = is_array($decoded) && !array_is_list($decoded);

        $code = $isObject && is_int($decoded['code'] ?? null) ? $decoded['code'] : null;
        $requestId = $isObject && is_string($decoded['request_id'] ?? null) ? $decoded['request_id'] : '';
        if ($requestId === '') {
            $requestId = (string) ($response->header('X-Request-Id') ?? '');
        }
        $httpOk = $response->status >= 200 && $response->status < 300;

        if ($httpOk && $code === 200) {
            return null;
        }
        if (!$isObject) {
            // An HTML or plain-text page from the network in between. The body is not echoed:
            // it is not part of the contract and may be large.
            return new ApiError(
                sprintf('the response was not a JSON envelope (HTTP %d)', $response->status),
                status: $response->status,
                requestId: $requestId,
                retryAfterSeconds: self::parseRetryAfter($response->header('Retry-After')),
            );
        }

        $message = is_string($decoded['msg'] ?? null) && $decoded['msg'] !== ''
            ? $decoded['msg']
            : sprintf('the request failed with HTTP %d', $response->status);
        $data = $decoded['data'] ?? null;
        $reason = is_array($data) && is_string($data['reason'] ?? null) && $data['reason'] !== ''
            ? $data['reason']
            : null;

        return new ApiError(
            $message,
            status: $response->status,
            businessCode: $code,
            requestId: $requestId,
            retryAfterSeconds: self::parseRetryAfter($response->header('Retry-After')),
            reason: $reason,
        );
    }

    /**
     * The envelope's `data`, which is the only part a caller ever wants.
     *
     * @param array<string,mixed> $decoded
     */
    private static function envelopeData(HttpResponse $response, array $decoded): mixed
    {
        if (!array_key_exists('data', $decoded)) {
            throw new ApiError(
                'a successful envelope omitted data',
                status: $response->status,
                businessCode: 200,
                requestId: is_string($decoded['request_id'] ?? null) ? $decoded['request_id'] : '',
            );
        }

        return $decoded['data'];
    }

    /**
     * `Retry-After` in seconds, accepting both forms RFC 9110 allows.
     *
     * The contract's header schema is an integer, but a proxy in front of the
     * API may rewrite it as an HTTP-date, so both are parsed.
     */
    private static function parseRetryAfter(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (preg_match('/^\d+(?:\.\d+)?$/', $trimmed) === 1) {
            return max(0.0, (float) $trimmed);
        }
        $timestamp = strtotime($trimmed);

        return $timestamp === false ? null : max(0.0, (float) ($timestamp - time()));
    }

    /** Exponential backoff with jitter, honouring `Retry-After` when the server sent one. */
    private function retryDelay(int $attempt, ?float $retryAfterSeconds): float
    {
        if ($retryAfterSeconds !== null) {
            return min($retryAfterSeconds, $this->maxRetryDelaySeconds);
        }

        return min($this->jitter($this->retryBaseDelaySeconds * (2 ** $attempt)), $this->maxRetryDelaySeconds);
    }

    /** Plus or minus 25% so a fleet of workers does not retry in lockstep. */
    private function jitter(float $seconds): float
    {
        return $seconds * (0.75 + (mt_rand() / mt_getrandmax()) * 0.5);
    }

    private function now(): float
    {
        return microtime(true);
    }

    private function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }
        usleep((int) round($seconds * 1_000_000));
    }

    /**
     * An empty PHP array encodes as `[]`, and the contract wants an object.
     *
     * @param array<string,mixed> $value
     */
    private static function jsonObject(array $value): array|\stdClass
    {
        return $value === [] ? new \stdClass() : $value;
    }

    /**
     * Build a query suffix from the parameters that actually have a value.
     *
     * The contract rejects unknown, duplicate **and empty** query parameters:
     * null means "not set" and is left out, while an empty string is refused
     * here rather than sent (a 400) or dropped (which would silently return the
     * unfiltered list).
     *
     * @param array<string,string|int|null> $values
     */
    private static function queryString(array $values): string
    {
        $present = [];
        foreach ($values as $name => $value) {
            if ($value === null) {
                continue;
            }
            $text = is_int($value) ? (string) $value : trim($value);
            if ($text === '') {
                throw new \InvalidArgumentException(sprintf(
                    '%s was given an empty value; pass null to leave it unset',
                    $name,
                ));
            }
            $present[$name] = $text;
        }

        return $present === [] ? '' : '?' . http_build_query($present);
    }

    /** A `YYYY-MM-DD` UTC date, as `format: date` in the contract spells it. */
    private static function requireDate(?string $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '%s must be a UTC date as YYYY-MM-DD, not "%s"',
                $label,
                $value,
            ));
        }

        return $trimmed;
    }

    private static function requireIdentifier(string $value, string $label): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new \InvalidArgumentException($label . ' is required');
        }
        if (strpbrk($trimmed, "\r\n") !== false) {
            throw new \InvalidArgumentException($label . ' must not contain line breaks');
        }

        return $trimmed;
    }

    /**
     * Characters in a UTF-8 string, without depending on ext-mbstring, which a
     * default PHP build does not guarantee. Invalid UTF-8 is refused here rather
     * than at json_encode time.
     */
    private static function utf8Length(string $value): int
    {
        $count = preg_match_all('/./su', $value);
        if ($count === false) {
            throw new \InvalidArgumentException('the value is not valid UTF-8');
        }

        return $count;
    }

    /** Contract: 1 to 128 characters, retained per API key for 24 hours. */
    private static function requireIdempotencyKey(string $value): string
    {
        $key = self::requireIdentifier($value, 'idempotencyKey');
        if (strlen($key) > 128) {
            throw new \InvalidArgumentException('idempotencyKey must be at most 128 characters');
        }

        return $key;
    }

    /**
     * The contract rejects `http://` callbacks (the delivery carries the prompt
     * and signed result links), so that is caught before any request. Private
     * addresses and ports are checked by the server, which can resolve them.
     */
    private static function requireCallbackUrl(string $value): string
    {
        $url = self::requireIdentifier($value, 'callBackUrl');
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            throw new \InvalidArgumentException('callBackUrl must be a public https:// URL');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('callBackUrl must not contain credentials');
        }

        return $url;
    }

    /**
     * @param mixed $data
     *
     * @return array<string,mixed>
     */
    private function requireArray(mixed $data): array
    {
        if (!is_array($data)) {
            throw new ApiError('the response data was not an object', status: 200, businessCode: 200);
        }

        return $data;
    }
}
