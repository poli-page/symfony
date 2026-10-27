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
 * through `poli_page.logger` when the client is built.
 */
final class PoliPageClientFactoryTest extends TestCase
{
    use RestoresGlobalHandlers;

    private const SECRET = '0123456789abcdef0123456789abcdef';

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
     * @return iterable<string, array{0: string, 1: string}> key, secret part that must not be logged
     */
    public static function malformedKeyProvider(): iterable
    {
        yield 'short secret' => ['pp_sandbox_x', 'pp_sandbox_x'];
        yield 'uppercase environment' => ['pp_Sandbox_'.self::SECRET, self::SECRET];
        yield 'environment starting with a digit' => ['pp_2fa_'.self::SECRET, self::SECRET];
        yield 'double hyphen in environment' => ['pp_a--b_'.self::SECRET, self::SECRET];
        yield '21-character environment' => ['pp_'.str_repeat('a', 21).'_'.self::SECRET, self::SECRET];
        yield 'not a pp_ token' => ['sk_live_'.self::SECRET, self::SECRET];
        yield 'uppercase hex secret' => ['pp_sandbox_'.strtoupper(self::SECRET), strtoupper(self::SECRET)];
        yield '33-character secret' => ['pp_sandbox_'.self::SECRET.'0', self::SECRET];
        yield 'trailing newline' => ['pp_sandbox_'.self::SECRET."\n", self::SECRET];
    }

    #[DataProvider('malformedKeyProvider')]
    public function testMalformedKeyBootsWithExactlyOneWarningWithoutTheSecret(string $key, string $secret): void
    {
        [$client, $logs] = $this->buildClient($key);

        self::assertSame($key, $this->apiKeyOf($client), 'the client is built with the key as given');
        self::assertCount(1, $logs);
        [$level, $message, $context] = $logs[0];
        self::assertSame('warning', $level);
        self::assertStringContainsString('API key', $message);
        $logged = $message.json_encode($context, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($key, $logged);
        self::assertStringNotContainsString($secret, $logged);
    }

    public function testWarningShowsOnlyTheEnvironmentPrefix(): void
    {
        [, $logs] = $this->buildClient('pp_Sandbox_'.self::SECRET);

        self::assertCount(1, $logs);
        self::assertStringContainsString('pp_Sandbox_', $logs[0][1]);
    }

    public function testWarningShowsNoPrefixForANonPpToken(): void
    {
        [, $logs] = $this->buildClient('sk-'.self::SECRET);

        self::assertCount(1, $logs);
        self::assertStringNotContainsString('sk-', $logs[0][1]);
    }

    public function testWarningShowsNoPrefixForA21CharacterEnvironment(): void
    {
        [, $logs] = $this->buildClient('pp_'.str_repeat('a', 21).'_'.self::SECRET);

        self::assertCount(1, $logs);
        self::assertStringNotContainsString('pp_'.str_repeat('a', 21), $logs[0][1]);
        self::assertStringStartsWith('Poli Page API key does not look like', $logs[0][1]);
    }

    public function testWarningShowsThePrefixOfA20CharacterEnvironment(): void
    {
        [, $logs] = $this->buildClient('pp_'.str_repeat('a', 20).'_x');

        self::assertCount(1, $logs);
        self::assertStringContainsString('"pp_'.str_repeat('a', 20).'_…"', $logs[0][1]);
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
    }

    public function testEmptyKeyIsRefusedBySdkWithoutWarning(): void
    {
        $kernel = $this->bootKernel('');
        try {
            $kernel->getContainer()->get(PoliPage::class);
            self::fail('expected the SDK to refuse an empty key');
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::INVALID_OPTIONS, $e->errorCode);
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

    private function bootKernel(string $apiKey): TestKernel
    {
        $kernel = new TestKernel(
            ['api_key' => $apiKey, 'logger' => 'app.test_logger'],
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
