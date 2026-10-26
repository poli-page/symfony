<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PoliPage\PoliPage;
use PoliPage\RenderInput;
use PoliPage\Symfony\Tests\Fixtures\TestKernel;
use PoliPage\Symfony\Tests\RestoresGlobalHandlers;

final class RenderAgainstDevelopApiTest extends TestCase
{
    use RestoresGlobalHandlers;

    public function testRenderGettingStartedWelcomeTemplateReturnsPdf(): void
    {
        $key = getenv('POLI_PAGE_API_KEY');
        if (false === $key || '' === $key) {
            self::markTestSkipped('POLI_PAGE_API_KEY not set; skipping integration test.');
        }
        // Why: a test-only guard; renders in any other environment count toward the monthly quota.
        if (!str_starts_with($key, 'pp_sandbox_')) {
            self::markTestSkipped('POLI_PAGE_API_KEY must be a pp_sandbox_ key; refusing to run the integration test in a counted environment.');
        }

        $config = ['api_key' => $key, 'timeout' => 30.0];
        $testBaseUrl = getenv('POLI_PAGE_TEST_BASE_URL');
        if (is_string($testBaseUrl) && '' !== $testBaseUrl) {
            $config['base_url'] = $testBaseUrl;
        }
        $kernel = new TestKernel($config);
        $kernel->boot();

        $client = $kernel->getContainer()->get(PoliPage::class);
        self::assertInstanceOf(PoliPage::class, $client);

        $pdf = $client->render->pdf(new RenderInput(
            project: 'getting-started',
            template: 'welcome',
            data: ['name' => 'symfony-bundle integration test'],
        ));

        self::assertNotEmpty($pdf);
        self::assertStringStartsWith('%PDF-', $pdf);

        $kernel->shutdown();
    }
}
