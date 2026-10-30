<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit\EventListener;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoliPage\Exception\ConnectionException;
use PoliPage\Exception\TimeoutException;
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
        $listener = new PoliPageExceptionListener('pp_sandbox_0123456789abcdef0123456789abcdef');
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

    public function testAnSdkErrorWithoutStatusAnswers500WithANullStatus(): void
    {
        $listener = new PoliPageExceptionListener('pp_sandbox_0123456789abcdef0123456789abcdef');
        $exception = new PoliPageException('apiKey is required', PoliPageException::INVALID_OPTIONS);
        $event = $this->makeEvent($exception);

        $listener->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame([
            'code' => 'invalid_options',
            'message' => 'apiKey is required',
            'status' => null,
            'requestId' => null,
        ], json_decode((string) $response->getContent(), true));
    }

    public function testANetworkErrorAnswers503(): void
    {
        $listener = new PoliPageExceptionListener('pp_sandbox_0123456789abcdef0123456789abcdef');
        $event = $this->makeEvent(new ConnectionException('Network request failed', PoliPageException::NETWORK_ERROR));

        $listener->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame([
            'code' => 'network_error',
            'message' => 'Network request failed',
            'status' => 503,
            'requestId' => null,
        ], json_decode((string) $response->getContent(), true));
    }

    public function testATimeoutAnswers504(): void
    {
        $listener = new PoliPageExceptionListener('pp_sandbox_0123456789abcdef0123456789abcdef');
        $event = $this->makeEvent(new TimeoutException('Request timed out after 60000ms', PoliPageException::TIMEOUT));

        $listener->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(504, $response->getStatusCode());
        self::assertSame([
            'code' => 'timeout',
            'message' => 'Request timed out after 60000ms',
            'status' => 504,
            'requestId' => null,
        ], json_decode((string) $response->getContent(), true));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function headerBreakingSuffixProvider(): iterable
    {
        yield 'newline' => ["\n"];
        // Guzzle trims " \t" before it validates, so its message holds the trimmed key.
        yield 'newline then space' => ["\n "];
        yield 'newline then tab' => ["\n\t"];
    }

    #[DataProvider('headerBreakingSuffixProvider')]
    public function testResponseMessageNeverContainsTheApiKey(string $suffix): void
    {
        $key = 'pp_sandbox_0123456789abcdef0123456789abcdef'.$suffix;
        $listener = new PoliPageExceptionListener($key);
        $event = $this->makeEvent(new PoliPageException(
            'Invalid header: CR/LF/NUL found in "Authorization: Bearer '.rtrim($key, " \t").'".',
            PoliPageException::NETWORK_ERROR,
        ));

        $listener->onKernelException($event);

        $content = (string) $event->getResponse()?->getContent();
        self::assertStringNotContainsString('0123456789abcdef0123456789abcdef', $content);
        self::assertStringNotContainsString('pp_sandbox_', $content);
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['message']);
        self::assertStringContainsString('CR/LF/NUL', $payload['message']);
    }

    public function testIgnoresNonPoliPageExceptions(): void
    {
        $listener = new PoliPageExceptionListener('pp_sandbox_0123456789abcdef0123456789abcdef');
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
