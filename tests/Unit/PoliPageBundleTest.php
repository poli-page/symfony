<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PoliPage\Symfony\Tests\Fixtures\TestKernel;

final class PoliPageBundleTest extends TestCase
{
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
}
