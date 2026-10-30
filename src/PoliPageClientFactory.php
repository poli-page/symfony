<?php

declare(strict_types=1);

namespace PoliPage\Symfony;

use Closure;
use PoliPage\Events\RetryEvent;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the `poli_page.client` service.
 *
 * @internal
 */
final class PoliPageClientFactory
{
    // The contract's full-key pattern: `{env}` is lowercase, letter first,
    // single inner hyphens, at most 20 characters. Why \z, not $: PHP's $ also
    // matches before a final "\n".
    private const KEY_PATTERN = '/^pp_(?=[a-z][a-z0-9-]{0,19}_)[a-z][a-z0-9]*(?:-[a-z0-9]+)*_[0-9a-f]{32}\z/';

    /**
     * @param (Closure(RetryEvent): void)|null        $onRetry
     * @param (Closure(PoliPageException): void)|null $onError
     */
    public static function create(
        string $apiKey,
        ?string $baseUrl,
        ?int $maxRetries,
        ?float $retryDelay,
        ?float $timeout,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        LoggerInterface $logger,
        ?Closure $onRetry,
        ?Closure $onError,
    ): PoliPage {
        // Why: the API is the only authority on keys (a new environment needs no
        // bundle release), so a malformed key is only flagged, never refused. An
        // empty key is left to the SDK, which refuses it. The warning shows no
        // part of the key: even a well-formed `pp_{env}_` can be a pasted secret.
        if ('' !== $apiKey && 1 !== preg_match(self::KEY_PATTERN, $apiKey)) {
            $logger->warning('Poli Page API key does not match the expected format pp_{environment}_{32 lowercase hex characters}; starting anyway, the API decides whether it is valid.');
        }

        return new PoliPage(
            apiKey: $apiKey,
            // Why: an empty base_url (e.g. POLI_PAGE_BASE_URL= in .env) means
            // "unset"; the SDK only falls back to its default URL on null.
            baseUrl: '' === $baseUrl ? null : $baseUrl,
            maxRetries: $maxRetries,
            retryDelay: $retryDelay,
            timeout: $timeout,
            httpClient: $httpClient,
            requestFactory: $requestFactory,
            streamFactory: $streamFactory,
            logger: $logger,
            onRetry: $onRetry,
            onError: $onError,
        );
    }
}
