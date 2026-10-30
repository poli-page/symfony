<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\Symfony\EventListener\PoliPageExceptionListener;
use PoliPage\Symfony\Tests\Fixtures\TestKernel;
use PoliPage\Symfony\Tests\RestoresGlobalHandlers;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PoliPageBundleTest extends TestCase
{
    use RestoresGlobalHandlers;

    public function testExceptionListenerIsNotRegisteredByDefault(): void
    {
        $kernel = new TestKernel(['api_key' => TestKernel::API_KEY]);
        $kernel->boot();

        self::assertFalse($kernel->getContainer()->has('poli_page.exception_listener'));

        $kernel->shutdown();
    }

    public function testExceptionListenerOptInRegistersService(): void
    {
        $kernel = new TestKernel([
            'api_key' => TestKernel::API_KEY,
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

    /**
     * A key ending in a newline passes nyholm's header check, then Symfony
     * HttpClient refuses it with the whole "Authorization: Bearer <key>"
     * in the message. poli-page/sdk 0.11.0 refuses such a key with a fixed
     * invalid_options message before building the request.
     */
    public function testRenderCommandNeverPrintsTheApiKeyOnTheDefaultTransport(): void
    {
        $kernel = new TestKernel([
            'api_key' => TestKernel::API_KEY."\n",
            'base_url' => 'http://127.0.0.1:9',
            'retries' => ['max_attempts' => 0],
            'logger' => 'app.test_logger',
        ], ['app.test_logger' => BufferingLogger::class]);
        $kernel->boot();

        $tester = new CommandTester((new Application($kernel))->find('poli-page:render'));
        $exitCode = $tester->execute([
            '--project' => 'getting-started',
            '--template' => 'welcome',
            '--output' => sys_get_temp_dir().'/poli-page-render-test-'.uniqid().'.pdf',
        ]);
        $display = (string) preg_replace('/\s+/', '', $tester->getDisplay());

        self::assertSame(3, $exitCode);
        self::assertStringContainsString('apiKeycontainscharactersthatarenotallowedinanHTTPheader', $display);
        self::assertStringContainsString('code=invalid_options', $display);
        self::assertStringNotContainsString(substr(TestKernel::API_KEY, -32), $display);
        self::assertStringNotContainsString('pp_sandbox_', $display);

        $kernel->shutdown();
    }

    public function testExceptionListenerNeverSendsTheApiKey(): void
    {
        $kernel = new TestKernel([
            'api_key' => TestKernel::API_KEY,
            'exception_listener' => ['enabled' => true],
        ]);
        $kernel->boot();
        $listener = $kernel->getContainer()->get('poli_page.exception_listener');
        self::assertInstanceOf(PoliPageExceptionListener::class, $listener);

        $event = new ExceptionEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, new PoliPageException(
            'Invalid header: CR/LF/NUL found in "Authorization: Bearer '.TestKernel::API_KEY."\n\".",
            PoliPageException::NETWORK_ERROR,
        ));
        $listener->onKernelException($event);

        $content = (string) $event->getResponse()?->getContent();
        self::assertStringNotContainsString(substr(TestKernel::API_KEY, -32), $content);
        self::assertStringNotContainsString('pp_sandbox_', $content);
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['message']);
        self::assertStringContainsString('CR/LF/NUL', $payload['message']);

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
        $kernel = new TestKernel(['api_key' => TestKernel::API_KEY]);
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
            'api_key' => TestKernel::API_KEY,
            'base_url' => 'https://api.example.com',
            'timeout' => 42.0,
            'retries' => ['max_attempts' => 5, 'delay_seconds' => 0.1],
        ]);
        $kernel->boot();

        $client = $kernel->getContainer()->get(PoliPage::class);
        self::assertInstanceOf(PoliPage::class, $client);

        $reflection = new ReflectionClass($client);
        self::assertSame(TestKernel::API_KEY, $reflection->getProperty('apiKey')->getValue($client));
        self::assertSame('https://api.example.com', $reflection->getProperty('baseUrl')->getValue($client));
        self::assertSame(42.0, $reflection->getProperty('defaultTimeout')->getValue($client));
        self::assertSame(5, $reflection->getProperty('maxRetries')->getValue($client));
        self::assertSame(0.1, $reflection->getProperty('retryDelay')->getValue($client));

        $kernel->shutdown();
    }
}
