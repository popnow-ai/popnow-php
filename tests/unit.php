<?php

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use PopNow\ApiError;
use PopNow\Client;
use PopNow\CurlTransport;
use PopNow\EndpointWebhook;
use PopNow\HttpResponse;
use PopNow\TimeoutError;
use PopNow\TransportError;
use PopNow\UploadError;
use PopNow\Webhook;
use PopNow\WebhookError;
use PopNow\StreamTransport;
use PopNow\TaskErrorCode;
use PopNow\TaskState;
use PopNow\Transport;
use PopNow\UploadContentType;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "  ok   $label\n"; }
    else { $failed++; echo "  FAIL $label" . ($detail === '' ? '' : " -- $detail") . "\n"; }
}

/**
 * Every tracked text file in the repository, resolved through git.
 *
 * git is asked rather than glob because it already knows what is published: it skips vendor/, the
 * build output and anything ignored, so the scan cannot be fooled into passing by a directory that
 * happens to be empty on this machine.
 *
 * @return list<string>
 */
function tracked_text_files(string $root): array
{
    static $cached = null;
    if ($cached !== null) { return $cached; }

    $output = [];
    $status = 0;
    exec('git -C ' . escapeshellarg($root) . ' ls-files 2>/dev/null', $output, $status);
    if ($status !== 0 || $output === []) {
        // Not a checkout, or git is unavailable. Returning an empty list here would make the scan
        // pass by reading nothing, so say so loudly instead.
        fwrite(STDERR, "tracked_text_files: git ls-files returned nothing\n");
        return $cached = [];
    }

    $binary = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'mp4', 'mov', 'ico', 'zip', 'gz', 'bin'];
    $files = [];
    foreach ($output as $relative) {
        $path = $root . '/' . $relative;
        if (!is_file($path)) { continue; }
        if (in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), $binary, true)) { continue; }
        $files[] = $path;
    }

    return $cached = $files;
}

/** Records every call and replays scripted responses. */
final class FakeTransport implements Transport
{
    public array $calls = [];
    /** @var list<HttpResponse|Throwable> */
    private array $queue;

    public function __construct(array $queue) { $this->queue = $queue; }

    public function send(string $method, string $url, array $headers, $body, float $timeoutSeconds): HttpResponse
    {
        $this->calls[] = [
            'method' => $method, 'url' => $url, 'headers' => $headers,
            'body' => is_resource($body) ? '<stream:' . stream_get_contents($body) . '>' : $body,
            'timeout' => $timeoutSeconds,
        ];
        if ($this->queue === []) { throw new RuntimeException("unexpected call $method $url"); }
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) { throw $next; }
        return $next;
    }
    public function drained(): bool { return $this->queue === []; }
}

function envelope(mixed $data, int $code = 200, int $status = 200, array $headers = [], string $msg = 'success'): HttpResponse
{
    return new HttpResponse($status, $headers, json_encode(
        ['code' => $code, 'msg' => $msg, 'data' => $data, 'request_id' => 'req_test'],
        JSON_THROW_ON_ERROR,
    ));
}

function errorEnvelope(int $status, int $code, string $msg, array $headers = []): HttpResponse
{
    return new HttpResponse($status, $headers, json_encode(
        ['code' => $code, 'msg' => $msg, 'request_id' => 'req_err'],
        JSON_THROW_ON_ERROR,
    ));
}

putenv('POPNOW_API_KEY=sk-popnow-' . str_repeat('a', 48));
$noSleep = static function (float $s): void {};

/* -- 1. Construction and authentication ------------------------------ */
echo "construction and authentication\n";
$t = new FakeTransport([envelope(['total' => 0, 'items' => []])]);
$client = new Client(transport: $t, sleeper: $noSleep);
$client->listModels();
check('Authorization header is a bearer key', ($t->calls[0]['headers']['Authorization'] ?? '') === 'Bearer sk-popnow-' . str_repeat('a', 48));
check('Accept header set', ($t->calls[0]['headers']['Accept'] ?? '') === 'application/json');
check('no Content-Type on GET', !isset($t->calls[0]['headers']['Content-Type']));
check('base URL is /api/v1', $t->calls[0]['url'] === 'https://api.popnow.ai/api/v1/models');

putenv('POPNOW_API_KEY');
unset($_SERVER['POPNOW_API_KEY']);
try { new Client(transport: new FakeTransport([])); check('missing key throws', false); }
catch (InvalidArgumentException $e) { check('missing key throws', str_contains($e->getMessage(), 'POPNOW_API_KEY')); }
$_SERVER['POPNOW_API_KEY'] = 'sk-popnow-fromserver';
try { new Client(transport: new FakeTransport([])); check('falls back to $_SERVER (php-fpm)', true); }
catch (Throwable $e) { check('falls back to $_SERVER (php-fpm)', false, $e->getMessage()); }
unset($_SERVER['POPNOW_API_KEY']);
$t = new FakeTransport([envelope(['total' => 0, 'items' => []])]);
(new Client(apiKey: 'sk-popnow-explicit', transport: $t, sleeper: $noSleep))->listModels();
check('an explicit apiKey works without the environment',
    ($t->calls[0]['headers']['Authorization'] ?? '') === 'Bearer sk-popnow-explicit');
try { new Client(apiKey: "sk-popnow-a\r\nX-Injected: 1", transport: new FakeTransport([])); check('a key with a line break is refused', false); }
catch (InvalidArgumentException $e) { check('a key with a line break is refused', true); }
$constructor = new ReflectionMethod(Client::class, '__construct');
check('the apiKey parameter is marked SensitiveParameter (redacted from PHP 8.2 stack traces)',
    array_filter($constructor->getParameters()[0]->getAttributes(), static fn ($a) => $a->getName() === 'SensitiveParameter') !== []);
putenv('POPNOW_API_KEY=sk-popnow-' . str_repeat('a', 48));

$t = new FakeTransport([envelope(['total' => 0, 'items' => []])]);
(new Client(transport: $t, sleeper: $noSleep, language: 'pt-BR'))->listModels();
check('language is sent as Accept-Language', ($t->calls[0]['headers']['Accept-Language'] ?? '') === 'pt-BR');
$t = new FakeTransport([envelope(['total' => 0, 'items' => []])]);
(new Client(transport: $t, sleeper: $noSleep))->listModels();
check('no Accept-Language unless asked', !isset($t->calls[0]['headers']['Accept-Language']));
try { new Client(transport: new FakeTransport([]), language: "en\r\nX: 1"); check('a malformed language is refused', false); }
catch (InvalidArgumentException $e) { check('a malformed language is refused', true); }

/* -- 2. Catalogue ---------------------------------------------------- */
echo "catalogue\n";
$t = new FakeTransport([envelope(['model' => 'x/y/text-to-image'])]);
$client = new Client(transport: $t, sleeper: $noSleep);
$client->getModel('family/1.0/text-to-image');
check('model slug is URL-encoded into one segment',
    $t->calls[0]['url'] === 'https://api.popnow.ai/api/v1/models/family%2F1.0%2Ftext-to-image',
    $t->calls[0]['url']);

$t = new FakeTransport([envelope(['total' => 1, 'items' => []])]);
$client = new Client(transport: $t, sleeper: $noSleep);
$client->listModels(modality: 'video', task: 'image-to-video', includeSchema: true, includeExamples: false);
check('query params encoded',
    str_contains($t->calls[0]['url'], 'modality=video')
    && str_contains($t->calls[0]['url'], 'task=image-to-video')
    && str_contains($t->calls[0]['url'], 'includeSchema=1')
    && str_contains($t->calls[0]['url'], 'includeExamples=0'),
    $t->calls[0]['url']);

/* -- 3. Quotes ------------------------------------------------------- */
echo "quotes\n";
$quoteData = ['quoteId' => 'q_1', 'model' => 'm', 'estimatedCost' => '0.043200000',
    'maxCharge' => '0.043200000', 'currency' => 'USD', 'quantity' => '1', 'unit' => 'image',
    'expiresAt' => '2026-09-20T00:05:00Z'];
$t = new FakeTransport([envelope($quoteData)]);
$client = new Client(transport: $t, sleeper: $noSleep);
$quote = $client->quote('m', ['prompt' => 'p']);
check('quote returns data unwrapped', $quote['quoteId'] === 'q_1' && $quote['estimatedCost'] === '0.043200000');
check('quote posts to /jobs/quote', $t->calls[0]['url'] === 'https://api.popnow.ai/api/v1/jobs/quote' && $t->calls[0]['method'] === 'POST');

/* -- 4. Task creation ------------------------------------------------ */
echo "task creation\n";
$created = ['taskId' => 'task_1', 'state' => 'queued', 'estimatedCost' => '0.0432', 'deadlineAt' => '2026-09-20T01:00:00Z'];
$t = new FakeTransport([envelope($created, status: 202)]);
$client = new Client(transport: $t, sleeper: $noSleep);
$task = $client->createTask(model: 'm', input: ['prompt' => 'p'], idempotencyKey: 'k-1',
    quoteId: 'q_1', expectedCost: '0.0432', retentionSeconds: 3600, callBackUrl: 'https://example.com/hook');
check('HTTP 202 accepted as success', $task['taskId'] === 'task_1');
$sent = json_decode($t->calls[0]['body'], true);
check('body carries model/input/quoteId/expectedCost/callBackUrl',
    $sent['model'] === 'm' && $sent['input'] === ['prompt' => 'p']
    && $sent['quoteId'] === 'q_1' && $sent['expectedCost'] === '0.0432'
    && $sent['callBackUrl'] === 'https://example.com/hook');
check('Idempotency-Key header sent', ($t->calls[0]['headers']['Idempotency-Key'] ?? '') === 'k-1');
check('X-PopNow-Retention header sent', ($t->calls[0]['headers']['X-PopNow-Retention'] ?? '') === '3600');

// Credits: expectedCredits is the quote's maxCredits, sent as an integer.
$t = new FakeTransport([envelope($created, status: 202)]);
(new Client(transport: $t, sleeper: $noSleep))->createTask(model: 'm', input: ['prompt' => 'p'],
    idempotencyKey: 'k-c', quoteId: 'q_1', expectedCredits: 34);
$sent = json_decode($t->calls[0]['body'], true);
check('expectedCredits goes in the body as an integer', ($sent['expectedCredits'] ?? null) === 34, $t->calls[0]['body']);
check('no expectedCost unless given', !array_key_exists('expectedCost', $sent));
foreach (['expectedCredits' => -1, 'expectedCost' => '0.1.2', 'callBackUrl' => 'http://example.com/hook',
          'callBackUrl ' => 'https://user:pw@example.com/hook'] as $field => $badValue) {
    try {
        $arguments = ['model' => 'm', 'input' => ['a' => 1], 'idempotencyKey' => 'k', trim($field) => $badValue];
        (new Client(transport: new FakeTransport([]), sleeper: $noSleep))->createTask(...$arguments);
        check("createTask refuses $field=" . var_export($badValue, true) . ' locally', false);
    } catch (InvalidArgumentException $e) {
        check("createTask refuses $field=" . var_export($badValue, true) . ' locally', true);
    }
}

// An empty input must encode as {} rather than [].
$t = new FakeTransport([envelope($created, status: 202)]);
$client = new Client(transport: $t, sleeper: $noSleep);
$client->createTask(model: 'm', input: [], idempotencyKey: 'k-2');
check('empty input encodes as {} not []', str_contains($t->calls[0]['body'], '"input":{}'), $t->calls[0]['body']);

// An over-long idempotency key.
try {
    (new Client(transport: new FakeTransport([]), sleeper: $noSleep))
        ->createTask(model: 'm', input: ['a' => 1], idempotencyKey: str_repeat('x', 129));
    check('idempotencyKey >128 rejected locally', false);
} catch (InvalidArgumentException $e) { check('idempotencyKey >128 rejected locally', true); }

// An oversized body is stopped locally.
try {
    (new Client(transport: new FakeTransport([]), sleeper: $noSleep))
        ->createTask(model: 'm', input: ['prompt' => str_repeat('x', 2_200_000)], idempotencyKey: 'k');
    check('oversized request rejected locally', false);
} catch (ApiError $e) {
    check('oversized request rejected locally', $e->status === 413 && str_contains($e->getMessage(), '2097152'));
}

// Invalid UTF-8 must throw rather than silently send an empty body.
try {
    (new Client(transport: new FakeTransport([]), sleeper: $noSleep))
        ->createTask(model: 'm', input: ['prompt' => "\xB1\x31"], idempotencyKey: 'k');
    check('invalid UTF-8 throws instead of sending an empty body', false);
} catch (JsonException $e) { check('invalid UTF-8 throws instead of sending an empty body', true); }

/* -- 5. Retry semantics ---------------------------------------------- */
echo "retry semantics\n";
// With an idempotency key, a 500 may be retried automatically.
$t = new FakeTransport([errorEnvelope(500, 500, 'boom'), envelope($created, status: 202)]);
$client = new Client(transport: $t, sleeper: $noSleep);
$task = $client->createTask(model: 'm', input: ['a' => 1], idempotencyKey: 'k');
check('createTask with key retries a 500', $task['taskId'] === 'task_1' && count($t->calls) === 2);

// Without a key, no retry.
$t = new FakeTransport([errorEnvelope(500, 500, 'boom'), envelope($created, status: 202)]);
$client = new Client(transport: $t, sleeper: $noSleep);
try { $client->createTask(model: 'm', input: ['a' => 1]); check('createTask without key does NOT retry', false); }
catch (ApiError $e) { check('createTask without key does NOT retry', count($t->calls) === 1, 'calls=' . count($t->calls)); }

// A 400 is never retried, not even for a call declared retryable.
$t = new FakeTransport([errorEnvelope(400, 40004, 'change resolution'), envelope([])]);
$client = new Client(transport: $t, sleeper: $noSleep);
try { $client->listModels(); check('40004 is not retried', false); }
catch (ApiError $e) {
    check('40004 is not retried', count($t->calls) === 1);
    check('40004 guidance says change the parameter, do not retry',
        str_contains($e->guidance(), 'Change the parameter') && str_contains($e->guidance(), 'Do not retry'));
    check('business code surfaced', $e->businessCode === 40004 && $e->getCode() === 40004);
    check('request_id surfaced', $e->requestId === 'req_err');
    check('msg surfaced', $e->getMessage() === 'change resolution');
}

// A Retry-After beyond the ceiling is handed straight back.
$t = new FakeTransport([errorEnvelope(429, 429, 'slow down', ['retry-after' => '600']), envelope([])]);
$client = new Client(transport: $t, sleeper: $noSleep, maxRetryDelaySeconds: 30.0);
try { $client->listModels(); check('Retry-After beyond ceiling is handed back', false); }
catch (ApiError $e) {
    check('Retry-After beyond ceiling is handed back', count($t->calls) === 1);
    check('retryAfterSeconds parsed', $e->retryAfterSeconds === 600.0);
}

// A Retry-After within the ceiling is honoured, then retried.
$t = new FakeTransport([errorEnvelope(429, 429, 'slow', ['retry-after' => '2']), envelope(['total' => 0, 'items' => []])]);
$client = new Client(transport: $t, sleeper: $noSleep);
$client->listModels();
check('Retry-After within ceiling is honoured then retried', count($t->calls) === 2);

// Three business codes share the 503 status.
$codes = [503 => 'temporarily unavailable', 50301 => 'no usable deployment', 50302 => 'refunded'];
foreach ($codes as $code => $needle) {
    $t = new FakeTransport([errorEnvelope(503, $code, 'unavailable'), errorEnvelope(503, $code, 'unavailable'), errorEnvelope(503, $code, 'unavailable')]);
    $client = new Client(transport: $t, sleeper: $noSleep);
    try { $client->getTask('task_1'); check("503/$code raises", false); }
    catch (ApiError $e) {
        check("503/$code keeps its business code", $e->status === 503 && $e->businessCode === $code);
        check("503/$code guidance is code-specific", stripos($e->guidance(), $needle) !== false, $e->guidance());
    }
}
$t = new FakeTransport([errorEnvelope(400, 40003, 'mismatch')]);
try { (new Client(transport: $t, sleeper: $noSleep))->commitUploadedFile('fil_1'); }
catch (ApiError $e) {
    check('40003 guidance says re-upload, not re-commit',
        str_contains($e->guidance(), 'fresh upload ticket') && str_contains($e->guidance(), 'Retrying the commit'));
}

// Transport failure.
$t = new FakeTransport([new TransportError('net down'), envelope(['total' => 0, 'items' => []])]);
$client = new Client(transport: $t, sleeper: $noSleep);
$client->listModels();
check('transport error retried on a retryable call', count($t->calls) === 2);
$t = new FakeTransport([new TransportError('net down')]);
$client = new Client(transport: $t, sleeper: $noSleep);
try { $client->createTask(model: 'm', input: ['a' => 1]); check('transport error not retried without key', false); }
catch (TransportError $e) { check('transport error not retried without key', count($t->calls) === 1); }

// Malformed JSON.
$t = new FakeTransport([new HttpResponse(200, [], '<html>gateway</html>')]);
try { (new Client(transport: $t, sleeper: $noSleep))->getTask('t'); check('non-JSON body raises', false); }
catch (ApiError $e) { check('non-JSON body raises', str_contains($e->getMessage(), 'not a JSON envelope')); }

// HTTP 200 with a business code other than 200.
$t = new FakeTransport([new HttpResponse(200, [], json_encode(['code' => 40301, 'msg' => 'nope', 'request_id' => 'r']))]);
try { (new Client(transport: $t, sleeper: $noSleep))->getTask('t'); check('HTTP 200 with code!=200 raises', false); }
catch (ApiError $e) { check('HTTP 200 with code!=200 raises', $e->businessCode === 40301); }

/* -- 6. Polling ------------------------------------------------------ */
echo "polling\n";
$running = ['taskId' => 't', 'model' => 'm', 'state' => 'running', 'cost' => '0.04', 'settled' => false, 'createdAt' => 'x'];
$succeeded = ['taskId' => 't', 'model' => 'm', 'state' => 'succeeded', 'cost' => '0.04', 'settled' => true,
    'createdAt' => 'x', 'output' => ['assets' => [['key' => 'a', 'url' => 'https://cdn/x.png', 'mime' => 'image/png']]]];
$pending = $succeeded; $pending['output']['assets'][0] = ['key' => 'a', 'pending' => true];

$t = new FakeTransport([envelope($running), envelope($running), envelope($succeeded)]);
$client = new Client(transport: $t, sleeper: $noSleep);
$seen = [];
$final = $client->waitForTerminal('t', onUpdate: function (array $task) use (&$seen): void { $seen[] = $task['state']; });
check('waitForTerminal polls to a terminal state', $final['state'] === 'succeeded' && count($t->calls) === 3);
check('onUpdate fires per poll', $seen === ['running', 'running', 'succeeded']);

$t = new FakeTransport([envelope($pending), envelope($succeeded)]);
$client = new Client(transport: $t, sleeper: $noSleep);
$final = $client->waitForTerminal('t');
check('succeeded-with-pending-assets keeps polling', count($t->calls) === 2 && Client::readyAssets($final) !== []);

$t = new FakeTransport(array_fill(0, 200, envelope($running)));
$realSleep = static function (float $s): void { usleep(20000); };
$client = new Client(transport: $t, sleeper: $realSleep);
try { $client->waitForTerminal('t', timeoutSeconds: 0.05); check('wait timeout raises TimeoutError', false); }
catch (TimeoutError $e) {
    check('wait timeout raises TimeoutError', $e->taskId === 't');
    check('wait timeout message says remote state is unknown', str_contains($e->getMessage(), 'remote state is unknown'));
    check('TimeoutError is a ApiError', $e instanceof ApiError);
}

$t = new FakeTransport([envelope(['taskId' => 't', 'state' => 'teleported', 'model' => 'm', 'cost' => '0', 'settled' => false, 'createdAt' => 'x'])]);
$client = new Client(transport: $t, sleeper: $noSleep);
try { $client->waitForTerminal('t'); check('unknown state is not treated as terminal', false); }
catch (ApiError $e) { check('unknown state is not treated as terminal', str_contains($e->getMessage(), 'unknown task state')); }

/* ── 7. retry / purge ──────────────────────────────────────────── */
echo "retry / purge\n";
$t = new FakeTransport([envelope(['taskId' => 't2', 'sourceTaskId' => 't1', 'state' => 'queued', 'estimatedCost' => '0.04', 'deadlineAt' => 'x'])]);
$client = new Client(transport: $t, sleeper: $noSleep);
$r = $client->retryTask('t1', 'k');
check('retryTask returns sourceTaskId', $r['sourceTaskId'] === 't1');
check('retryTask body carries taskId', json_decode($t->calls[0]['body'], true) === ['taskId' => 't1']);

$t = new FakeTransport([errorEnvelope(500, 500, 'boom'), envelope(['taskId' => 't', 'contentState' => 'purged', 'billingRetained' => true, 'mediaDeletionPending' => true])]);
$client = new Client(transport: $t, sleeper: $noSleep);
$p = $client->purgeTask('t');
check('purge retries without any Idempotency-Key', count($t->calls) === 2);
check('purge sends no Idempotency-Key header', !isset($t->calls[0]['headers']['Idempotency-Key']));
check('purge reports billingRetained', $p['billingRetained'] === true);

/* -- 8. The three upload steps --------------------------------------- */
echo "upload steps\n";
$tmpBase = (string) tempnam(sys_get_temp_dir(), 'popnow');
$tmp = $tmpBase . '.png';
file_put_contents($tmp, str_repeat("\x89PNG", 10));
$bytes = filesize($tmp);
$ticket = ['fileId' => 'fil_abc', 'key' => 'popnow://f/fil_abc', 'uploadUrl' => 'https://storage.example/obj?sig=1',
    'method' => 'PUT', 'headers' => ['Content-Type' => 'image/png', 'x-amz-meta-owner' => 'acct_1'],
    'expiresAt' => 'x', 'maxBytes' => 10485760];
$commit = ['fileId' => 'fil_abc', 'status' => 'ready', 'bytes' => $bytes, 'contentType' => 'image/png',
    'sha256' => str_repeat('a', 64), 'uri' => 'popnow://f/fil_abc', 'expiresAt' => 'x'];
$t = new FakeTransport([envelope($ticket), new HttpResponse(200, [], ''), envelope($commit)]);
$client = new Client(transport: $t, sleeper: $noSleep);
$file = $client->uploadFile($tmp);
check('uploadFile returns the popnow:// URI', $file['uri'] === 'popnow://f/fil_abc');
check('step 1 posts contentType + exact bytes',
    json_decode($t->calls[0]['body'], true) === ['contentType' => 'image/png', 'bytes' => $bytes]);
check('step 2 PUTs to the signed URL', $t->calls[1]['method'] === 'PUT' && $t->calls[1]['url'] === 'https://storage.example/obj?sig=1');
check('step 2 forwards every ticket header verbatim',
    array_diff_key($t->calls[1]['headers'], ['User-Agent' => 1]) === ['Content-Type' => 'image/png', 'x-amz-meta-owner' => 'acct_1'],
    json_encode($t->calls[1]['headers']));
check('step 2 names the client in its User-Agent', ($t->calls[1]['headers']['User-Agent'] ?? '') === Client::userAgent());
check('step 2 sends NO Authorization header', !isset($t->calls[1]['headers']['Authorization']));
check('step 2 streams the file', $t->calls[1]['body'] === '<stream:' . str_repeat("\x89PNG", 10) . '>');
check('step 2 uses the long upload timeout', $t->calls[1]['timeout'] === 600.0);
check('step 3 commits by fileId', $t->calls[2]['url'] === 'https://api.popnow.ai/api/v1/files/fil_abc/commit');
check('step 3 sends no body', $t->calls[2]['body'] === null);

// A failed PUT is not retried.
$t = new FakeTransport([envelope($ticket), new HttpResponse(403, [], '<Error>SignatureDoesNotMatch</Error>')]);
$client = new Client(transport: $t, sleeper: $noSleep);
try { $client->uploadFile($tmp); check('failed PUT raises UploadError and is not retried', false); }
catch (UploadError $e) {
    check('failed PUT raises UploadError and is not retried', count($t->calls) === 2 && $e->status === 403);
    check('storage body is not leaked into the message', !str_contains($e->getMessage(), 'SignatureDoesNotMatch'));
}

// Over the ticket's maxBytes.
$smallTicket = $ticket; $smallTicket['maxBytes'] = 4;
$t = new FakeTransport([envelope($smallTicket)]);
$client = new Client(transport: $t, sleeper: $noSleep);
try { $client->uploadFile($tmp); check('oversize vs ticket maxBytes rejected before PUT', false); }
catch (UploadError $e) { check('oversize vs ticket maxBytes rejected before PUT', count($t->calls) === 1); }

// uploadBytes
$t = new FakeTransport([envelope($ticket), new HttpResponse(200, [], ''), envelope($commit)]);
$client = new Client(transport: $t, sleeper: $noSleep);
$client->uploadBytes('abcd', UploadContentType::Png);
check('uploadBytes sends the in-memory body', $t->calls[1]['body'] === 'abcd');

// The local size gate.
try {
    (new Client(transport: new FakeTransport([]), sleeper: $noSleep))
        ->createUploadUrl(UploadContentType::Png, 11 * 1024 * 1024);
    check('image over 10 MiB rejected locally', false);
} catch (UploadError $e) { check('image over 10 MiB rejected locally', $e->status === 413); }
check('video ceiling is 90 MiB', UploadContentType::Mp4->maxBytes() === 94371840);
check('contentType inferred from extension', UploadContentType::forPath('/a/b/c.MP4') === UploadContentType::Mp4);
try { UploadContentType::forPath('/a/b/c.mov'); check('unknown extension rejected', false); }
catch (InvalidArgumentException $e) { check('unknown extension rejected', str_contains($e->getMessage(), 'mp4')); }
unlink($tmp);
@unlink($tmpBase);

// Reference documents. Without these cases there is no way to upload one at all: createUploadUrl
// takes the enum, not a free string.
// forPath throws on an unknown extension; caught here so a missing mapping fails one check instead
// of ending the suite.
$inferred = static function (string $path): ?UploadContentType {
    try { return UploadContentType::forPath($path); } catch (InvalidArgumentException) { return null; }
};
check('PDF inferred from extension', $inferred('/a/brief.PDF') === UploadContentType::Pdf);
foreach (['a.doc' => 'application/msword', 'a.docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
          'a.xls' => 'application/vnd.ms-excel', 'a.xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
          'a.ppt' => 'application/vnd.ms-powerpoint', 'a.pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
          'a.key' => 'application/vnd.apple.keynote', 'a.pages' => 'application/vnd.apple.pages',
          'a.numbers' => 'application/vnd.apple.numbers', 'a.txt' => 'text/plain',
          'a.md' => 'text/markdown'] as $documentPath => $expectedType) {
    check("$documentPath inferred as $expectedType", $inferred($documentPath)?->value === $expectedType);
}
check('documents are documents, media is not',
    UploadContentType::Pdf->isDocument() && UploadContentType::Markdown->isDocument()
    && !UploadContentType::Png->isDocument() && !UploadContentType::Mp4->isDocument()
    && !UploadContentType::Wav->isDocument());
check('a document gets the 90 MiB ceiling, not the image one', UploadContentType::Pdf->maxBytes() === 94371840);
$docTicket = ['fileId' => 'fil_doc', 'key' => 'popnow://f/fil_doc', 'uploadUrl' => 'https://storage.example/doc?sig=1',
    'method' => 'PUT', 'headers' => ['Content-Type' => 'application/pdf'], 'expiresAt' => 'x', 'maxBytes' => 94371840];
$t = new FakeTransport([envelope($docTicket)]);
(new Client(transport: $t, sleeper: $noSleep))->createUploadUrl(UploadContentType::Pdf, 20 * 1024 * 1024);
check('a 20 MiB PDF is not refused locally and is declared as application/pdf',
    json_decode($t->calls[0]['body'], true) === ['contentType' => 'application/pdf', 'bytes' => 20 * 1024 * 1024]);

// LoRA weights: application/octet-stream, a .safetensors file up to 2 GiB.
check('.safetensors inferred as LoRA weights', $inferred('/a/style.safetensors') === UploadContentType::LoraWeights);
check('LoRA weights are neither media nor a document',
    UploadContentType::LoraWeights->isLoraWeights() && !UploadContentType::LoraWeights->isDocument()
    && !UploadContentType::LoraWeights->isImage());
check('LoRA weights get the 2 GiB ceiling', UploadContentType::LoraWeights->maxBytes() === 2147483648);
$loraTicket = ['fileId' => 'fil_lora', 'key' => 'popnow://f/fil_lora', 'uploadUrl' => 'https://storage.example/lora?sig=1',
    'method' => 'PUT', 'headers' => ['Content-Type' => 'application/octet-stream'], 'expiresAt' => 'x', 'maxBytes' => 2147483648];
$loraCommit = ['fileId' => 'fil_lora', 'status' => 'ready', 'bytes' => 4, 'contentType' => 'application/octet-stream',
    'sha256' => str_repeat('b', 64), 'uri' => 'popnow://f/fil_lora', 'expiresAt' => 'x'];
$t = new FakeTransport([envelope($loraTicket), new HttpResponse(200, [], ''), envelope($loraCommit)]);
(new Client(transport: $t, sleeper: $noSleep))->uploadBytes('safe', UploadContentType::LoraWeights);
check('a LoRA PUT gets the two-hour ticket window as its timeout', $t->calls[1]['timeout'] >= 7200.0,
    (string) $t->calls[1]['timeout']);

// The enums must equal the pinned contract's lists. scripts/check_contract.php compares against the
// published contract over the network; this runs offline, so a forgotten case fails every local run.
require_once __DIR__ . '/../scripts/contract_lib.php';
$pinned = (string) file_get_contents(__DIR__ . '/../contracts/openapi.yaml');
foreach ([
    'UploadContentType' => ['UploadURLRequest', 'contentType', UploadContentType::cases(), 8],
    'TaskState' => ['TaskRecord', 'state', TaskState::cases(), 6],
    'TaskErrorCode' => ['TaskRecord', 'errorCode', TaskErrorCode::cases(), 9],
] as $enumName => [$schemaName, $fieldName, $cases, $minimum]) {
    $pinnedValues = contract_enum($pinned, $schemaName, $fieldName) ?? [];
    $ours = array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);
    sort($ours);
    check("the pinned contract lists $schemaName.$fieldName at all", count($pinnedValues) >= $minimum,
        (string) count($pinnedValues));
    check("$enumName equals the pinned contract enum", $ours === $pinnedValues,
        implode(',', array_diff($pinnedValues, $ours)) . ' | ' . implode(',', array_diff($ours, $pinnedValues)));
}
check('Client::ASSET_SOURCES equals the pinned contract enum',
    contract_enum($pinned, 'AssetCreateRequest', 'source') === (static function (): array {
        $values = Client::ASSET_SOURCES; sort($values); return $values;
    })());

/* ── 9. download-url ───────────────────────────────────────────── */
echo "download-url\n";
$t = new FakeTransport([envelope(['key' => 'a', 'url' => 'https://cdn/x?sig=2', 'expiresAt' => 'x'])]);
$client = new Client(transport: $t, sleeper: $noSleep);
$d = $client->createDownloadUrl('t', 'a');
check('createDownloadUrl posts taskId + key', json_decode($t->calls[0]['body'], true) === ['taskId' => 't', 'key' => 'a']);
check('createDownloadUrl returns a url', $d['url'] === 'https://cdn/x?sig=2');
$t = new FakeTransport([envelope(['key' => 'a', 'url' => 'u', 'expiresAt' => 'x'])]);
(new Client(transport: $t, sleeper: $noSleep))->createDownloadUrl('t');
check('key omitted when not given', json_decode($t->calls[0]['body'], true) === ['taskId' => 't']);

/* -- 10. An output is not always a file ------------------------------ */
echo "outputs\n";
$textOnly = ['taskId' => 't', 'state' => 'succeeded', 'output' => ['text' => 'transcribed lyrics']];
check('outputText reads text', Client::outputText($textOnly) === 'transcribed lyrics');
check('outputAssets is [] when there is no assets key', Client::outputAssets($textOnly) === []);
check('readyAssets is [] when there is no assets key', Client::readyAssets($textOnly) === []);
check('outputText null when absent', Client::outputText($succeeded) === null);
// An empty string means "the field is there and it is empty", which is not the same as "the field
// is absent". The other clients all return the empty string as-is; folding the two into null would
// make a task that handed in a blank look like one that produced a file.
check('outputText keeps an empty string as an empty string',
    Client::outputText(['output' => ['text' => '']]) === '');
check('outputText is null only when the field is absent or not a string',
    Client::outputText(['output' => ['text' => 123]]) === null
    && Client::outputText(['output' => []]) === null);
check('no output key at all is safe', Client::outputAssets(['taskId' => 't']) === [] && Client::outputText(['taskId' => 't']) === null);
$mixed = ['state' => 'succeeded', 'output' => ['assets' => [
    ['key' => 'a', 'url' => 'https://cdn/1'], ['key' => 'b', 'pending' => true], ['key' => 'c', 'unavailable' => true],
]]];
check('readyAssets filters pending and unavailable', count(Client::readyAssets($mixed)) === 1);
check('outputAssets keeps all three', count(Client::outputAssets($mixed)) === 3);

/* -- 11. Enums ------------------------------------------------------- */
echo "enums\n";
check('terminal states', TaskState::Succeeded->isTerminal() && TaskState::Failed->isTerminal()
    && TaskState::Canceled->isTerminal() && TaskState::Expired->isTerminal());
check('active states', !TaskState::Queued->isTerminal() && !TaskState::Running->isTerminal());
check('unknown errorCode folds into upstream_failed',
    TaskErrorCode::fromTask(['errorCode' => 'brand_new_code']) === TaskErrorCode::UpstreamFailed);
check('known errorCode maps', TaskErrorCode::fromTask(['errorCode' => 'content_rejected']) === TaskErrorCode::ContentRejected);
check('absent errorCode is null', TaskErrorCode::fromTask(['state' => 'succeeded']) === null);
check('content_rejected is not worth retrying', !TaskErrorCode::ContentRejected->isWorthRetrying());
check('upstream_unavailable is worth retrying', TaskErrorCode::UpstreamUnavailable->isWorthRetrying());

/* -- 12. USD string comparison --------------------------------------- */
echo "USD\n";
check('compareUsd equal', Client::compareUsd('0.0432', '0.04320') === 0);
check('compareUsd nine decimals', Client::compareUsd('0.000000001', '0.000000002') === -1);
check('compareUsd magnitude', Client::compareUsd('10.0', '9.99') === 1);
check('compareUsd negative', Client::compareUsd('-1.5', '0.5') === -1);
check('compareUsd zero forms', Client::compareUsd('0', '0.000') === 0);
check('compareUsd survives float-lossy values', Client::compareUsd('0.1', '0.3') === -1);

/* ── 13. Webhook ───────────────────────────────────────────────── */
echo "Webhook\n";
$secret = 'whsec_test_key';
$now = 1_758_000_000;

// v2
$v2Body = json_encode(['code' => 200, 'msg' => 'success', 'request_id' => 'del_1',
    'data' => ['taskId' => 'task_9', 'model' => 'm', 'state' => 'succeeded', 'cost' => '0.04', 'settled' => true, 'createdAt' => 'x']]);
$sig2 = Webhook::computeSignature('task_9', (string) $now, $v2Body, $secret);
$v = Webhook::verify($v2Body, (string) $now, $sig2, $secret, 2, now: $now);
check('v2 verifies', $v->taskId === 'task_9' && $v->payloadVersion === 2);
check('v2 delivery id is request_id', $v->deliveryId === 'del_1');
check('v2 state()', $v->state() === TaskState::Succeeded);
check('v2 taskRecord()', ($v->taskRecord()['model'] ?? null) === 'm');

// v1
$v1Body = json_encode(['task_id' => 'task_9', 'model' => 'm', 'state' => 'failed',
    'error_code' => 'content_rejected', 'cost' => '0', 'created_at' => 'x']);
$sig1 = Webhook::computeSignature('task_9', (string) $now, $v1Body, $secret);
$v1 = Webhook::verify($v1Body, (string) $now, $sig1, $secret, 1, now: $now);
check('v1 verifies from top-level task_id', $v1->taskId === 'task_9' && $v1->payloadVersion === 1);
check('v1 state()', $v1->state() === TaskState::Failed);
check('v1 taskRecord() is null', $v1->taskRecord() === null);
check('v1 has no delivery id', $v1->deliveryId === null);

// Reading the wrong version means no taskId.
try { Webhook::verify($v1Body, (string) $now, $sig1, $secret, 2, now: $now); check('v1 body read as v2 is rejected', false); }
catch (WebhookError $e) { check('v1 body read as v2 is rejected', $e->reason === 'invalid_payload'); }

// A tampered body.
$tampered = str_replace('succeeded', 'failed___', $v2Body);
try { Webhook::verify($tampered, (string) $now, $sig2, $secret, 2, now: $now); check('tampered body rejected', false); }
catch (WebhookError $e) { check('tampered body rejected', $e->reason === 'invalid_signature'); }

// A re-serialised body - the most common PHP mistake. In a real delivery the body is the bytes the
// server's encoder produced; re-encoding in PHP changes the escaping (here / becomes \/) and the
// digest stops matching immediately.
$wireBody = '{"code":200,"msg":"success","request_id":"del_2","data":{"taskId":"task_9",'
    . '"state":"succeeded","output":{"assets":[{"url":"https://cdn.example.com/a.png?x=1&y=2"}]}}}';
$wireSig = Webhook::computeSignature('task_9', (string) $now, $wireBody, $secret);
check('a wire-shaped body verifies as delivered',
    Webhook::verify($wireBody, (string) $now, $wireSig, $secret, 2, now: $now)->taskId === 'task_9');
$reencoded = json_encode(json_decode($wireBody, true));
check('re-encoding really does change the bytes', $reencoded !== $wireBody);
try { Webhook::verify($reencoded, (string) $now, $wireSig, $secret, 2, now: $now); check('re-encoded body rejected', false); }
catch (WebhookError $e) { check('re-encoded body rejected', $e->reason === 'invalid_signature'); }

// The wrong secret.
try { Webhook::verify($v2Body, (string) $now, $sig2, 'other', 2, now: $now); check('wrong secret rejected', false); }
catch (WebhookError $e) { check('wrong secret rejected', $e->reason === 'invalid_signature'); }

// A stale timestamp.
$old = $now - 4000;
$sigOld = Webhook::computeSignature('task_9', (string) $old, $v2Body, $secret);
try { Webhook::verify($v2Body, (string) $old, $sigOld, $secret, 2, now: $now); check('stale timestamp rejected', false); }
catch (WebhookError $e) { check('stale timestamp rejected', $e->reason === 'stale_timestamp'); }
$v = Webhook::verify($v2Body, (string) $old, $sigOld, $secret, 2, toleranceSeconds: 5000, now: $now);
check('a wider tolerance accepts it', $v->taskId === 'task_9');

// A future timestamp is outside the window too.
$future = $now + 4000;
$sigF = Webhook::computeSignature('task_9', (string) $future, $v2Body, $secret);
try { Webhook::verify($v2Body, (string) $future, $sigF, $secret, 2, now: $now); check('future timestamp rejected', false); }
catch (WebhookError $e) { check('future timestamp rejected', $e->reason === 'stale_timestamp'); }

// Malformed input.
foreach ([['not-a-number', 'invalid_timestamp'], ['', 'invalid_timestamp']] as [$ts, $reason]) {
    try { Webhook::verify($v2Body, $ts, $sig2, $secret, 2, now: $now); check("timestamp '$ts' rejected", false); }
    catch (WebhookError $e) { check("timestamp '$ts' rejected", $e->reason === $reason, $e->reason); }
}
try { Webhook::verify('not json', (string) $now, $sig2, $secret, 2, now: $now); check('non-JSON body rejected', false); }
catch (WebhookError $e) { check('non-JSON body rejected', $e->reason === 'invalid_json'); }
try { Webhook::verify('[1,2,3]', (string) $now, $sig2, $secret, 2, now: $now); check('JSON array body rejected', false); }
catch (WebhookError $e) { check('JSON array body rejected', $e->reason === 'invalid_json'); }
try { Webhook::verify($v2Body, (string) $now, $sig2, '  ', 2, now: $now); check('blank secret rejected', false); }
catch (WebhookError $e) { check('blank secret rejected', $e->reason === 'invalid_secret'); }
try { Webhook::verify(str_repeat('x', 11_000_000), (string) $now, $sig2, $secret, 2, now: $now); check('oversized body rejected before HMAC', false); }
catch (WebhookError $e) { check('oversized body rejected before HMAC', $e->reason === 'body_too_large'); }
try { Webhook::verify($v2Body, (string) $now, $sig2, $secret, 7, now: $now); check('unknown payload version rejected', false); }
catch (WebhookError $e) { check('unknown payload version rejected', $e->reason === 'invalid_payload'); }

// Surrounding whitespace in the headers is tolerated.
$v = Webhook::verify($v2Body, ' ' . $now . ' ', "  $sig2  ", $secret, 2, now: $now);
check('surrounding whitespace in headers tolerated', $v->taskId === 'task_9');

// verifyRequest reads from $_SERVER.
$server = [
    'HTTP_X_WEBHOOK_SIGNATURE' => $sig2,
    'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $now,
    'HTTP_X_WEBHOOK_PAYLOAD_VERSION' => '2',
];
$v = Webhook::verifyRequest($secret, $server, $v2Body, toleranceSeconds: 10_000_000_000);
check('verifyRequest reads HTTP_X_WEBHOOK_* from $_SERVER', $v->taskId === 'task_9');
unset($server['HTTP_X_WEBHOOK_PAYLOAD_VERSION']);
$v = Webhook::verifyRequest($secret, $server, $v2Body, toleranceSeconds: 10_000_000_000);
check('missing version header defaults to v2', $v->payloadVersion === 2);
unset($server['HTTP_X_WEBHOOK_SIGNATURE']);
try { Webhook::verifyRequest($secret, $server, $v2Body); check('missing signature header rejected', false); }
catch (WebhookError $e) { check('missing signature header rejected', $e->reason === 'missing_header'); }

// The signature's shape: Base64 over HMAC-SHA256.
$expected = base64_encode(hash_hmac('sha256', 'task_9.' . $now . '.' . hash('sha256', $v2Body), $secret, true));
check('signature is base64(hmac_sha256(taskId.timestamp.hex_sha256(body)))', $sig2 === $expected);
check('signature decodes to 32 bytes', strlen((string) base64_decode($sig2, true)) === 32);

/* -- 13.1 Account endpoints (Standard Webhooks) ----------------------- */
echo "Endpoint webhooks\n";
$endpointKey = random_bytes(24);
$endpointSecret = 'whsec_' . base64_encode($endpointKey);
$eventBody = '{"type":"task.succeeded","timestamp":"2026-10-19T00:00:00Z","data":{"object":'
    . '{"taskId":"task_9","state":"succeeded","credits":34}}}';
$msgId = 'msg_01k3m8x9q2z4v7n5p6r8s0t1w3';
$endpointSig = EndpointWebhook::computeSignature($msgId, (string) $now, $eventBody, $endpointSecret);
check('endpoint signature is v1,base64(hmac(decoded key, id.ts.body))',
    $endpointSig === 'v1,' . base64_encode(hash_hmac('sha256', "$msgId.$now.$eventBody", $endpointKey, true)));
$event = EndpointWebhook::verify($eventBody, $msgId, (string) $now, $endpointSig, $endpointSecret, now: $now);
check('endpoint event verifies', $event->webhookId === $msgId && $event->type() === 'task.succeeded');
check('endpoint taskRecord() is data.object', ($event->taskRecord()['taskId'] ?? null) === 'task_9');
// During a rotation the header holds two signatures; either one matching is enough.
$oldSig = EndpointWebhook::computeSignature($msgId, (string) $now, $eventBody, 'whsec_' . base64_encode('old-key-old-key'));
check('a rotated header with the new signature second still verifies',
    EndpointWebhook::verify($eventBody, $msgId, (string) $now, "$oldSig $endpointSig", $endpointSecret, now: $now)->webhookId === $msgId);
// The HMAC key is the decoded secret, not the secret string: a receiver that keys with the raw
// string computes a different value and must not match.
$rawKeyed = 'v1,' . base64_encode(hash_hmac('sha256', "$msgId.$now.$eventBody", $endpointSecret, true));
try { EndpointWebhook::verify($eventBody, $msgId, (string) $now, $rawKeyed, $endpointSecret, now: $now); check('keying with the undecoded secret is rejected', false); }
catch (WebhookError $e) { check('keying with the undecoded secret is rejected', $e->reason === 'invalid_signature'); }
try { EndpointWebhook::verify($eventBody . ' ', $msgId, (string) $now, $endpointSig, $endpointSecret, now: $now); check('endpoint: tampered body rejected', false); }
catch (WebhookError $e) { check('endpoint: tampered body rejected', $e->reason === 'invalid_signature'); }
try { EndpointWebhook::verify($eventBody, 'msg_other', (string) $now, $endpointSig, $endpointSecret, now: $now); check('endpoint: a different webhook-id is rejected', false); }
catch (WebhookError $e) { check('endpoint: a different webhook-id is rejected', $e->reason === 'invalid_signature'); }
$staleSig = EndpointWebhook::computeSignature($msgId, (string) ($now - 4000), $eventBody, $endpointSecret);
try { EndpointWebhook::verify($eventBody, $msgId, (string) ($now - 4000), $staleSig, $endpointSecret, now: $now); check('endpoint: stale timestamp rejected', false); }
catch (WebhookError $e) { check('endpoint: stale timestamp rejected', $e->reason === 'stale_timestamp'); }
try { EndpointWebhook::verify($eventBody, $msgId, (string) $now, $endpointSig, 'whsec_!!!', now: $now); check('endpoint: a malformed secret is rejected', false); }
catch (WebhookError $e) { check('endpoint: a malformed secret is rejected', $e->reason === 'invalid_secret'); }
$event = EndpointWebhook::verifyRequest($endpointSecret, [
    'HTTP_WEBHOOK_ID' => $msgId,
    'HTTP_WEBHOOK_TIMESTAMP' => (string) time(),
    'HTTP_WEBHOOK_SIGNATURE' => EndpointWebhook::computeSignature($msgId, (string) time(), $eventBody, $endpointSecret),
], $eventBody);
check('endpoint verifyRequest reads HTTP_WEBHOOK_* from $_SERVER', $event->webhookId === $msgId);
try { EndpointWebhook::verifyRequest($endpointSecret, ['HTTP_WEBHOOK_ID' => $msgId], $eventBody); check('endpoint: missing headers rejected', false); }
catch (WebhookError $e) { check('endpoint: missing headers rejected', $e->reason === 'missing_header'); }

/* -- 13.5 Account, usage and task history ---------------------------- */
echo "account and history\n";
$balanceData = ['available' => '12.340000000', 'held' => '0.043200000', 'total' => '12.383200000'];
$t = new FakeTransport([envelope($balanceData)]);
$client = new Client(transport: $t, sleeper: $noSleep);
$balance = $client->getBalance();
check('getBalance reads GET /chat/credit',
    $t->calls[0]['method'] === 'GET' && $t->calls[0]['url'] === 'https://api.popnow.ai/api/v1/chat/credit',
    $t->calls[0]['url']);
check('getBalance unwraps the envelope data', $balance['available'] === '12.340000000');
check('getBalance sends no body', $t->calls[0]['body'] === null);

$usageData = ['from' => '2026-09-01', 'to' => '2026-09-08', 'currency' => 'USD',
    'totalCalls' => 12, 'totalSpend' => '1.234000000', 'days' => [], 'models' => []];
$t = new FakeTransport([envelope($usageData)]);
$client = new Client(transport: $t, sleeper: $noSleep);
$usage = $client->getUsage('2026-09-01', '2026-09-08');
check('getUsage encodes from/to',
    str_contains($t->calls[0]['url'], '/usage?') && str_contains($t->calls[0]['url'], 'from=2026-09-01')
    && str_contains($t->calls[0]['url'], 'to=2026-09-08'), $t->calls[0]['url']);
check('getUsage unwraps the envelope data', $usage['totalSpend'] === '1.234000000');

// With no parameters at all, not even a question mark goes out: the contract states plainly that
// empty parameters are rejected.
$t = new FakeTransport([envelope($usageData)]);
$client = new Client(transport: $t, sleeper: $noSleep);
$client->getUsage();
check('getUsage with no bounds sends no query string at all',
    $t->calls[0]['url'] === 'https://api.popnow.ai/api/v1/usage', $t->calls[0]['url']);

// These are calendar dates, not instants. A timestamp is stopped on the spot rather than becoming
// a quietly misaligned window.
try { (new Client(transport: new FakeTransport([]), sleeper: $noSleep))->getUsage('2026-09-01T00:00:00Z'); 
    check('getUsage rejects a timestamp where a date belongs', false); }
catch (InvalidArgumentException $e) {
    check('getUsage rejects a timestamp where a date belongs', str_contains($e->getMessage(), 'YYYY-MM-DD'), $e->getMessage());
}

$page1 = ['items' => [['taskId' => 't1'], ['taskId' => 't2']], 'hasMore' => true, 'nextCursor' => 'cur_2'];
$page2 = ['items' => [['taskId' => 't3']], 'hasMore' => false];
$t = new FakeTransport([envelope($page1)]);
$client = new Client(transport: $t, sleeper: $noSleep);
$list = $client->listTasks(from: '2026-09-01', to: '2026-09-08', state: 'succeeded', model: 'demo/1.0/text-to-image', limit: 50, cursor: 'cur_1');
check('listTasks unwraps items/hasMore/nextCursor',
    count($list['items']) === 2 && $list['hasMore'] === true && $list['nextCursor'] === 'cur_2');
foreach (['from=2026-09-01', 'to=2026-09-08', 'state=succeeded', 'model=demo', 'limit=50', 'cursor=cur_1'] as $fragment) {
    check("listTasks encodes $fragment", str_contains($t->calls[0]['url'], $fragment), $t->calls[0]['url']);
}

$t = new FakeTransport([envelope($page2)]);
$client = new Client(transport: $t, sleeper: $noSleep);
$client->listTasks();
check('listTasks with no filters sends no query string at all',
    $t->calls[0]['url'] === 'https://api.popnow.ai/api/v1/jobs', $t->calls[0]['url']);

// limit rejects non-positive values only. The upper bound is deliberately not enforced locally: the
// day the platform relaxes it to 200, a hard local ceiling would reject a value that already works,
// and the user would see nothing but the SDK refusing.
foreach ([0, -1] as $badLimit) {
    try { (new Client(transport: new FakeTransport([]), sleeper: $noSleep))->listTasks(limit: $badLimit);
        check("listTasks rejects limit=$badLimit", false); }
    catch (InvalidArgumentException $e) { check("listTasks rejects limit=$badLimit", true); }
}
$t = new FakeTransport([envelope($page2)]);
(new Client(transport: $t, sleeper: $noSleep))->listTasks(limit: 500);
check('listTasks does not impose a local ceiling on limit',
    str_contains($t->calls[0]['url'], 'limit=500'), $t->calls[0]['url']);

// An empty string means "the computed filter came out empty", not "unset". Sending it earns a 400;
// dropping it silently is worse, because the caller gets the entire unfiltered list with no way to
// notice.
try { (new Client(transport: new FakeTransport([]), sleeper: $noSleep))->listTasks(state: '');
    check('listTasks refuses an empty filter instead of dropping it', false); }
catch (InvalidArgumentException $e) {
    check('listTasks refuses an empty filter instead of dropping it', str_contains($e->getMessage(), 'state'), $e->getMessage());
}

// Paging: every page repeats all the filters and moves only the cursor.
$t = new FakeTransport([envelope($page1), envelope($page2)]);
$client = new Client(transport: $t, sleeper: $noSleep);
$walked = iterator_to_array($client->iterTasks(from: '2026-09-01', state: 'succeeded'), false);
check('iterTasks yields every item across pages',
    array_column($walked, 'taskId') === ['t1', 't2', 't3'], json_encode(array_column($walked, 'taskId')));
check('iterTasks repeats every filter on the second page',
    str_contains($t->calls[1]['url'], 'from=2026-09-01') && str_contains($t->calls[1]['url'], 'state=succeeded'),
    $t->calls[1]['url']);
check('iterTasks carries the server cursor through unchanged',
    str_contains($t->calls[1]['url'], 'cursor=cur_2') && !str_contains($t->calls[0]['url'], 'cursor='),
    $t->calls[1]['url']);
check('iterTasks stops when hasMore is false', count($t->calls) === 2);

// Trusting hasMore alone turns a hasMore:true with no cursor into endless paging - billing for API
// calls forever, while from the outside it just looks like a hang. Both stop conditions are checked.
foreach ([['hasMore' => true], ['hasMore' => true, 'nextCursor' => '']] as $index => $brokenTail) {
    $t = new FakeTransport(array_fill(0, 20, envelope(['items' => [['taskId' => 'x']]] + $brokenTail)));
    $client = new Client(transport: $t, sleeper: $noSleep);
    $walked = iterator_to_array($client->iterTasks(), false);
    check('iterTasks stops on hasMore=true with no usable cursor #' . ($index + 1),
        count($t->calls) === 1 && count($walked) === 1, 'calls=' . count($t->calls));
}

// An HTTP 200 with a business code other than 200 must never be read as success. It matters most on
// these three endpoints: treated as success, the caller receives an empty result - an empty balance,
// zero usage, an empty task list - and every one of those looks like a plausible answer.
$blankCases = [
    'getBalance' => static fn (Client $c): array => $c->getBalance(),
    'getUsage' => static fn (Client $c): array => $c->getUsage(),
    'listTasks' => static fn (Client $c): array => $c->listTasks(),
];
foreach ($blankCases as $label => $call) {
    $t = new FakeTransport([new HttpResponse(200, [], json_encode(
        ['code' => 40201, 'msg' => 'insufficient balance', 'request_id' => 'r9'], JSON_THROW_ON_ERROR))]);
    $client = new Client(transport: $t, sleeper: $noSleep);
    try { $call($client); check("$label does not treat HTTP 200 with code 40201 as success", false); }
    catch (ApiError $e) {
        check("$label does not treat HTTP 200 with code 40201 as success",
            $e->businessCode === 40201 && $e->requestId === 'r9');
    }
}
// The other half of the same concern: an envelope without even a data key must not read as an empty
// result either.
foreach ($blankCases as $label => $call) {
    $t = new FakeTransport([new HttpResponse(200, [], json_encode(
        ['code' => 200, 'msg' => 'success', 'request_id' => 'r8'], JSON_THROW_ON_ERROR))]);
    $client = new Client(transport: $t, sleeper: $noSleep);
    try { $call($client); check("$label does not treat a missing data field as an empty result", false); }
    catch (ApiError $e) {
        check("$label does not treat a missing data field as an empty result",
            str_contains($e->getMessage(), 'omitted data'), $e->getMessage());
    }
}
// All three are read-only and safe to resend, so they should be retried automatically.
$t = new FakeTransport([errorEnvelope(500, 500, 'boom'), envelope($balanceData)]);
$client = new Client(transport: $t, sleeper: $noSleep);
$client->getBalance();
check('a read-only account call retries a 500', count($t->calls) === 2);

/* -- 13.6 createTask's wait, and isTerminal -------------------------- */
echo "wait and isTerminal\n";
$t = new FakeTransport([envelope($created, status: 202)]);
$client = new Client(transport: $t, sleeper: $noSleep);
$client->createTask(model: 'm', input: ['prompt' => 'p'], idempotencyKey: 'k');
check('no waitSeconds means no query string on createTask',
    $t->calls[0]['url'] === 'https://api.popnow.ai/api/v1/jobs/createTask', $t->calls[0]['url']);

$t = new FakeTransport([envelope($succeeded)]);
$client = new Client(transport: $t, sleeper: $noSleep);
$done = $client->createTask(model: 'm', input: ['prompt' => 'p'], idempotencyKey: 'k', waitSeconds: 30);
check('waitSeconds goes on the query string', str_contains($t->calls[0]['url'], '?wait=30'), $t->calls[0]['url']);
// The local request timeout has to accommodate the server-side wait. The collision shows up as
// "wait appears to do nothing", when the real cause is the local side cutting the call off while the
// server is still waiting.
check('the local timeout leaves room for the server-side wait',
    $t->calls[0]['timeout'] >= 30.0 + 9.0, (string) $t->calls[0]['timeout']);
check('a terminal record comes back whole', $done['state'] === 'succeeded');

// The server clamps anything over 60 to 60 and ignores non-positive integers. Nothing is clamped
// locally: copying the ceiling here would bury a constant that will expire. Only negatives are
// rejected, being a caller error rather than a platform limit.
$t = new FakeTransport([envelope($created, status: 202)]);
$client = new Client(transport: $t, sleeper: $noSleep);
$client->createTask(model: 'm', input: ['prompt' => 'p'], idempotencyKey: 'k', waitSeconds: 90);
check('waitSeconds above the server ceiling is sent unclamped',
    str_contains($t->calls[0]['url'], 'wait=90'), $t->calls[0]['url']);
check('... and the local timeout still covers it',
    $t->calls[0]['timeout'] >= 90.0 + 9.0, (string) $t->calls[0]['timeout']);
$t = new FakeTransport([envelope($created, status: 202)]);
$client = new Client(transport: $t, sleeper: $noSleep);
$client->createTask(model: 'm', input: ['prompt' => 'p'], idempotencyKey: 'k', waitSeconds: 0);
check('waitSeconds 0 is passed through for the server to ignore',
    str_contains($t->calls[0]['url'], 'wait=0'), $t->calls[0]['url']);
try {
    (new Client(transport: new FakeTransport([]), sleeper: $noSleep))
        ->createTask(model: 'm', input: ['a' => 1], idempotencyKey: 'k', waitSeconds: -1);
    check('a negative waitSeconds is refused', false);
} catch (InvalidArgumentException $e) { check('a negative waitSeconds is refused', true); }

// When wait runs out, what comes back is a 202 acceptance response - the same shape as a terminal
// record, differing only in state. The assumption "I passed wait, so it must be finished" silently
// carries a still-running task forward.
check('an accepted response is not terminal', Client::isTerminal($created) === false);
check('a finished record is terminal', Client::isTerminal($succeeded) === true);
foreach (['failed', 'canceled', 'expired'] as $terminalState) {
    check("isTerminal accepts $terminalState", Client::isTerminal(['state' => $terminalState]) === true);
}
foreach (['queued', 'running'] as $activeState) {
    check("isTerminal rejects $activeState", Client::isTerminal(['state' => $activeState]) === false);
}
// An unrecognised state is not terminal: treating it as such would silently discard a task that is
// still running and still being billed.
check('isTerminal treats an unknown state as not terminal',
    Client::isTerminal(['state' => 'teleported']) === false);
check('isTerminal is safe on a record with no state at all',
    Client::isTerminal(['taskId' => 't']) === false && Client::isTerminal([]) === false);

/* -- 14. User-Agent and idempotency-key prefixes --------------------- */
echo "User-Agent\n";
$t = new FakeTransport([envelope(['total' => 0, 'items' => []])]);
$client = new Client(transport: $t, sleeper: $noSleep);
$client->listModels();
$ua = $t->calls[0]['headers']['User-Agent'] ?? '';
check('User-Agent does not call itself an example', stripos($ua, 'example') === false, $ua);
// The API's edge refuses default library User-Agents, so the client always names itself, with its
// version, on every call.
check('User-Agent is popnow-php/<version> with a comment',
    preg_match('#^popnow-php/' . preg_quote(Client::VERSION, '#') . ' \(php/[0-9.]+[^)]*\)$#', $ua) === 1, $ua);
// One release number, written in three places that must agree: Client::VERSION, the newest
// CHANGELOG heading and (at release time) the git tag Composer resolves.
preg_match('/^## (\d+\.\d+\.\d+)/m', (string) file_get_contents(__DIR__ . '/../CHANGELOG.md'), $changelogVersion);
check('Client::VERSION equals the newest CHANGELOG heading',
    ($changelogVersion[1] ?? '') === Client::VERSION, ($changelogVersion[1] ?? '?') . ' vs ' . Client::VERSION);
$composerJson = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
check('composer.json declares no version (Composer reads the git tag)',
    is_array($composerJson) && !array_key_exists('version', $composerJson));
check('composer.json is the popnow/popnow package with the PopNow\\ namespace',
    ($composerJson['name'] ?? '') === 'popnow/popnow' && ($composerJson['autoload']['psr-4']['PopNow\\'] ?? '') === 'src/');

$generatedKey = Client::idempotencyKey();
check('generated idempotency keys are not labelled example', stripos($generatedKey, 'example') === false, $generatedKey);
check('generated idempotency keys carry a product prefix', str_starts_with($generatedKey, 'popnow-'), $generatedKey);
check('idempotencyKey still honours an explicit prefix',
    str_starts_with(Client::idempotencyKey('order-42'), 'order-42-'));

/* -- 14.5 LoRA import and the asset library --------------------------- */
echo "LoRA import and assets\n";
$t = new FakeTransport([envelope($loraCommit)]);
$file = (new Client(transport: $t, sleeper: $noSleep))->importLoraFile('owner/repo', token: 'hf_secret');
check('importLoraFile posts {url, token} to /files/import',
    $t->calls[0]['url'] === 'https://api.popnow.ai/api/v1/files/import'
    && json_decode($t->calls[0]['body'], true) === ['url' => 'owner/repo', 'token' => 'hf_secret']);
check('importLoraFile returns the committed file', $file['uri'] === 'popnow://f/fil_lora');
$t = new FakeTransport([errorEnvelope(500, 500, 'boom'), envelope($loraCommit)]);
try { (new Client(transport: $t, sleeper: $noSleep))->importLoraFile('https://example.com/a.safetensors'); check('importLoraFile is not retried blindly', false); }
catch (ApiError $e) { check('importLoraFile is not retried blindly', count($t->calls) === 1); }

$asset = ['id' => 'ast_1', 'uri' => 'popnow://a/ast_1', 'name' => 'Hero shot', 'kind' => 'image', 'favorite' => false];
$t = new FakeTransport([errorEnvelope(503, 503, 'busy'), envelope($asset)]);
$saved = (new Client(transport: $t, sleeper: $noSleep))->saveAsset('task_output', 'save-task_9-0',
    taskId: 'task_9', index: 0, name: 'Hero shot');
check('saveAsset posts to /assets', $t->calls[0]['method'] === 'POST' && $t->calls[0]['url'] === 'https://api.popnow.ai/api/v1/assets');
check('saveAsset sends the required Idempotency-Key', ($t->calls[0]['headers']['Idempotency-Key'] ?? '') === 'save-task_9-0');
check('saveAsset body is {source, taskId, index, name}',
    json_decode($t->calls[0]['body'], true) === ['source' => 'task_output', 'taskId' => 'task_9', 'index' => 0, 'name' => 'Hero shot'],
    (string) $t->calls[0]['body']);
check('saveAsset is retried, because the key makes it safe', count($t->calls) === 2 && $saved['uri'] === 'popnow://a/ast_1');
$t = new FakeTransport([envelope($asset), envelope($asset)]);
$assetClient = new Client(transport: $t, sleeper: $noSleep);
$assetClient->saveAsset('upload', 'k-up', fileId: 'popnow://f/fil_abc');
$assetClient->saveAsset('url', 'k-url', url: 'https://example.com/a.png');
check('saveAsset upload sends fileId', json_decode($t->calls[0]['body'], true) === ['source' => 'upload', 'fileId' => 'popnow://f/fil_abc']);
check('saveAsset url sends url', json_decode($t->calls[1]['body'], true) === ['source' => 'url', 'url' => 'https://example.com/a.png']);
foreach ([
    'an unknown source' => ['source' => 'disk', 'idempotencyKey' => 'k'],
    'task_output with neither key nor index' => ['source' => 'task_output', 'idempotencyKey' => 'k', 'taskId' => 't'],
    'task_output with both key and index' => ['source' => 'task_output', 'idempotencyKey' => 'k', 'taskId' => 't', 'key' => 'a', 'index' => 0],
    'upload without fileId' => ['source' => 'upload', 'idempotencyKey' => 'k'],
    'url that is not http(s)' => ['source' => 'url', 'idempotencyKey' => 'k', 'url' => 'ftp://example.com/a.png'],
    'an empty idempotency key' => ['source' => 'upload', 'idempotencyKey' => ' ', 'fileId' => 'fil_1'],
    'a name over 80 characters' => ['source' => 'upload', 'idempotencyKey' => 'k', 'fileId' => 'fil_1', 'name' => str_repeat("\u{e9}", 81)],
] as $label => $arguments) {
    try { (new Client(transport: new FakeTransport([]), sleeper: $noSleep))->saveAsset(...$arguments); check("saveAsset refuses $label", false); }
    catch (InvalidArgumentException $e) { check("saveAsset refuses $label", true); }
}
check('an 80-character accented name is counted in characters, not bytes', (static function () use ($asset, $noSleep): bool {
    $t = new FakeTransport([envelope($asset)]);
    (new Client(transport: $t, sleeper: $noSleep))->saveAsset('upload', 'k', fileId: 'fil_1', name: str_repeat("\u{e9}", 80));
    return count($t->calls) === 1;
})());

$t = new FakeTransport([envelope(['items' => [$asset], 'nextCursor' => 'c2'])]);
(new Client(transport: $t, sleeper: $noSleep))->listAssets(kind: 'image', source: ['task_output', 'url'],
    q: 'hero', favorite: true, sort: 'name', limit: 10, cursor: 'c1');
foreach (['kind=image', 'source=task_output%2Curl', 'q=hero', 'favorite=true', 'sort=name', 'limit=10', 'cursor=c1'] as $fragment) {
    check("listAssets encodes $fragment", str_contains($t->calls[0]['url'], $fragment), $t->calls[0]['url']);
}
$t = new FakeTransport([envelope(['items' => [$asset], 'nextCursor' => 'c2']), envelope(['items' => [$asset]])]);
$walked = iterator_to_array((new Client(transport: $t, sleeper: $noSleep))->iterAssets(kind: 'video'), false);
check('iterAssets follows nextCursor and stops when it is absent', count($walked) === 2 && count($t->calls) === 2
    && str_contains($t->calls[1]['url'], 'cursor=c2') && str_contains($t->calls[1]['url'], 'kind=video'));
$t = new FakeTransport(array_fill(0, 5, envelope(['items' => [$asset], 'nextCursor' => 'same'])));
$walked = iterator_to_array((new Client(transport: $t, sleeper: $noSleep))->iterAssets(), false);
check('iterAssets stops when the server repeats a cursor', count($t->calls) === 2, 'calls=' . count($t->calls));

$t = new FakeTransport([envelope($asset), envelope($asset), envelope(['id' => 'ast_1', 'deleted' => true]),
    envelope(['tier' => ['id' => 'free']]), envelope(['items' => []]), envelope(['id' => 'ent_1', 'assets' => []])]);
$assetClient = new Client(transport: $t, sleeper: $noSleep);
$assetClient->getAsset('ast_1');
$assetClient->updateAsset('ast_1', name: 'New name', favorite: true);
$deleted = $assetClient->deleteAsset('ast_1');
$assetClient->getAssetUsage();
$assetClient->listCharacters(assetId: 'ast_1', limit: 5);
$assetClient->getCharacter('ent_1');
$routes = array_map(static fn (array $call): string => $call['method'] . ' ' . substr($call['url'], strlen('https://api.popnow.ai/api/v1')), $t->calls);
check('asset calls hit the documented routes', $routes === [
    'GET /assets/ast_1', 'PATCH /assets/ast_1', 'DELETE /assets/ast_1', 'GET /assets/usage',
    'GET /assets/entities?assetId=ast_1&limit=5', 'GET /assets/entities/ent_1',
], implode(' | ', $routes));
check('updateAsset sends only what changes', json_decode($t->calls[1]['body'], true) === ['name' => 'New name', 'favorite' => true]);
check('deleteAsset returns {id, deleted}', $deleted['deleted'] === true);
try { (new Client(transport: new FakeTransport([]), sleeper: $noSleep))->updateAsset('ast_1'); check('updateAsset with nothing to change is refused', false); }
catch (InvalidArgumentException $e) { check('updateAsset with nothing to change is refused', true); }

// Asset library limits carry a machine-readable data.reason next to the business code.
$t = new FakeTransport([new HttpResponse(400, ['x-request-id' => 'req_hdr'], json_encode(
    ['code' => 400, 'msg' => 'Your asset library is full', 'data' => ['reason' => 'asset_library_full'], 'request_id' => 'req_full']))]);
try { (new Client(transport: $t, sleeper: $noSleep))->saveAsset('upload', 'k', fileId: 'fil_1'); check('data.reason surfaces on ApiError', false); }
catch (ApiError $e) {
    check('data.reason surfaces on ApiError', $e->reason === 'asset_library_full' && $e->businessCode === 400);
    check('describe() includes the reason', str_contains($e->describe(), 'reason=asset_library_full'), $e->describe());
}
$t = new FakeTransport([new HttpResponse(502, ['x-request-id' => 'req_hdr'], '<html>bad gateway</html>')]);
try { (new Client(transport: $t, sleeper: $noSleep, maxRetries: 0))->getAsset('ast_1'); check('X-Request-Id is kept for a non-JSON error', false); }
catch (ApiError $e) { check('X-Request-Id is kept for a non-JSON error', $e->requestId === 'req_hdr' && $e->status === 502); }

/* -- 15. Business-code guidance matches the contract ------------------ */
echo "business-code guidance\n";
$advice = static fn (int $code): string => (new ApiError('m', status: 409, businessCode: $code))->guidance();
// Contract (ErrorEnvelope.code): 50302 must be resent under a NEW Idempotency-Key; the original key
// only replays the failure already on record.
check('50302 tells you to send a new idempotency key',
    stripos($advice(50302), 'new Idempotency-Key') !== false, $advice(50302));
check('50302 no longer claims the identical request is safe',
    stripos($advice(50302), 'identical request is safe') === false, $advice(50302));
// In the contract 409 and 40901 are different things, and the advice for them is opposite.
check('409 and 40901 do not share one piece of advice', $advice(409) !== $advice(40901));
check('409 is the idempotency conflict', stripos($advice(409), 'idempotency conflict') !== false, $advice(409));
check('40901 is the price/quote conflict', stripos($advice(40901), 'price changed') !== false, $advice(40901));
check('40901 says to keep the original key, not to take a new one',
    stripos($advice(40901), 'keeping the original Idempotency-Key') !== false, $advice(40901));
// Contract: 40310 is an unverified email address. It is a 403 like a key-scope or IP refusal, and
// the fix is nothing like theirs - no key or network change helps, verifying the address does.
check('40310 points at email verification', stripos($advice(40310), 'verif') !== false
    && stripos($advice(40310), 'email') !== false, $advice(40310));
check('40310 is not given the generic advice', $advice(40310) !== $advice(40399));

/* -- 16. A poll's timeout is clamped to the remaining budget ---------- */
echo "polling timeout semantics\n";
// One polling attempt timing out is not this wait failing. While budget remains, keep polling.
$t = new FakeTransport([new TimeoutError('poll timed out'), envelope($succeeded)]);
$client = new Client(transport: $t, sleeper: $noSleep, maxRetries: 0);
// Wrapped in a try because the way this guard fails is by letting an exception escape. Uncaught,
// the whole suite would fatal here and the dozens of assertions after it would never run - a guard
// that can kill the suite mid-way goes red in a shape unlike any other failure, and whoever
// investigates starts by suspecting the test itself.
try {
    $final = $client->waitForTerminal('t', timeoutSeconds: 30.0);
    check('a single poll timing out does not end the wait',
        $final['state'] === 'succeeded' && count($t->calls) === 2, 'calls=' . count($t->calls));
} catch (Throwable $e) {
    check('a single poll timing out does not end the wait', false, get_class($e) . ': ' . $e->getMessage());
}

// When the budget genuinely runs out, the exception thrown must carry the taskId - it is the only
// thread back for reconciliation afterwards.
$t = new FakeTransport(array_fill(0, 5, new TimeoutError('poll timed out')));
$realSleeper = static function (float $s): void { usleep((int) round($s * 1_000_000)); };
$client = new Client(transport: $t, sleeper: $realSleeper, maxRetries: 0);
try {
    $client->waitForTerminal('task_77', timeoutSeconds: 1.1);
    check('a wait killed by poll timeouts still names the task', false);
} catch (TimeoutError $e) {
    check('a wait killed by poll timeouts still names the task', $e->taskId === 'task_77', var_export($e->taskId, true));
    check('... and still says the remote state is unknown', str_contains($e->getMessage(), 'remote state is unknown'));
}

// A single poll never gets a timeout longer than the whole wait.
$t = new FakeTransport([envelope($succeeded)]);
$client = new Client(transport: $t, sleeper: $noSleep);
$client->waitForTerminal('t', timeoutSeconds: 5.0);
check('a poll is never given more time than the wait has left',
    $t->calls[0]['timeout'] <= 5.0, (string) $t->calls[0]['timeout']);

// A call's budget covers its own retries too, or the caller's 5 seconds becomes 3 x 30.
$t = new FakeTransport(array_fill(0, 5, new TimeoutError('slow')));
$client = new Client(transport: $t, sleeper: $noSleep);
try { $client->getTask('t', 4.0); check('getTask enforces its own timeout', false); }
catch (TimeoutError $e) {
    check('getTask enforces its own timeout', true);
    check('every retry of a call is clamped to that call\'s budget',
        max(array_column($t->calls, 'timeout')) <= 4.0,
        implode(',', array_column($t->calls, 'timeout')));
}

/* -- 17. The transport tells a timeout from a refused connection ------ */
echo "transport timeouts\n";
// Bind a loopback socket without serving on it: the kernel completes the handshake while user space
// never accepts, so the request really did go out and the response never comes. That is exactly the
// "timed out locally, outcome unknown" class, and its remedy is the opposite of "connection refused,
// the request never went out" - only the latter is safe to resend without an idempotency key.
putenv('NO_PROXY=127.0.0.1,localhost');
putenv('no_proxy=127.0.0.1,localhost');
$blackhole = @stream_socket_server('tcp://127.0.0.1:0', $bindErrno, $bindError);
if ($blackhole === false) {
    check('a loopback socket can be bound for the timeout test', false, (string) $bindError);
} else {
    $hangUrl = 'http://' . stream_socket_get_name($blackhole, false) . '/hang';
    foreach (['CurlTransport' => new CurlTransport(), 'StreamTransport' => new StreamTransport()] as $name => $transport) {
        try {
            $transport->send('GET', $hangUrl, [], null, 0.4);
            check("$name reports a hung response as a timeout", false, 'nothing was thrown');
        } catch (TimeoutError $e) {
            check("$name reports a hung response as a timeout", true);
        } catch (TransportError $e) {
            check("$name reports a hung response as a timeout", false, $e->getMessage());
        }
    }
    fclose($blackhole);
    foreach (['CurlTransport' => new CurlTransport(), 'StreamTransport' => new StreamTransport()] as $name => $transport) {
        try {
            $transport->send('GET', $hangUrl, [], null, 5.0);
            check("$name does not call a refused connection a timeout", false, 'nothing was thrown');
        } catch (TimeoutError $e) {
            check("$name does not call a refused connection a timeout", false, 'it was reported as a timeout');
        } catch (TransportError $e) {
            check("$name does not call a refused connection a timeout", true);
        }
    }
}

/* -- 18. The README lines up with the code --------------------------- */
echo "README\n";
$readme = (string) file_get_contents(__DIR__ . '/../README.md');
preg_match_all('/^```php\R(.*?)^```/ms', $readme, $readmeBlocks);
check('the README has php examples to check', count($readmeBlocks[1]) > 0);
$readmePhp = implode("\n", $readmeBlocks[1]);
// Every client method returns an associative array. Writing ->prop is, in PHP 8, merely a warning
// plus a null, and that null only blows up at the next call site - so the error points there rather
// than at this line.
check('the README reads client results as arrays, not objects',
    preg_match('/\$(quote|task|final|uploaded|accepted|file)\s*->/', $readmePhp) !== 1, $readmePhp);
foreach ($readmeBlocks[1] as $blockIndex => $blockCode) {
    $blockBase = (string) tempnam(sys_get_temp_dir(), 'readme');
    $blockFile = $blockBase . '.php';
    file_put_contents($blockFile, "<?php\n" . $blockCode);
    $lintOutput = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($blockFile) . ' 2>&1', $lintOutput, $lintStatus);
    check('README php block #' . ($blockIndex + 1) . ' parses', $lintStatus === 0, implode(' ', $lintOutput));
    unlink($blockFile);
    @unlink($blockBase);
}
// verifyRequest takes the secret first. Passing the body as the secret and the secret as the body
// raises no type error - both are strings - and merely makes every genuine delivery fail
// verification, reported as invalid_json, which points at "the body is not JSON" rather than "the
// arguments are the wrong way round".
preg_match_all('/verifyRequest\(\s*([^,)\n]+)/', $readmePhp, $verifyArgs);
check('the README passes the secret as verifyRequest\'s first argument',
    $verifyArgs[1] !== [] && array_filter(
        $verifyArgs[1],
        static fn (string $argument): bool => stripos($argument, 'secret') === false,
    ) === [],
    implode(' | ', $verifyArgs[1]));
// Every exception property the README names must really exist on the class.
preg_match('/carrying (.+?)\./', $readme, $carrying);
preg_match_all('/`\$([A-Za-z]+)`/', $carrying[1] ?? '', $namedProperties);
$realProperties = array_map(
    static fn (ReflectionProperty $property): string => $property->getName(),
    (new ReflectionClass(ApiError::class))->getProperties(ReflectionProperty::IS_PUBLIC),
);
check('every exception property the README names really exists',
    ($namedProperties[1] ?? []) !== [] && array_diff($namedProperties[1], $realProperties) === [],
    'README: ' . implode(',', $namedProperties[1] ?? []) . ' - actual: ' . implode(',', $realProperties));
foreach ([401, 40003, 40004, 40201, 40310, 409, 40901, 503, 50301, 50302] as $documentedCode) {
    check("the README error table lists $documentedCode", str_contains($readme, '| `' . $documentedCode . '` |'));
}

/* -- 19. Repository hygiene: vendor names and CJK ------------------- */
echo "repository hygiene\n";
$trackedFiles = tracked_text_files(__DIR__ . '/..');
$trackedBytes = 0;
$trackedContents = [];
foreach ($trackedFiles as $trackedFile) {
    $trackedContents[$trackedFile] = (string) file_get_contents($trackedFile);
    $trackedBytes += strlen($trackedContents[$trackedFile]);
}
// Counter-check first: a scan that reads nothing finds nothing, so it must prove it read the
// repository. An empty file list (not a checkout, or git missing) fails here instead of passing below.
check('the hygiene scans read the repository',
    count($trackedFiles) >= 20 && $trackedBytes > 100_000,
    count($trackedFiles) . ' files, ' . $trackedBytes . ' bytes');
check('the hygiene scans include the client source',
    in_array(realpath(__DIR__ . '/../src/Client.php'), array_map('realpath', $trackedFiles), true));

/**
 * Names that must never appear in this repository: third-party infrastructure and service vendors
 * the public package has no business naming. They are stored as SHA-256 digests of the lower-cased
 * word so that this file does not contain, and therefore does not match, the names it scans for.
 * Add a name with: printf %s name | shasum -a 256
 */
const VENDOR_NAME_DIGESTS = [
    '2efa64f91736e51bfbcae85be793635c890cf873727a90a959ad0fbcffafbead',
    'f913085cbacece709571cd312b94473040b3911458ba09fb2b16d1229fe9433f',
    '66f4068e63b8c53e5a3ee2178092043355052454d09fa424f564fd6910084685',
    'b858f760399ed93651e22bac52481458da23ff4c00490531ede9b832992a42fa',
    'afa92e210e352872087e0ee958ff658373593e3d3f86e45d6e9cdb18aa56758a',
    '53d62381341991fc48b998beeef530c8fb036c9539af1d2881fad4794d523313',
    '3d469302580313fbb6d7bfd6bade27401ebcadca8a647fc153dc01303354c30c',
    '4f45d0540bebdfb19c8a9eea90fcb6e3bac6c37c4ccf01b669adbfa713b1345e',
    '9d6b0d69459b31a1c6c47ca7fc8466526c7b9620c76aae6d4d8e83508f713764',
    '5fa956575122f6b93ff58803085b870c524015af26bc8b775a6a5e3d13298793',
    '7ea0a2c0ff0cb88820886758ee1d840e1ca1d56185d0b06ec2342e0f2db17125',
    '2834e64f3da4f1eb41677ad96d9205c4dfe35997ee2df8a3ce7decf90d738a21',
];

/**
 * Every distinct word of $text whose digest is in $digests.
 *
 * @param list<string> $digests
 *
 * @return list<string>
 */
function vendor_name_hits(string $text, array $digests): array
{
    $wanted = array_flip($digests);
    $hits = [];
    preg_match_all('/[a-z0-9]+/', strtolower($text), $words);
    foreach (array_unique($words[0]) as $word) {
        if (isset($wanted[hash('sha256', $word)])) {
            $hits[] = $word;
        }
    }

    return $hits;
}

// The scanner itself must work: a planted control word with a known digest is found.
check('the vendor-name scanner finds a planted control word',
    vendor_name_hits('a line with ZebraControl in it',
        ['f07ff2d911bc1a4fae6f91ced54264746abe2edd7fda8220a79023f2c76d2244']) === ['zebracontrol']);
$vendorHits = [];
foreach ($trackedContents as $trackedFile => $contents) {
    foreach (vendor_name_hits($contents, VENDOR_NAME_DIGESTS) as $word) {
        $vendorHits[] = basename($trackedFile) . ': ' . $word;
    }
}
check('no vendor names anywhere in the repository', $vendorHits === [], implode(' | ', array_slice($vendorHits, 0, 5)));

// Everything in this public repository is written in English: code, comments, docs, tests. A comment
// most readers cannot read looks like documentation while explaining nothing. The scan covers CJK
// ideographs, Japanese kana and Korean hangul in every tracked text file.
const CJK_PATTERN = '/[\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}]/u';
check('the CJK scanner finds a planted ideograph, kana and hangul',
    preg_match(CJK_PATTERN, "x\u{4e2d}") === 1 && preg_match(CJK_PATTERN, "x\u{3042}") === 1
    && preg_match(CJK_PATTERN, "x\u{d55c}") === 1 && preg_match(CJK_PATTERN, 'plain ASCII') === 0);
$cjkHits = [];
foreach ($trackedContents as $trackedFile => $contents) {
    foreach (explode("\n", $contents) as $index => $line) {
        if (preg_match(CJK_PATTERN, $line) === 1) {
            $cjkHits[] = basename($trackedFile) . ':' . ($index + 1);
        }
    }
}
check('no CJK text anywhere in the repository', $cjkHits === [], implode(' | ', array_slice($cjkHits, 0, 5)));

echo "\npassed {$passed}, failed {$failed}\n";
exit($failed === 0 ? 0 : 1);
