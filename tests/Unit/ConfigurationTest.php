<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoliPage\Symfony\Tests\Fixtures\TestKernel;
use PoliPage\Symfony\Tests\RestoresGlobalHandlers;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Exercises the bundle's config tree by booting a TestKernel with various
 * YAML-equivalent inputs and asserting either resolved container parameters
 * or InvalidConfigurationException with the right message.
 *
 * Why: the plan's original DefinitionConfigurator reflection-based approach
 * is brittle across Symfony 6.4 / 7.x minor versions (constructor signature
 * differs). Booting the kernel exercises the same tree through the same
 * code path users will hit.
 */
final class ConfigurationTest extends TestCase
{
    use RestoresGlobalHandlers;

    public function testMinimalConfigOnlyApiKey(): void
    {
        $kernel = new TestKernel(['api_key' => TestKernel::API_KEY]);
        $kernel->boot();
        $c = $kernel->getContainer();

        self::assertSame(TestKernel::API_KEY, $c->getParameter('poli_page.api_key'));
        self::assertNull($c->getParameter('poli_page.base_url'));
        self::assertNull($c->getParameter('poli_page.timeout'));
        self::assertNull($c->getParameter('poli_page.retries.max_retries'));
        self::assertNull($c->getParameter('poli_page.retries.delay_seconds'));
        self::assertNull($c->getParameter('poli_page.on_retry'));
        self::assertNull($c->getParameter('poli_page.on_error'));

        $kernel->shutdown();
    }

    public function testFullConfigRoundtrips(): void
    {
        $input = [
            'api_key' => TestKernel::API_KEY,
            'base_url' => 'https://api.example.com',
            'timeout' => 45.0,
            'retries' => ['max_retries' => 5, 'delay_seconds' => 0.1],
            'logger' => 'logger',
            'on_retry' => 'app.retry_listener',
            'on_error' => 'app.error_listener',
        ];
        $kernel = new TestKernel($input, [
            'app.retry_listener' => null,
            'app.error_listener' => null,
        ]);
        $kernel->boot();
        $c = $kernel->getContainer();

        self::assertSame(TestKernel::API_KEY, $c->getParameter('poli_page.api_key'));
        self::assertSame('https://api.example.com', $c->getParameter('poli_page.base_url'));
        self::assertSame(45.0, $c->getParameter('poli_page.timeout'));
        self::assertSame(5, $c->getParameter('poli_page.retries.max_retries'));
        self::assertSame(0.1, $c->getParameter('poli_page.retries.delay_seconds'));
        self::assertSame('app.retry_listener', $c->getParameter('poli_page.on_retry'));
        self::assertSame('app.error_listener', $c->getParameter('poli_page.on_error'));

        $kernel->shutdown();
    }

    public function testApiKeyIsRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/api_key/');

        (new TestKernel([]))->boot();
    }

    /**
     * @return iterable<string, array{0: float|int}>
     */
    public static function invalidTimeoutProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'too large' => [601];
    }

    #[DataProvider('invalidTimeoutProvider')]
    public function testTimeoutOutOfRangeRejected(float|int $value): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/timeout/');

        (new TestKernel(['api_key' => TestKernel::API_KEY, 'timeout' => $value]))->boot();
    }

    public function testRetriesMaxRetriesTooHigh(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/max_retries/');

        (new TestKernel(['api_key' => TestKernel::API_KEY, 'retries' => ['max_retries' => 11]]))->boot();
    }

    public function testRetriesMaxRetriesNegative(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/max_retries/');

        (new TestKernel(['api_key' => TestKernel::API_KEY, 'retries' => ['max_retries' => -1]]))->boot();
    }

    public function testLegacyContainerParameterHoldsTheSameValue(): void
    {
        $kernel = new TestKernel(['api_key' => TestKernel::API_KEY, 'retries' => ['max_retries' => 4]]);
        $kernel->boot();
        $c = $kernel->getContainer();

        self::assertSame(4, $c->getParameter('poli_page.retries.max_retries'));
        self::assertSame(4, $c->getParameter('poli_page.retries.max_attempts'));

        $kernel->shutdown();
    }

    public function testMaxRetriesDoesNotRaiseADeprecation(): void
    {
        $deprecations = $this->bootCollectingDeprecations(['max_retries' => 3]);

        self::assertSame([], $deprecations);
    }

    public function testMaxAttemptsAliasFillsMaxRetriesAndRaisesADeprecation(): void
    {
        $kernel = new TestKernel(['api_key' => TestKernel::API_KEY, 'retries' => ['max_attempts' => 3]]);
        $deprecations = $this->collectDeprecations(static fn () => $kernel->boot());

        self::assertSame(3, $kernel->getContainer()->getParameter('poli_page.retries.max_retries'));
        self::assertCount(1, $deprecations);
        self::assertStringContainsString('retries.max_retries', $deprecations[0]);

        $kernel->shutdown();
    }

    public function testMaxAttemptsAliasIsRangeChecked(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/max_retries must be between 0 and 10/');

        $this->bootCollectingDeprecations(['max_attempts' => 11]);
    }

    public function testMaxRetriesAndAliasWithDifferentValuesAreRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/max_retries.*max_attempts.*different values/');

        $this->bootCollectingDeprecations(['max_retries' => 1, 'max_attempts' => 3]);
    }

    public function testMaxRetriesAndAliasWithEqualValuesAreAccepted(): void
    {
        $deprecations = $this->bootCollectingDeprecations(['max_retries' => 3, 'max_attempts' => 3]);

        self::assertCount(1, $deprecations);
    }

    /**
     * @param array<string, mixed> $retries
     *
     * @return list<string>
     */
    private function bootCollectingDeprecations(array $retries): array
    {
        $kernel = new TestKernel(['api_key' => TestKernel::API_KEY, 'retries' => $retries]);

        try {
            return $this->collectDeprecations(static fn () => $kernel->boot());
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @return list<string> the E_USER_DEPRECATED messages raised while $run runs
     */
    private function collectDeprecations(Closure $run): array
    {
        $deprecations = [];
        set_error_handler(static function (int $no, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, \E_USER_DEPRECATED);

        try {
            $run();
        } finally {
            restore_error_handler();
        }

        // Symfony may finalize the config tree more than once per boot, so one
        // deprecation can arrive several times: what matters is the message.
        return array_values(array_unique($deprecations));
    }

    public function testRetriesDelaySecondsOutOfRange(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/delay_seconds/');

        (new TestKernel(['api_key' => TestKernel::API_KEY, 'retries' => ['delay_seconds' => 31]]))->boot();
    }

    public function testBaseUrlMustBeHttpScheme(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/http/');

        (new TestKernel(['api_key' => TestKernel::API_KEY, 'base_url' => 'ftp://api.poli.page']))->boot();
    }
}
