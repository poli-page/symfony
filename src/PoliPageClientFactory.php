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
    private const KEY_PATTERN = '/^pp_(?=[a-z][a-z0-9-]{0,19}_)[a-z][a-z0-9]*(?:-[a-z0-9]+)*_[0-9a-f]{32}$/';

    private const ENVIRONMENT_PREFIX = '/^pp_[A-Za-z0-9-]{1,20}_/';

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
        // empty key is left to the SDK, which refuses it.
        if ('' !== $apiKey && 1 !== preg_match(self::KEY_PATTERN, $apiKey)) {
            $logger->warning(\sprintf(
                'Poli Page API key %sdoes not look like pp_{environment}_{32 hex characters}; starting anyway, the API decides whether it is valid.',
                1 === preg_match(self::ENVIRONMENT_PREFIX, $apiKey, $prefix) ? \sprintf('"%s…" ', $prefix[0]) : '',
            ));
        }

        return new PoliPage(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
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
