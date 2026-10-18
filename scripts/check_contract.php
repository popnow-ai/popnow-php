<?php

/**
 * Contract drift check: the contract facts hard-coded in this package must still match the
 * published PopNow API contract.
 *
 * Why it has to exist: the enums and limits in an SDK are constants copied from the contract. When
 * the contract changes and these do not, nothing raises an error: requests still go out, responses
 * still parse, and some new option is quietly rejected locally as an illegal value.
 *
 * It needs no credentials: the contract is public at https://popnow.ai/docs/openapi.yaml.
 *
 * Usage: php scripts/check_contract.php            compare, exit 1 on drift
 *        php scripts/check_contract.php --update   rewrite contracts/openapi.yaml from the live copy
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/contract_lib.php';

const LIVE_URL = 'https://popnow.ai/docs/openapi.yaml';
const LOCAL = __DIR__ . '/../contracts/openapi.yaml';

/**
 * Retrieves the published contract, minus its generated-header comment lines.
 *
 * An explicit User-Agent is mandatory: the site's edge refuses requests that carry none or a
 * default library one, and without it this check would fail in the name of "contract drift" for a
 * reason that has nothing to do with the contract.
 */
function fetchLive(): string
{
    $context = stream_context_create([
        'http' => [
            'header' => 'User-Agent: popnow-php-contract-check/' . PopNow\Client::VERSION . " (+https://popnow.ai)\r\n",
            'timeout' => 30,
        ],
    ]);
    $body = @file_get_contents(LIVE_URL, false, $context);
    if ($body === false || $body === '') {
        fwrite(STDERR, "[contract] the published contract is unreachable\n");
        exit(1);
    }

    return contract_strip_header($body);
}

$live = fetchLive();

if (in_array('--update', $argv, true)) {
    file_put_contents(LOCAL, $live);
    echo "[contract] contracts/openapi.yaml refreshed; now re-check every constant against the diff.\n";
    exit(0);
}

$local = is_file(LOCAL) ? (string) file_get_contents(LOCAL) : '';
if ($local !== $live) {
    fwrite(STDERR,
        "[contract] contracts/openapi.yaml is stale against " . LIVE_URL . ".\n" .
        "[contract] refresh it:  php scripts/check_contract.php --update\n" .
        "[contract] then re-check every constant this package hard-codes against that diff.\n");
    exit(1);
}

// Each of these is a complete set on both sides, which is what makes a value-by-value comparison
// meaningful; cases() guarantees a newly added member cannot be missed.
$checks = [
    'upload content types' => ['UploadURLRequest', 'contentType', PopNow\UploadContentType::cases()],
    'task states' => ['TaskRecord', 'state', PopNow\TaskState::cases()],
    'task error codes' => ['TaskRecord', 'errorCode', PopNow\TaskErrorCode::cases()],
    'asset sources' => ['AssetCreateRequest', 'source', PopNow\Client::ASSET_SOURCES],
];
$drift = false;
foreach ($checks as $label => [$schema, $field, $cases]) {
    $ours = array_map(static fn ($case): string => $case instanceof BackedEnum ? (string) $case->value : (string) $case, $cases);
    sort($ours);
    $theirs = contract_enum($live, $schema, $field);
    if ($theirs === null) {
        fwrite(STDERR, "[contract] the contract has no enum at {$schema}.{$field}\n");
        $drift = true;
        continue;
    }
    if ($ours !== $theirs) {
        fwrite(STDERR, sprintf(
            "[contract] %s drifted:\n  package:  %s\n  contract: %s\n",
            $label,
            implode(', ', $ours),
            implode(', ', $theirs),
        ));
        $drift = true;
    }
}

if ($drift) {
    exit(1);
}
echo "[contract] matches the published contract.\n";
