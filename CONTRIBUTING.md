# Contributing

Thanks for helping improve the PopNow PHP SDK.

## Development setup

```bash
git clone https://github.com/popnow-ai/popnow-php
cd popnow-php
php tests/run.php              # unit + loopback suites, no network, no API key
php scripts/check_contract.php # compares the pinned contract with https://popnow.ai/docs/openapi.yaml
```

No `composer install` is needed: the package has no dependencies, and the tests load it through
`autoload.php`. `tests/unit.php` uses a fake transport; `tests/loopback.php` starts PHP's built-in
server on a random local port so requests really cross a socket.

## Guidelines

- PHP 8.1+ only syntax, no runtime dependencies beyond ext-json and ext-hash (ext-curl optional).
- Follow the API contract in `contracts/openapi.yaml`; do not add endpoints or fields that are not
  documented. When the contract changes, run `php scripts/check_contract.php --update` and review
  the diff against every constant the package hard-codes.
- Every new method needs a test in `tests/unit.php`, and README examples must still parse
  (the suite lints every `php` block).
- Bump `Client::VERSION` together with a new `CHANGELOG.md` heading; the suite checks they agree.
- Write code, comments, docs and commit messages in English. Never commit API keys, webhook secrets
  or real output links.

## Pull requests

1. Fork the repository and create a branch.
2. Add or update tests and run `php tests/run.php`.
3. Describe the change and link any related issue.
