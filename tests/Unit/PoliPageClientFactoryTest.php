<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\Symfony\Tests\Fixtures\TestKernel;
use PoliPage\Symfony\Tests\RestoresGlobalHandlers;
use ReflectionClass;
use Symfony\Component\ErrorHandler\BufferingLogger;

/**
 * The bundle accepts any non-empty key and lets the API decide; a key that
 * does not look like `pp_{env}_{32 hex}` only earns one warning, logged
 * through `poli_page.logger` when the client is built, that shows no part
 * of the key.
 */
final class PoliPageClientFactoryTest extends TestCase
{
    use RestoresGlobalHandlers;

    private const SECRET = '0123456789abcdef0123456789abcdef';

    private const WARNING = 'Poli Page API key does not match the expected format pp_{environment}_{32 lowercase hex characters}; starting anyway, the API decides whether it is valid.';

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function wellFormedKeyProvider(): iterable
    {
        yield 'sandbox' => ['pp_sandbox_'.self::SECRET];
        yield 'live' => ['pp_live_'.self::SECRET];
        yield 'hyphenated environment' => ['pp_eu-prod_'.self::SECRET];
        yield '20-character environment' => ['pp_'.str_repeat('a', 20).'_'.self::SECRET];
    }

    #[DataProvider('wellFormedKeyProvider')]
    public function testWellFormedKeyBootsWithoutWarning(string $key): void
    {
        [$client, $logs] = $this->buildClient($key);

        self::assertSame($key, $this->apiKeyOf($client));
        self::assertSame([], $logs);
    }

    /**
     * The warning shows no part of the key, not even a `pp_{env}_` prefix whose
     * `{env}` follows the contract grammar: a mis-pasted secret fragment can
     * follow it too (`pp_abc123_…`).
     *
     * @return iterable<string, array{0: string, 1: string}> key, its prefix that must not be logged either
     */
    public static function malformedKeyProvider(): iterable
    {
        yield 'contract environment, short secret' => ['pp_sandbox_x', 'pp_sandbox_'];
        yield 'contract environment, 31-character secret' => ['pp_live_'.substr(self::SECRET, 1), 'pp_live_'];
        yield 'hex-fragment environment' => ['pp_abc123_'.substr(self::SECRET, 6), 'pp_abc123_'];
        yield 'hyphenated environment, short secret' => ['pp_eu-prod_x', 'pp_eu-prod_'];
        yield '20-character environment, short secret' => ['pp_'.str_repeat('a', 20).'_x', 'pp_'.str_repeat('a', 20).'_'];
        yield 'uppercase environment' => ['pp_Sandbox_'.self::SECRET, 'pp_Sandbox_'];
        yield 'environment starting with a digit' => ['pp_2fa_'.self::SECRET, 'pp_2fa_'];
        yield 'double hyphen in environment' => ['pp_a--b_'.self::SECRET, 'pp_a--b_'];
        yield 'leading hyphen in environment' => ['pp_-eu_'.self::SECRET, 'pp_-eu_'];
        yield 'trailing hyphen in environment' => ['pp_eu-_'.self::SECRET, 'pp_eu-_'];
        yield 'secret-like environment' => ['pp_0123456789abcdef0123_rest', 'pp_0123456789abcdef0123_'];
        yield '21-character environment' => ['pp_'.str_repeat('a', 21).'_'.self::SECRET, 'pp_'.str_repeat('a', 21).'_'];
        yield 'not a pp_ token' => ['sk_live_'.self::SECRET, 'sk_live_'];
        yield 'uppercase hex secret' => ['pp_sandbox_'.strtoupper(self::SECRET), 'pp_sandbox_'];
        yield '33-character secret' => ['pp_sandbox_'.self::SECRET.'0', 'pp_sandbox_'];
        yield 'trailing newline' => ['pp_sandbox_'.self::SECRET."\n", 'pp_sandbox_'];
    }

    #[DataProvider('malformedKeyProvider')]
    public function testMalformedKeyBootsWithExactlyOneWarningShowingNoKeyText(string $key, string $prefix): void
    {
        [$client, $logs] = $this->buildClient($key);

        self::assertSame($key, $this->apiKeyOf($client), 'the client is built with the key as given');
        self::assertCount(1, $logs);
        [$level, $message, $context] = $logs[0];
        self::assertSame('warning', $level);
        self::assertSame(self::WARNING, $message);
        $logged = $message.json_encode($context, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(rtrim($key), $logged);
        self::assertStringNotContainsString($prefix, $logged);
    }

    public function testEnvPlaceholderKeyIsResolvedAndChecked(): void
    {
        $key = 'pp_sandbox_'.self::SECRET;
        $_SERVER['POLI_PAGE_KEY_UNDER_TEST'] = $key;
        try {
            [$client, $logs] = $this->buildClient('%env(POLI_PAGE_KEY_UNDER_TEST)%');
        } finally {
            unset($_SERVER['POLI_PAGE_KEY_UNDER_TEST']);
        }

        self::assertSame($key, $this->apiKeyOf($client));
        self::assertSame([], $logs);
    }

    public function testEnvPlaceholderWithMalformedKeyWarnsAtRuntime(): void
    {
        $_SERVER['POLI_PAGE_KEY_UNDER_TEST'] = 'pp_staging_short';
        try {
            [$client, $logs] = $this->buildClient('%env(POLI_PAGE_KEY_UNDER_TEST)%');
        } finally {
            unset($_SERVER['POLI_PAGE_KEY_UNDER_TEST']);
        }

        self::assertSame('pp_staging_short', $this->apiKeyOf($client));
        self::assertCount(1, $logs);
        self::assertSame(self::WARNING, $logs[0][1]);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function emptyBaseUrlProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'env placeholder resolving to an empty string' => ['%env(POLI_PAGE_BASE_URL_UNDER_TEST)%'];
    }

    #[DataProvider('emptyBaseUrlProvider')]
    public function testEmptyBaseUrlUsesTheSdkDefault(string $baseUrl): void
    {
        $_SERVER['POLI_PAGE_BASE_URL_UNDER_TEST'] = '';
        try {
            $kernel = $this->bootKernel('pp_sandbox_'.self::SECRET, $baseUrl);
            $client = $kernel->getContainer()->get(PoliPage::class);
            self::assertInstanceOf(PoliPage::class, $client);
            $kernel->shutdown();
        } finally {
            unset($_SERVER['POLI_PAGE_BASE_URL_UNDER_TEST']);
        }

        self::assertSame('https://api.poli.page', (new ReflectionClass($client))->getProperty('baseUrl')->getValue($client));
    }

    public function testExplicitBaseUrlIsKept(): void
    {
        $kernel = $this->bootKernel('pp_sandbox_'.self::SECRET, 'https://api.example.test');
        $client = $kernel->getContainer()->get(PoliPage::class);
        self::assertInstanceOf(PoliPage::class, $client);
        $kernel->shutdown();

        self::assertSame('https://api.example.test', (new ReflectionClass($client))->getProperty('baseUrl')->getValue($client));
    }

    public function testEmptyKeyIsRefusedBySdkWithoutWarning(): void
    {
        $kernel = $this->bootKernel('');
        try {
            $kernel->getContainer()->get(PoliPage::class);
            self::fail('expected the SDK to refuse an empty key');
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::INVALID_OPTIONS, $e->errorCode);
            self::assertStringNotContainsString('pp_', $e->getMessage());
        } finally {
            $logger = $kernel->getContainer()->get('app.test_logger');
            self::assertInstanceOf(BufferingLogger::class, $logger);
            self::assertSame([], $logger->cleanLogs());
            $kernel->shutdown();
        }
    }

    /**
     * @return array{0: PoliPage, 1: list<array{0: mixed, 1: string, 2: array<array-key, mixed>}>}
     */
    private function buildClient(string $apiKey): array
    {
        $kernel = $this->bootKernel($apiKey);
        $container = $kernel->getContainer();

        $client = $container->get(PoliPage::class);
        self::assertInstanceOf(PoliPage::class, $client);
        // A second lookup reuses the shared client and must not warn again.
        $container->get(PoliPage::class);

        $logger = $container->get('app.test_logger');
        self::assertInstanceOf(BufferingLogger::class, $logger);
        /** @var list<array{0: mixed, 1: string, 2: array<array-key, mixed>}> $logs */
        $logs = $logger->cleanLogs();
        $kernel->shutdown();

        return [$client, $logs];
    }

    private function bootKernel(string $apiKey, ?string $baseUrl = null): TestKernel
    {
        $kernel = new TestKernel(
            ['api_key' => $apiKey, 'base_url' => $baseUrl, 'logger' => 'app.test_logger'],
            ['app.test_logger' => BufferingLogger::class],
        );
        $kernel->boot();

        return $kernel;
    }

    private function apiKeyOf(PoliPage $client): mixed
    {
        return (new ReflectionClass($client))->getProperty('apiKey')->getValue($client);
    }
}
