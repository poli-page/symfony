<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit\EventListener;

use LogicException;
use PHPUnit\Framework\TestCase;
use PoliPage\PoliPageException;
use PoliPage\Symfony\EventListener\PoliPageExceptionListener;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

final class PoliPageExceptionListenerTest extends TestCase
{
    public function testMapsPoliPageExceptionToJsonResponseWithApiStatus(): void
    {
        $listener = new PoliPageExceptionListener();
        $exception = new PoliPageException(
            'Thumbnails require a paid plan.',
            'THUMBNAILS_NOT_AVAILABLE',
            403,
            'req_123',
        );
        $event = $this->makeEvent($exception);

        $listener->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame([
            'code' => 'THUMBNAILS_NOT_AVAILABLE',
            'message' => 'Thumbnails require a paid plan.',
            'status' => 403,
            'requestId' => 'req_123',
        ], json_decode((string) $response->getContent(), true));
    }

    public function testDefaultsTo500WhenExceptionHasNoStatus(): void
    {
        $listener = new PoliPageExceptionListener();
        $exception = new PoliPageException('boom', PoliPageException::NETWORK_ERROR);
        $event = $this->makeEvent($exception);

        $listener->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(500, $response->getStatusCode());
    }

    public function testIgnoresNonPoliPageExceptions(): void
    {
        $listener = new PoliPageExceptionListener();
        $event = $this->makeEvent(new RuntimeException('unrelated'));

        $listener->onKernelException($event);

        self::assertNull($event->getResponse());
    }

    public function testSubscribesToKernelException(): void
    {
        $events = PoliPageExceptionListener::getSubscribedEvents();
        self::assertArrayHasKey('kernel.exception', $events);
    }

    private function makeEvent(Throwable $exception): ExceptionEvent
    {
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): \Symfony\Component\HttpFoundation\Response
            {
                throw new LogicException('stub kernel must not be invoked in tests');
            }
        };

        return new ExceptionEvent(
            $kernel,
            new Request(),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }
}
