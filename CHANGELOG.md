# Changelog

All notable changes to `popnow/popnow` are listed here. The version is the git tag; `composer.json`
carries no version field, and `PopNow\Client::VERSION` (sent in the `User-Agent`) must equal the
newest heading below.

## 0.2.0 - 2026-11-01

The API bills in exact USD from its own API balance, separate from website credits. This release
moves the SDK, its docs and its examples to that contract. Nothing is removed: code written for
0.1.0 keeps working.

- `createTask()` takes `expectedCostUsd`, the exact USD hold to confirm: send the quote's
  `maxChargeUsd` together with its `quoteId` (or `quoteId` alone). It is validated locally as a
  decimal string with up to nine decimal places and no exponent, and it must equal `expectedCost`
  when both are passed. It is the last parameter, so positional calls are unaffected.
- `expectedCredits` is deprecated (an integer projection that cannot express fractional-cent
  prices) and `expectedCost` is documented as the legacy name of `expectedCostUsd`. Both are still
  sent exactly as before.
- Docblocks describe the USD response fields: `maxChargeUsd` and `expectedChargeUsd` on quotes,
  `estimatedCostUsd` on acceptance, `costUsd` on task records, `availableUsd`, `heldUsd` and
  `totalUsd` on the balance, the `usd` rate snapshot on models, and `totalSpend` / `spend` on usage.
  The credit fields (`maxCredits`, `expectedCredits`, `estimatedCredits`, `credits`,
  `exactCredits`, `availableCredits`, `heldCredits`, `totalCredits`, model `credits`) are documented
  as deprecated; they still arrive and still parse.
- `ApiError::guidance()` for `40201` points to API billing (https://popnow.ai/api/console/billing).
- README, `llms.txt` and the examples quote, confirm and report in USD, and link the developer MCP
  server (https://popnow.ai/api/mcp, endpoint `https://api.popnow.ai/api/v1/mcp`, repository
  `popnow-ai/popnow-api-mcp`), developer skills (https://popnow.ai/api/skills) and API pricing
  (https://popnow.ai/api/pricing).
- The pinned contract is refreshed. `scripts/check_contract.php` leaves out the model identifier
  lines that name a word the repository hygiene scan forbids, on both sides of the comparison; the
  vendor-name digests moved to `scripts/contract_lib.php` so the scan and the filter share them.
- Earlier: docs and the pinned contract described credits in the unit in force on 2026-10-19. No
  code change: the SDK reads credit amounts as the API returns them.

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
