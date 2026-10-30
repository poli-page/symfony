<?php

declare(strict_types=1);

// One process serving several requests, the way a long-running worker does,
// with one HttpKernel and one listener instance. Output is already sent before
// the first request (a stray echo), so headers_sent() is true throughout.
// Request 1 streams a PDF whose download fails after its first 8192 bytes; request 2's
// controller throws before any response exists. Run in its own process by
// WorkerErrorsTest. Prints every response body in order.

use GuzzleHttp\Psr7\PumpStream;
use PoliPage\PoliPageException;
use PoliPage\Symfony\EventListener\PoliPageExceptionListener;
use PoliPage\Symfony\Http\PoliPageResponseFactory;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\HttpKernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

echo 'stray;';
flush();

$controllers = [
    '/stream' => static function (): Response {
        $reads = 0;

        return (new PoliPageResponseFactory())->stream(new PumpStream(static function () use (&$reads): string {
            if (0 === $reads++) {
                return str_pad('%PDF-1.4 ', 8192, 'x');
            }
            throw new PoliPageException('Failed to download PDF: network error', PoliPageException::DOWNLOAD_FAILED);
        }), 'report.pdf');
    },
    '/missing' => static function (): never {
        throw new PoliPageException('Template not found', 'TEMPLATE_NOT_FOUND', 404, 'req_1');
    },
];

$dispatcher = new EventDispatcher();
$dispatcher->addSubscriber(new PoliPageExceptionListener());
$kernel = new HttpKernel($dispatcher, new class($controllers) implements ControllerResolverInterface {
    /** @param array<string, Closure> $controllers */
    public function __construct(private readonly array $controllers)
    {
    }

    public function getController(Request $request): callable
    {
        return $this->controllers[$request->getPathInfo()];
    }
}, new RequestStack(), new ArgumentResolver());

foreach (['/stream', '/missing'] as $path) {
    $request = Request::create($path);
    $response = $kernel->handle($request);
    try {
        $response->send();
    } catch (Throwable $e) {
        $kernel->terminateWithException($e, $request);
    }
    echo ';';
}
