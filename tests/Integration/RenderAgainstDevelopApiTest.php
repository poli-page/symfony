<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PoliPage\PoliPage;
use PoliPage\ProjectModeInput;
use PoliPage\Symfony\Tests\Fixtures\TestKernel;

final class RenderAgainstDevelopApiTest extends TestCase
{
    protected function setUp(): void
    {
        $key = getenv('POLI_PAGE_API_KEY');
        if (false === $key || '' === $key) {
            self::markTestSkipped('POLI_PAGE_API_KEY not set; skipping develop-API integration test.');
        }
        if (!str_starts_with($key, 'pp_test_')) {
            self::markTestSkipped('POLI_PAGE_API_KEY must be a pp_test_ key; refusing to run integration test against a live key.');
        }
    }

    public function testRenderGettingStartedWelcomeTemplateReturnsPdf(): void
    {
        $kernel = new TestKernel([
            'api_key' => (string) getenv('POLI_PAGE_API_KEY'),
            'base_url' => 'https://api-develop.poli.page',
            'timeout' => 30.0,
        ]);
        $kernel->boot();

        $client = $kernel->getContainer()->get(PoliPage::class);
        self::assertInstanceOf(PoliPage::class, $client);

        $pdf = $client->render->pdf(new ProjectModeInput(
            project: 'getting-started',
            template: 'welcome',
            data: ['name' => 'symfony-bundle integration test'],
            version: '1.0.0',
        ));

        self::assertNotEmpty($pdf);
        self::assertStringStartsWith('%PDF-', $pdf);

        $kernel->shutdown();
    }
}
