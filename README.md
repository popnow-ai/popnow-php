<div align="center">
  <a href="https://popnow.ai">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/popnow-ai/popnow-php/main/assets/popnow-wordmark-light.png">
      <source media="(prefers-color-scheme: light)" srcset="https://raw.githubusercontent.com/popnow-ai/popnow-php/main/assets/popnow-wordmark.png">
      <img alt="PopNow" src="https://raw.githubusercontent.com/popnow-ai/popnow-php/main/assets/popnow-wordmark.png" width="260">
    </picture>
  </a>

  <h1>PopNow PHP SDK: AI Video, Image and Music Generation API</h1>

  <p><strong>popnow/popnow</strong> is a dependency-free PHP 8.1+ client for the PopNow API that turns text, images and audio into AI videos, images, music and speech, using the same models, prices and credits as <a href="https://popnow.ai">popnow.ai</a>.</p>

  <p>
    <a href="LICENSE"><img alt="License: MIT" src="https://img.shields.io/badge/license-MIT-blue.svg"></a>
    <img alt="PHP 8.1, 8.3, 8.4" src="https://img.shields.io/badge/php-8.1%20%7C%208.3%20%7C%208.4-777BB4.svg?logo=php&logoColor=white">
    <a href="https://github.com/popnow-ai/popnow-php/actions/workflows/ci.yml"><img alt="CI" src="https://github.com/popnow-ai/popnow-php/actions/workflows/ci.yml/badge.svg"></a>
    <a href="https://popnow.ai"><img alt="Website: popnow.ai" src="https://img.shields.io/badge/site-popnow.ai-ff5a36.svg"></a>
    <a href="https://popnow.ai/docs"><img alt="Docs" src="https://img.shields.io/badge/docs-popnow.ai%2Fdocs-111111.svg"></a>
  </p>
</div>

PopNow is a browser-based AI studio for videos, images and music: image-to-video, text-to-video, AI images, music and speech, one-tap trending video effects and image styles. This SDK wraps its developer API (`https://api.popnow.ai/api/v1`) so you can call Kling, Seedance, Wan, Vidu, MiniMax, Nano Banana, GPT Image, Flux, Suno and more from PHP. Credit packs, no subscription; failed runs are refunded. *Make it pop.*

- **No dependencies.** `ext-json` and `ext-hash`; `ext-curl` when present, otherwise the `https://` stream wrapper.
- **Safe retries.** Reads, quotes and keyed submissions are retried on `429` / `5xx` honouring `Retry-After`; a task without an `Idempotency-Key` is never resent behind your back.
- **The whole async flow.** Quote, create, wait (`?wait=` or polling), retry, purge, history, usage and balance.
- **Files and assets.** Streaming three-step upload (90 MiB media, 2 GiB LoRA weights), LoRA import from a link, and the asset library (`popnow://a/...`).
- **Webhooks.** Constant-time verification of task callbacks and Standard Webhooks account endpoints.

## Quickstart

**1. Install** with Composer from this GitHub repository (a Packagist release is coming):

```bash
composer config repositories.popnow vcs https://github.com/popnow-ai/popnow-php
composer require popnow/popnow:dev-main
```

That is the same as adding this to your `composer.json`:

```json
{
  "repositories": [{ "type": "vcs", "url": "https://github.com/popnow-ai/popnow-php" }],
  "require": { "popnow/popnow": "dev-main" }
}
```

No Composer? `git clone https://github.com/popnow-ai/popnow-php` and `require 'popnow-php/autoload.php';`.

**2. Create an API key** in the [API console](https://popnow.ai/api/console/keys) and export it:

```bash
export POPNOW_API_KEY="sk-popnow-..."
```

**3. Generate:**

```php
require __DIR__ . '/vendor/autoload.php';

use PopNow\Client;

$client = new Client();                       // reads POPNOW_API_KEY

$task = $client->createTask(
    model: 'alibaba/z-image-turbo/text-to-image',
    input: ['prompt' => 'A small red fox curled up in a teacup, soft morning light'],
    idempotencyKey: Client::idempotencyKey(),
    waitSeconds: 60,                          // short jobs come back finished
);
if (!Client::isTerminal($task)) {
    $task = $client->waitForTerminal($task['taskId']);
}

foreach (Client::readyAssets($task) as $asset) {
    echo $asset['url'], "\n";                 // signed link, valid about 20 minutes
}
```

Every call returns the decoded `data` of the response envelope as a plain associative array, so read
it with `$task['state']`, not `$task->state`. A fast image model like this costs a couple of credits
(1 credit = $0.01).

## Features

| Area | Methods | Endpoint |
| --- | --- | --- |
| Models | `listModels()`, `getModel()` | `GET /api/v1/models`, `GET /api/v1/models/{model}` |
| Quotes | `quote()` | `POST /api/v1/jobs/quote` |
| Tasks | `createTask()`, `getTask()`, `waitForTerminal()`, `retryTask()`, `purgeTask()` | `POST /api/v1/jobs/createTask`, `GET /api/v1/jobs/recordInfo`, `POST /api/v1/jobs/retry`, `POST /api/v1/jobs/purge` |
| History | `listTasks()`, `iterTasks()` | `GET /api/v1/jobs` |
| Account | `getBalance()`, `getUsage()` | `GET /api/v1/chat/credit`, `GET /api/v1/usage` |
| Uploads | `uploadFile()`, `uploadBytes()`, `createUploadUrl()`, `commitUploadedFile()` | `POST /api/v1/common/upload-url`, `POST /api/v1/files/{id}/commit` |
| LoRA import | `importLoraFile()` | `POST /api/v1/files/import` |
| Downloads | `createDownloadUrl()` | `POST /api/v1/common/download-url` |
| Asset library | `saveAsset()`, `listAssets()`, `iterAssets()`, `getAsset()`, `updateAsset()`, `deleteAsset()`, `getAssetUsage()` | `/api/v1/assets*` |
| Characters | `listCharacters()`, `getCharacter()` | `GET /api/v1/assets/entities*` |
| Webhooks | `Webhook::verifyRequest()`, `EndpointWebhook::verifyRequest()` | task callbacks, account endpoints |

### Supported models

Everything in the live catalog: video (Kling, Seedance, Wan, Vidu, MiniMax, LTX, HunyuanVideo), images (Nano Banana, GPT Image, Flux, Seedream, Qwen Image, Krea), music and audio (Suno, MiniMax Music), speech and transcription, plus PopNow tools such as background removal and upscaling. Model IDs look like `<publisher>/<model>/<task>`; list them with `listModels()` or browse [popnow.ai/docs/models](https://popnow.ai/docs/models). Build each request's `input` from that model's `inputSchema`:

```php
$model = $client->getModel('alibaba/wan-3.0/image-to-video');
print_r(array_keys($model['inputSchema']['properties'] ?? []));
```

## Usage

### Quote first, then pay exactly that

```php
$input = ['prompt' => 'A lantern drifting through fog'];
$quote = $client->quote('alibaba/z-image-turbo/text-to-image', $input);
printf("at most %d credits\n", $quote['maxCredits']);

$task = $client->createTask(
    model: $quote['model'],
    input: $input,
    idempotencyKey: Client::idempotencyKey(),
    quoteId: $quote['quoteId'],
    expectedCredits: $quote['maxCredits'],   // a changed price fails with 40901 instead of charging more
);
```

A quote is valid for five minutes and reserves nothing. `createTask()` holds `maxCredits`; the final
charge is never higher, and a failed or expired task releases the hold.

### Image to video from a local file

```php
$uploaded = $client->uploadFile('/path/to/photo.png');    // popnow://f/fil_...
$task = $client->createTask(
    model: 'alibaba/wan-3.0/image-to-video',
    input: ['image_url' => $uploaded['uri'], 'prompt' => 'Slow dolly in, soft wind'],
    idempotencyKey: 'order-1234-video',                     // stable per submission
);
$final = $client->waitForTerminal($task['taskId'], timeoutSeconds: 900);
```

The upload streams the file straight to storage: the ticket's signed headers are forwarded exactly,
the API key never goes to the storage host, and nothing is loaded into memory with ext-curl. JPEG,
PNG, WebP, GIF (10 MiB), MP4/WebM video, MP3/WAV audio and documents (90 MiB) are accepted; the type
is inferred from the extension, or pass `UploadContentType::Mp4` and friends explicitly.

`waitSeconds` holds the connection open for up to 60 seconds. **It does not promise a finished
task**: when the budget runs out you get the ordinary accepted response, so always branch on
`Client::isTerminal()`. A `TimeoutError` from `waitForTerminal()` means only that the local wait
ended; the task keeps running and its `taskId` is on the exception.

### LoRA weights

```php
use PopNow\UploadContentType;

$weights = $client->uploadFile('/path/to/style.safetensors', UploadContentType::LoraWeights);
// or import from Hugging Face / Civitai without downloading it yourself:
$weights = $client->importLoraFile('your-name/your-lora-repo', token: getenv('HF_TOKEN') ?: null);

$task = $client->createTask(
    model: 'alibaba/qwen-image-2.1-lora/text-to-image',
    input: ['prompt' => 'A cozy reading nook', 'loras' => [['path' => $weights['uri'], 'scale' => 1]]],
    idempotencyKey: Client::idempotencyKey(),
);
```

### Asset library

Task outputs expire; saved assets stay until you delete them and get a stable URI that works in any
matching media field:

```php
$asset = $client->saveAsset(
    source: 'task_output',
    idempotencyKey: 'save-' . $final['taskId'] . '-0',
    taskId: $final['taskId'],
    index: 0,
    name: 'Hero shot',
);
echo $asset['uri'], "\n";                                   // popnow://a/ast_...

foreach ($client->iterAssets(kind: 'image', favorite: true) as $saved) {
    echo $saved['name'], ' ', $saved['uri'], "\n";
}
$client->updateAsset($asset['id'], favorite: true);
print_r($client->getAssetUsage()['usage']);
```

### Webhooks

Pass `callBackUrl: 'https://your-app.example.com/hooks/popnow'` to `createTask()` and verify what
arrives before acting on it:

```php
use PopNow\Webhook;
use PopNow\WebhookError;

try {
    $delivery = Webhook::verifyRequest(getenv('POPNOW_WEBHOOK_SECRET') ?: '');
} catch (WebhookError $error) {
    http_response_code(401);
    exit;
}
http_response_code(200);                                    // acknowledge within 15 seconds
$record = $delivery->taskRecord();                          // same shape as getTask()
// Deliveries are retried: deduplicate on $delivery->deliveryId.
```

Inside a framework that already read the body, pass the exact bytes:
`Webhook::verifyRequest(secret: $secret, server: $_SERVER, rawBody: $request->getContent())`.
Account endpoints registered in the console use Standard Webhooks headers and their own `whsec_`
secret; verify them with `EndpointWebhook::verifyRequest($endpointSecret)`, which returns the event
`type()`, `webhookId` and `taskRecord()`. See [`examples/webhook-receiver.php`](examples/webhook-receiver.php).

Three things this does for you: it hashes the raw body (a re-encoded body never matches), compares
with `hash_equals` (never `==`, which treats `"0e123" == "0e456"` as true), and rejects timestamps
outside a five-minute window.

### Balance, usage and history

```php
$balance = $client->getBalance();                           // availableCredits, heldCredits, ...
$usage = $client->getUsage('2026-10-01', '2026-10-08');     // settled spend over [from, to)

foreach ($client->iterTasks(state: 'succeeded') as $summary) {
    echo $summary['taskId'], "\n";                          // every page, filters repeated for you
}
```

Credits are integers; USD amounts are decimal strings, compare them with `Client::compareUsd()`.
`getUsage()` is for reconciliation (30 requests per minute per account), not for progress; follow a
task with `getTask()`. History and usage cover this API key only.

## Configuration

| Option | Default | Meaning |
| --- | --- | --- |
| `apiKey` | `POPNOW_API_KEY` env | The key. Prefer the environment; `$_SERVER` is checked too for php-fpm |
| `baseUrl` | `https://api.popnow.ai/api/v1` | HTTPS only (plain HTTP allowed for loopback stubs) |
| `language` | none | `Accept-Language` for `msg` / `errorMessage` (`en`, `de`, `ja`, `pt-BR`, ...) |
| `requestTimeoutSeconds` | `30` | Ceiling per API call, retries included |
| `uploadTimeoutSeconds` | `600` | Ceiling for the storage PUT (LoRA weights get two hours) |
| `maxRetries` | `2` | Automatic retries for calls that are safe to repeat |
| `maxRetryDelaySeconds` | `30` | A longer `Retry-After` is returned to you instead of slept through |
| `transport` | cURL, else streams | Any `PopNow\Transport`, for proxies, tracing or tests |

Every request carries `User-Agent: popnow-php/<version> (php/<version>; +https://popnow.ai)`.

## Errors

Failures throw `ApiError` carrying `$status`, `$businessCode`, `$requestId`, `$retryAfterSeconds` and `$reason`.
`TransportError` (no response), `TimeoutError` (local deadline, with `$taskId` when waiting) and
`UploadError` (storage PUT) extend it. Branch on the business code, never on the message;
`$error->guidance()` says what to do and `$error->describe()` is a log-safe line.

| Code | Meaning | What to do |
| --- | --- | --- |
| `401` | missing, unknown or revoked key | create a key in the API console |
| `40003` | uploaded bytes do not match their ticket | upload again with a fresh ticket |
| `40004` | the model cannot serve this parameter combination | change the parameter named in `msg` |
| `40201` | not enough credits for the hold | [buy credits](https://popnow.ai/pricing), then resend |
| `40310` | the account email is not verified | open the verification link; the same key then works |
| `409` | the idempotency key was reused for a different request | resend the original, or use a new key |
| `40901` | the quote expired or the price moved | quote again, keep the same idempotency key |
| `503` | a dependency is briefly unavailable | back off by `Retry-After` (retried automatically) |
| `50301` | the model cannot take tasks right now | retry later or pick another model |
| `50302` | a synchronous generation failed and was refunded | resend with a **new** idempotency key |

Asset library limits add `$reason` (`asset_library_locked`, `asset_library_full`,
`asset_file_too_large`, `asset_daily_limit`, ...). A task that fails after acceptance is not an
exception: it has `state: failed` and an `errorCode`; read it with `TaskErrorCode::fromTask($task)`
and `->isWorthRetrying()`.

## FAQ

### How do I generate a video from an image with an API in PHP?

Upload the image with `$client->uploadFile()`, pass the returned `popnow://f/...` URI as `image_url`
to an image-to-video model such as `alibaba/wan-3.0/image-to-video` or a Kling or Seedance model,
then call `waitForTerminal()` or receive a webhook. Public HTTPS image URLs work without uploading.

### How do I install the PopNow PHP SDK without Packagist?

Add the GitHub repository as a Composer VCS repository
(`"repositories":[{"type":"vcs","url":"https://github.com/popnow-ai/popnow-php"}]`) and require
`popnow/popnow`. Without Composer, clone the repository and `require 'autoload.php'`.

### Which AI models can I call from PHP?

Every image, video and audio model in the PopNow catalog, including Kling, Seedance, Wan, Vidu,
MiniMax, Nano Banana, GPT Image, Flux and Suno. `listModels()` returns the live list with each
model's price in credits.

### How much does a generation cost, and am I charged for failures?

Each model has a credit price (1 credit = $0.01), shown by `quote()` before you spend anything.
Credits come in packs with no subscription. Failed and expired tasks release their hold, so they
cost nothing.

### Is it safe to retry `createTask()` after a timeout?

Yes, if you pass an `idempotencyKey`: the server returns the original task instead of creating a
second one, and the client retries keyed submissions automatically. Without a key the client never
resends, because a lost response does not prove the task was not created.

### How do I verify PopNow webhook signatures in PHP?

`Webhook::verifyRequest(getenv('POPNOW_WEBHOOK_SECRET'))` checks
`base64(HMAC-SHA256(secret, "taskId.timestamp.sha256hex(body)"))` in constant time and enforces a
five-minute timestamp window. Account endpoints use Standard Webhooks; use `EndpointWebhook`.

### Can I use PopNow from Laravel or Symfony?

Yes. Create one `PopNow\Client` (for example as a container singleton) with the key from your config,
and pass `$request->getContent()` as `rawBody` when verifying webhooks. The client is framework-free.

## Links

- Website: [popnow.ai](https://popnow.ai)
- Docs: [popnow.ai/docs](https://popnow.ai/docs)
- API reference (OpenAPI): [popnow.ai/docs/openapi.yaml](https://popnow.ai/docs/openapi.yaml), pinned here in [`contracts/openapi.yaml`](contracts/openapi.yaml)
- Pricing: [popnow.ai/pricing](https://popnow.ai/pricing)
- MCP server: [popnow.ai/mcp](https://popnow.ai/mcp)
- llms.txt: [popnow.ai/llms.txt](https://popnow.ai/llms.txt)
- Other PopNow repos: [popnow-python](https://github.com/popnow-ai/popnow-python), [popnow-js](https://github.com/popnow-ai/popnow-js), [popnow-go](https://github.com/popnow-ai/popnow-go), [popnow-cli](https://github.com/popnow-ai/popnow-cli), [popnow-api](https://github.com/popnow-ai/popnow-api), [popnow-mcp](https://github.com/popnow-ai/popnow-mcp), [popnow-skills](https://github.com/popnow-ai/popnow-skills), [popnow-comfyui](https://github.com/popnow-ai/popnow-comfyui), [n8n-nodes-popnow](https://github.com/popnow-ai/n8n-nodes-popnow), [popnow-blender](https://github.com/popnow-ai/popnow-blender)

## Contributing

Issues and pull requests are welcome; see [CONTRIBUTING.md](CONTRIBUTING.md). To report a
vulnerability, see [SECURITY.md](SECURITY.md).

## License

[MIT](LICENSE) © PopNow
