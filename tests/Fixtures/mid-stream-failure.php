<?php

declare(strict_types=1);

// Sends a streamed PDF whose download fails on its second read, the way a
// front controller does: HttpKernel::handle(), Response::send(), and
// HttpKernel::terminateWithException() for an error raised while sending.
// Run in its own process by MidStreamFailureTest, so the bytes really go out
// (headers_sent() is true) before the error. Prints the response body.

use GuzzleHttp\Psr7\PumpStream;
use PoliPage\PoliPageException;
use PoliPage\Symfony\EventListener\PoliPageExceptionListener;
use PoliPage\Symfony\Http\PoliPageResponseFactory;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\HttpKernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// The first read (8192 bytes, done by PoliPageResponseFactory::stream()
// before it returns) succeeds; the next one fails like a dropped download.
$reads = 0;
$stream = new PumpStream(static function () use (&$reads): string {
    if (0 === $reads++) {
        return str_pad('%PDF-1.4 ', 8192, 'x');
    }
    throw new PoliPageException('Failed to download PDF: network error', PoliPageException::DOWNLOAD_FAILED);
});

$dispatcher = new EventDispatcher();
$dispatcher->addSubscriber(new PoliPageExceptionListener());
$controller = static fn () => (new PoliPageResponseFactory())->stream($stream, 'report.pdf');
$kernel = new HttpKernel($dispatcher, new class($controller) implements ControllerResolverInterface {
    public function __construct(private readonly Closure $controller)
    {
    }

    public function getController(Request $request): callable
    {
        return $this->controller;
    }
}, new RequestStack(), new ArgumentResolver());

$request = Request::create('/report');
$response = $kernel->handle($request);
try {
    $response->send();
} catch (Throwable $e) {
    $kernel->terminateWithException($e, $request);
}
