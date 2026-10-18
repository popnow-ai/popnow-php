# Changelog

All notable changes to `popnow/popnow` are listed here. The version is the git tag; `composer.json`
carries no version field, and `PopNow\Client::VERSION` (sent in the `User-Agent`) must equal the
newest heading below.

## 0.1.0

- First release of the PopNow PHP SDK (`popnow/popnow`, namespace `PopNow`, PHP 8.1+, no library
  dependencies).
- Tasks: `quote()`, `createTask()` (with `Idempotency-Key`, `expectedCredits` / `expectedCost`,
  `X-PopNow-Retention` and `wait`), `getTask()`, `waitForTerminal()`, `retryTask()`, `purgeTask()`,
  `listTasks()` and `iterTasks()`.
- Catalog and account: `listModels()`, `getModel()`, `getBalance()`, `getUsage()`.
- Files: the three-step upload (`uploadFile()`, `uploadBytes()`, or `createUploadUrl()` +
  `commitUploadedFile()`), including `.safetensors` LoRA weights up to 2 GiB, `importLoraFile()`
  and `createDownloadUrl()`.
- Asset library: `saveAsset()`, `listAssets()`, `iterAssets()`, `getAsset()`, `updateAsset()`,
  `deleteAsset()`, `getAssetUsage()`, `listCharacters()`, `getCharacter()`.
- Webhooks: `Webhook` verifies task callbacks (`X-Webhook-Signature`), `EndpointWebhook` verifies
  account endpoint events (Standard Webhooks).
- Automatic retries only where a resend is safe (reads, quotes, keyed submissions), honouring
  `Retry-After`; `ApiError` carries the business code, `request_id`, `Retry-After` and `data.reason`.
- An explicit `User-Agent: popnow-php/<version>` on every request.
