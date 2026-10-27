<?php

declare(strict_types=1);

namespace PoliPage\Symfony;

/**
 * Keeps the API key out of error text shown to a user.
 *
 * @internal
 */
final class ApiKeyRedaction
{
    /**
     * Why: a transport or PSR-7 error about the Authorization header can hold
     * the whole "Bearer <key>" value (poli-page/sdk before 0.10.0 let a key
     * ending in a newline reach the HTTP layer, whose message echoes it).
     * Guzzle trims " \t" from the value first, so the trimmed key is redacted too.
     */
    public static function apply(string $apiKey, string $message): string
    {
        // str_replace skips an empty search (a key made only of spaces or tabs).
        return str_replace([$apiKey, rtrim($apiKey, " \t")], '[redacted API key]', $message);
    }
}
