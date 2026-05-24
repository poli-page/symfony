<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoliPage\Symfony\Tests\Fixtures\TestKernel;
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
    public function testMinimalConfigOnlyApiKey(): void
    {
        $kernel = new TestKernel(['api_key' => 'pp_test_abc']);
        $kernel->boot();
        $c = $kernel->getContainer();

        self::assertSame('pp_test_abc', $c->getParameter('poli_page.api_key'));
        self::assertNull($c->getParameter('poli_page.base_url'));
        self::assertNull($c->getParameter('poli_page.timeout'));
        self::assertNull($c->getParameter('poli_page.user_agent'));
        self::assertNull($c->getParameter('poli_page.retries.max_attempts'));
        self::assertNull($c->getParameter('poli_page.retries.delay_seconds'));
        self::assertNull($c->getParameter('poli_page.on_retry'));
        self::assertNull($c->getParameter('poli_page.on_error'));

        $kernel->shutdown();
    }

    public function testFullConfigRoundtrips(): void
    {
        $input = [
            'api_key' => 'pp_live_full',
            'base_url' => 'https://api-develop.poli.page',
            'timeout' => 45.0,
            'user_agent' => 'my-app/1.0',
            'retries' => ['max_attempts' => 5, 'delay_seconds' => 0.1],
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

        self::assertSame('pp_live_full', $c->getParameter('poli_page.api_key'));
        self::assertSame('https://api-develop.poli.page', $c->getParameter('poli_page.base_url'));
        self::assertSame(45.0, $c->getParameter('poli_page.timeout'));
        self::assertSame('my-app/1.0', $c->getParameter('poli_page.user_agent'));
        self::assertSame(5, $c->getParameter('poli_page.retries.max_attempts'));
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
     * @return iterable<string, array{0: string}>
     */
    public static function invalidApiKeyProvider(): iterable
    {
        yield 'no prefix' => ['abcdef'];
        yield 'wrong prefix' => ['sk_test_abc'];
        yield 'just pp_' => ['pp_abc'];
        yield 'pp_prod_' => ['pp_prod_abc'];
    }

    #[DataProvider('invalidApiKeyProvider')]
    public function testApiKeyMustHavePpTestOrPpLivePrefix(string $key): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/pp_test_ or pp_live_/');

        (new TestKernel(['api_key' => $key]))->boot();
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

        (new TestKernel(['api_key' => 'pp_test_x', 'timeout' => $value]))->boot();
    }

    public function testRetriesMaxAttemptsTooHigh(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/max_attempts/');

        (new TestKernel(['api_key' => 'pp_test_x', 'retries' => ['max_attempts' => 11]]))->boot();
    }

    public function testRetriesMaxAttemptsNegative(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/max_attempts/');

        (new TestKernel(['api_key' => 'pp_test_x', 'retries' => ['max_attempts' => -1]]))->boot();
    }

    public function testRetriesDelaySecondsOutOfRange(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/delay_seconds/');

        (new TestKernel(['api_key' => 'pp_test_x', 'retries' => ['delay_seconds' => 31]]))->boot();
    }

    public function testBaseUrlMustBeHttpScheme(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/http/');

        (new TestKernel(['api_key' => 'pp_test_x', 'base_url' => 'ftp://api.poli.page']))->boot();
    }
}
