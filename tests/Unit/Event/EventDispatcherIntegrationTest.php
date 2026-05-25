<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use PoliPage\Events\RetryEvent;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\Symfony\Event\PoliPageErrorEvent;
use PoliPage\Symfony\Event\PoliPageRetryEvent;
use PoliPage\Symfony\EventListener\ErrorListener;
use PoliPage\Symfony\EventListener\RetryListener;
use PoliPage\Symfony\Tests\Fixtures\TestKernel;
use PoliPage\Symfony\Tests\RestoresGlobalHandlers;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class EventDispatcherIntegrationTest extends TestCase
{
    use RestoresGlobalHandlers;

    public function testRetryListenerDispatchesRetryEvent(): void
    {
        $kernel = new TestKernel(['api_key' => 'pp_test_evt']);
        $kernel->boot();
        $container = $kernel->getContainer();

        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $captured = null;
        $dispatcher->addListener(PoliPageRetryEvent::class, static function (PoliPageRetryEvent $event) use (&$captured): void {
            $captured = $event;
        });

        $listener = $container->get('poli_page.retry_listener');
        self::assertInstanceOf(RetryListener::class, $listener);

        $sdkEvent = new RetryEvent(2, 250.0, new PoliPageException('boom', 'INTERNAL'));
        $listener($sdkEvent);

        self::assertInstanceOf(PoliPageRetryEvent::class, $captured);
        self::assertSame($sdkEvent, $captured->sdkEvent);

        $kernel->shutdown();
    }

    public function testErrorListenerDispatchesErrorEvent(): void
    {
        $kernel = new TestKernel(['api_key' => 'pp_test_err']);
        $kernel->boot();
        $container = $kernel->getContainer();

        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $captured = null;
        $dispatcher->addListener(PoliPageErrorEvent::class, static function (PoliPageErrorEvent $event) use (&$captured): void {
            $captured = $event;
        });

        $listener = $container->get('poli_page.error_listener');
        self::assertInstanceOf(ErrorListener::class, $listener);

        $exception = new PoliPageException('terminal', 'API_ERROR');
        $listener($exception);

        self::assertInstanceOf(PoliPageErrorEvent::class, $captured);
        self::assertSame($exception, $captured->exception);

        $kernel->shutdown();
    }

    /**
     * When on_retry: <service_id> is set in YAML, the bundle aliases the
     * internal poli_page.retry_listener to that user service. The container's
     * compile-time invalid-reference check requires the user service to exist,
     * so we register a stub via TestKernel's extraServices hook.
     */
    public function testUserOnRetryAliasOverridesInternalListener(): void
    {
        $kernel = new TestKernel(
            ['api_key' => 'pp_test_custom_hook', 'on_retry' => 'app.custom_retry_listener'],
            ['app.custom_retry_listener' => null], // stdClass stub — enough to satisfy the alias target
        );
        $kernel->boot();
        $container = $kernel->getContainer();

        self::assertTrue($container->has(PoliPage::class));
        // The bundle's internal listener id is now an alias pointing at the user's service.
        self::assertSame(
            $container->get('app.custom_retry_listener'),
            $container->get('poli_page.retry_listener'),
        );

        $kernel->shutdown();
    }
}
