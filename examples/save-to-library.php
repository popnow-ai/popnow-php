<?php

/**
 * Keep a generated file: save a finished task's first output to the asset library and reuse it.
 *
 * Usage:
 *   export POPNOW_API_KEY="sk-popnow-..."
 *   php examples/save-to-library.php <taskId>
 *
 * Task outputs expire with the task's retention; a saved asset stays until you delete it and gets a
 * stable popnow://a/ast_... URI that works in any matching media field of any model.
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use PopNow\ApiError;
use PopNow\Client;

$taskId = $argv[1] ?? '';
if ($taskId === '') {
    fwrite(STDERR, "Usage: php examples/save-to-library.php <taskId>\n");
    exit(2);
}

try {
    $client = new Client();

    // A stable key derived from the source: saving the same output twice returns the same asset.
    $asset = $client->saveAsset(
        source: 'task_output',
        idempotencyKey: 'save-' . $taskId . '-0',
        taskId: $taskId,
        index: 0,
        name: 'Saved from ' . $taskId,
    );
    printf("saved %s as %s (%s)\n", $asset['name'], $asset['uri'], $asset['kind']);

    $usage = $client->getAssetUsage();
    printf("library: %d items, plan %s\n", $usage['usage']['count'] ?? 0, $usage['tier']['name'] ?? '?');
} catch (ApiError $error) {
    // Library limits come with a reason, e.g. asset_library_locked before the first credit purchase.
    fwrite(STDERR, $error->describe() . "\n" . $error->guidance() . "\n");
    exit(1);
}
