<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit\EventListener;

use Closure;
use GuzzleHttp\Psr7\PumpStream;
use PHPUnit\Framework\TestCase;
use PoliPage\PoliPageException;
use PoliPage\Symfony\EventListener\PoliPageExceptionListener;
use PoliPage\Symfony\Http\PoliPageResponseFactory;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\HttpKernel;

/**
 * A streamed PDF whose download fails on its first read, through a real
 * HttpKernel with the exception listener subscribed.
 */
final class StreamErrorTest extends TestCase
{
    public function testAStreamWhoseFirstReadFailsAnswersJsonWithoutPdfHeaders(): void
    {
        $controller = static fn () => (new PoliPageResponseFactory())->stream(new PumpStream(static function (): never {
            throw new PoliPageException('Failed to download PDF: network error', PoliPageException::DOWNLOAD_FAILED);
        }), 'report.pdf');
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new PoliPageExceptionListener());
        $kernel = new HttpKernel($dispatcher, new class($controller) implements ControllerResolverInterface {
            public function __construct(private readonly Closure $controller)
            {
            }

            public function getController(Request $request): callable
            {
                return $this->controller;
            }
        }, new RequestStack(), new ArgumentResolver());

        $response = $kernel->handle(Request::create('/report'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertNull($response->headers->get('Content-Disposition'));
        self::assertSame([
            'code' => 'DOWNLOAD_FAILED',
            'message' => 'Failed to download PDF: network error',
            'status' => null,
            'requestId' => null,
        ], json_decode((string) $response->getContent(), true));
    }
}
