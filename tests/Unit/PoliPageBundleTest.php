<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PoliPage\PoliPage;
use PoliPage\Symfony\EventListener\PoliPageExceptionListener;
use PoliPage\Symfony\Tests\Fixtures\TestKernel;
use PoliPage\Symfony\Tests\RestoresGlobalHandlers;
use ReflectionClass;

final class PoliPageBundleTest extends TestCase
{
    use RestoresGlobalHandlers;

    public function testExceptionListenerIsNotRegisteredByDefault(): void
    {
        $kernel = new TestKernel(['api_key' => 'pp_test_x']);
        $kernel->boot();

        self::assertFalse($kernel->getContainer()->has('poli_page.exception_listener'));

        $kernel->shutdown();
    }

    public function testExceptionListenerOptInRegistersService(): void
    {
        $kernel = new TestKernel([
            'api_key' => 'pp_test_x',
            'exception_listener' => ['enabled' => true],
        ]);
        $kernel->boot();

        $container = $kernel->getContainer();
        self::assertTrue($container->has('poli_page.exception_listener'));
        self::assertInstanceOf(
            PoliPageExceptionListener::class,
            $container->get('poli_page.exception_listener'),
        );

        $kernel->shutdown();
    }

    public function testKernelBootsWithBundleRegistered(): void
    {
        $kernel = new TestKernel();
        $kernel->boot();

        $container = $kernel->getContainer();
        self::assertTrue($container->hasParameter('kernel.bundles'));
        $bundles = $container->getParameter('kernel.bundles');
        self::assertIsArray($bundles);
        self::assertArrayHasKey('PoliPageBundle', $bundles);

        $kernel->shutdown();
    }

    public function testPoliPageClientIsAutowireable(): void
    {
        $kernel = new TestKernel(['api_key' => 'pp_test_resolves']);
        $kernel->boot();

        $container = $kernel->getContainer();
        self::assertTrue($container->has(PoliPage::class));

        $client = $container->get(PoliPage::class);
        self::assertInstanceOf(PoliPage::class, $client);

        $kernel->shutdown();
    }

    public function testCustomConfigReachesConstructor(): void
    {
        $kernel = new TestKernel([
            'api_key' => 'pp_test_custom',
            'base_url' => 'https://api-develop.poli.page',
            'timeout' => 42.0,
            'retries' => ['max_attempts' => 5, 'delay_seconds' => 0.1],
        ]);
        $kernel->boot();

        $client = $kernel->getContainer()->get(PoliPage::class);
        self::assertInstanceOf(PoliPage::class, $client);

        $reflection = new ReflectionClass($client);
        self::assertSame('pp_test_custom', $reflection->getProperty('apiKey')->getValue($client));
        self::assertSame('https://api-develop.poli.page', $reflection->getProperty('baseUrl')->getValue($client));
        self::assertSame(42.0, $reflection->getProperty('defaultTimeout')->getValue($client));
        self::assertSame(5, $reflection->getProperty('maxRetries')->getValue($client));
        self::assertSame(0.1, $reflection->getProperty('retryDelay')->getValue($client));

        $kernel->shutdown();
    }
}
