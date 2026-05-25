<?php

declare(strict_types=1);

// Pull POLI_PAGE_API_KEY (and friends) from the bundle repo's root .env so
// the example app works without a per-app .env.local copy. Real env vars
// take precedence (12-factor), so a shell export still wins.
//
// Why: this runs BEFORE Symfony Runtime's autoload_runtime.php. We must not
// require vendor/autoload.php here — the runtime decides "first call vs
// re-include" by whether autoload.php is already loaded; pre-loading it
// makes the runtime skip itself and the kernel never boots.
$rootEnv = dirname(__DIR__).'/.env';
if (is_file($rootEnv)) {
    $lines = file($rootEnv, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
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
