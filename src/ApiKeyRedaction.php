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
     * Why: a key the HTTP layer rejects (e.g. one ending in a newline) comes
     * back in the error message with the whole "Bearer <key>" header value.
     * Guzzle trims " \t" from the value first, so the trimmed key is redacted too.
     */
    public static function apply(string $apiKey, string $message): string
    {
        // str_replace skips an empty search (a key made only of spaces or tabs).
        return str_replace([$apiKey, rtrim($apiKey, " \t")], '[redacted API key]', $message);
    }
}
