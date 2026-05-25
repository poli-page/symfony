<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

// Load repo-root .env so integration tests can pick up POLI_PAGE_API_KEY
// without requiring the developer to export it in their shell. Real env
// vars take precedence (12-factor), so CI overrides still win.
$envFile = dirname(__DIR__).'/.env';
if (is_file($envFile)) {
    $lines = file($envFile, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
    foreach (false === $lines ? [] : $lines as $line) {
        $trimmed = ltrim($line);
        if ('' === $trimmed || str_starts_with($trimmed, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'");
        if (false === getenv($key)) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
