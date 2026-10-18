# Security policy

## Reporting a vulnerability

Please do **not** open a public issue for security problems.

- Open a [private security advisory](https://github.com/popnow-ai/popnow-php/security/advisories/new) on GitHub, or
- email **support@popnow.ai** with "Security" in the subject.

Include the affected version, a description, and steps to reproduce. Never include a live API key; if
you think a key was exposed, revoke it in the [API console](https://popnow.ai/api/console/keys) first.

## Supported versions

Security fixes are released for the latest minor version of `popnow/popnow`.

## Handling secrets

- Keep `POPNOW_API_KEY`, `POPNOW_WEBHOOK_SECRET` and endpoint `whsec_` secrets on your server, in
  environment variables or a secret store. Never ship them to a browser.
- The client never logs the key; `ApiError::describe()` is safe to log.
- Output URLs (`output.assets[].url`) and asset `previewUrl`s are short-lived bearer links; do not
  publish or store them. Store task IDs and `popnow://a/...` asset URIs instead.
- Verify every webhook with `PopNow\Webhook` or `PopNow\EndpointWebhook` before acting on it.
