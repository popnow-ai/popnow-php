<?php

/**
 * Minimal end-to-end run against a real model: schema, optional upload, quote,
 * create, wait, read the result.
 *
 * Usage:
 *   export POPNOW_API_KEY="sk-popnow-..."
 *   php examples/generate.php alibaba/z-image-turbo/text-to-image "A small red fox in a teacup"
 *   php examples/generate.php alibaba/wan-3.0/image-to-video "Slow dolly in" path/to/photo.png
 *
 * Pick <model-id> from the live catalog (`listModels()` or https://popnow.ai/docs/models),
 * never from a hard-coded list:
 *
 *   php -r 'require "autoload.php";
 *     foreach ((new PopNow\Client())->listModels(modality: "image")["items"] as $m) {
 *         echo $m["model"], PHP_EOL;
 *     }'
 *
 * The optional third argument demonstrates the three-step upload: the file is
 * PUT straight to storage, and the committed `popnow://f/fil_...` URI (not a
 * local path) is what goes into the model input.
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use PopNow\ApiError;
use PopNow\Client;
use PopNow\TimeoutError;
use PopNow\TaskErrorCode;
use PopNow\TaskState;

$model = $argv[1] ?? '';
$prompt = $argv[2] ?? '';
$imagePath = $argv[3] ?? null;
if ($model === '' || $prompt === '') {
    fwrite(STDERR, "Usage: php examples/generate.php <model-id> \"<prompt>\" [reference-image]\n");
    exit(2);
}

try {
    $client = new Client();

    // Read the schema first: field names and accepted values come from it, not from an example
    // copied elsewhere.
    $schema = $client->getModel($model);
    printf("model: %s\n", $schema['model'] ?? $model);

    $input = ['prompt' => $prompt];
    if ($imagePath !== null) {
        $file = $client->uploadFile($imagePath);
        printf("uploaded: %s (%s, %d bytes)\n", $file['uri'], $file['contentType'], $file['bytes']);
        // The field name comes from this model's inputSchema; `image_url` is the most common one
        // in the catalog.
        $input['image_url'] = $file['uri'];
    }

    // Quote first, then create the task against that same quote: a changed price is stopped with
    // a 40901 before anything is charged, rather than quietly settling at the new one.
    $quote = $client->quote($model, $input);
    printf("quote: up to %d credits (usually %d), expires %s\n",
        $quote['maxCredits'], $quote['expectedCredits'], $quote['expiresAt']);

    $accepted = $client->createTask(
        model: $model,
        input: $input,
        // In a real system, derive this from a stable identifier you already have (an order
        // number, a row id) rather than randomising each time: a fresh key on retry is the same as
        // having no idempotency at all.
        idempotencyKey: Client::idempotencyKey(),
        quoteId: $quote['quoteId'],
        expectedCredits: $quote['maxCredits'],
    );
    printf("task: %s (%d credits held)\n", $accepted['taskId'], $accepted['estimatedCredits']);

    $task = $client->waitForTerminal(
        $accepted['taskId'],
        onUpdate: static function (array $update): void {
            printf("  ... %s\n", $update['state'] ?? '?');
        },
    );

    $state = TaskState::tryFrom((string) ($task['state'] ?? ''));
    printf("state: %s, %s credits (settled: %s)\n",
        $task['state'] ?? '?', $task['credits'] ?? '?', ($task['settled'] ?? false) ? 'yes' : 'no');

    if ($state !== TaskState::Succeeded) {
        $code = TaskErrorCode::fromTask($task);
        printf("failed: %s - %s\n", $code?->value ?? 'unknown', $task['errorMessage'] ?? '');
        // Always branch on errorCode, never errorMessage: the latter is translated and may pass on
        // the model's own reason verbatim.
        printf("worth retrying: %s\n", ($code?->isWorthRetrying() ?? false) ? 'yes' : 'no');
        exit(1);
    }

    // The output is not always a file: some endpoints answer in output.text and carry no assets
    // key at all.
    $text = Client::outputText($task);
    if ($text !== null) {
        printf("text output:\n%s\n", $text);
    }
    foreach (Client::readyAssets($task) as $index => $asset) {
        printf("asset %d: %s (%s, expires %s)\n",
            $index, $asset['url'], $asset['mime'] ?? '?', $asset['expiresAt'] ?? '?');
    }
    if ($text === null && Client::readyAssets($task) === []) {
        print("the task succeeded but produced no ready output\n");
    }
    exit(0);
} catch (TimeoutError $error) {
    // A local wait timing out does not mean the generation failed: the task is most likely still
    // running, and still being billed.
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} catch (ApiError $error) {
    fwrite(STDERR, $error->describe() . "\n" . $error->guidance() . "\n");
    exit(1);
}
