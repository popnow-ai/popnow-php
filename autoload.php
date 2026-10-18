<?php

/**
 * Autoloader for using this package without Composer.
 *
 *   require_once '/path/to/popnow-php/autoload.php';
 *   $client = new PopNow\Client();
 *
 * With Composer, ignore this file: `vendor/autoload.php` already maps the
 * `PopNow\` namespace to `src/` (PSR-4).
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'PopNow\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    // A class name never contains a path separator or a dot; refuse anything that does, so a
    // crafted class string cannot point the loader at another file.
    if ($relative === '' || preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative) !== 1) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
