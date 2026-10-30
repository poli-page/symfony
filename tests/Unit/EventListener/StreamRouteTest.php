<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit\EventListener;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoliPage\PoliPage;
use PoliPage\RenderInput;
use PoliPage\Symfony\Http\PoliPageResponseFactory;
use PoliPage\Symfony\Tests\Contract\MockServer;
use PoliPage\Symfony\Tests\Fixtures\TestKernel;
use PoliPage\Symfony\Tests\RestoresGlobalHandlers;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\HttpKernel;

/**
 * A streamed-PDF route, wired like the example app's GET /render/stream, with
 * the bundle's client and exception listener against a local mock API: every
 * SDK error (the render, or the start of the download) reaches the listener
 * before a PDF header is sent.
 */
final class StreamRouteTest extends TestCase
{
    use RestoresGlobalHandlers;

    private const RENDERED = '{"status": 200, "json": {"documentId": "doc_1", "presignedPdfUrl": "{{mock}}/storage/doc_1.pdf",'
        .' "environment": "sandbox", "pageCount": 1, "sizeBytes": 10, "expiresAt": "2026-09-29T10:00:22.000Z",'
        .' "createdAt": "2026-09-29T09:45:22.000Z", "format": "A4", "orientation": "portrait", "locale": "en-US",'
        .' "organizationId": "org_1", "projectId": "p", "projectSlug": "getting-started", "templateId": "t",'
        .' "templateSlug": "welcome", "apiKeyId": "k", "metadata": {}, "diagnostics": []}}';

    /**
     * @return iterable<string, array{string, int, array{code: string, message: string, status: int|null, requestId: string|null}}>
     */
    public static function failures(): iterable
    {
        yield 'render 404' => [
            '[{"status": 404, "headers": {"x-request-id": "req_404"}, "json": {"error": "TEMPLATE_NOT_FOUND", "detail": "Template not found.", "requestId": "req_404"}}]',
            404,
            ['code' => 'TEMPLATE_NOT_FOUND', 'message' => 'Template not found.', 'status' => 404, 'requestId' => 'req_404'],
        ];
        yield 'render network error' => [
            '[{"fault": "drop"}]',
            503,
            ['code' => 'network_error', 'message' => 'Network request failed', 'status' => 503, 'requestId' => null],
        ];
        yield 'download network error' => [
            '['.self::RENDERED.', {"fault": "drop"}]',
            500,
            ['code' => 'DOWNLOAD_FAILED', 'message' => 'Failed to download PDF: network error', 'status' => null, 'requestId' => null],
        ];
        yield 'download 403' => [
            '['.self::RENDERED.', {"status": 403, "text": "AccessDenied"}]',
            403,
            ['code' => 'DOWNLOAD_FAILED', 'message' => 'Failed to download PDF: HTTP 403', 'status' => 403, 'requestId' => null],
        ];
    }

    /**
     * @param array{code: string, message: string, status: int|null, requestId: string|null} $payload
     */
    #[DataProvider('failures')]
    public function testAnSdkErrorAnswersTheListenerJsonWithoutPdfHeaders(string $steps, int $status, array $payload): void
    {
        $response = $this->requestStreamRoute($steps);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertNull($response->headers->get('Content-Disposition'));
        self::assertSame($payload, json_decode((string) $response->getContent(), true));
    }

    public function testARenderStreamsThePdf(): void
    {
        $body = '';
        $response = $this->requestStreamRoute(
            '['.self::RENDERED.', {"status": 200, "headers": {"content-type": "application/pdf"}, "text": "%PDF-1.4 bytes"}]',
            $body,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('%PDF-1.4 bytes', $body);
    }

    private function requestStreamRoute(string $steps, string &$body = ''): Response
    {
        $decoded = json_decode($steps, false, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $mock = new MockServer(array_values($decoded));
        // One attempt per call: the retry policy is the SDK's, tested there.
        $kernel = new TestKernel(['api_key' => TestKernel::API_KEY, 'base_url' => $mock->origin, 'retries' => ['max_attempts' => 0]]);
        try {
            $kernel->boot();
            $container = $kernel->getContainer();
            $client = $container->get('poli_page.client');
            $factory = $container->get('poli_page.response_factory');
            $dispatcher = $container->get('event_dispatcher');
            self::assertInstanceOf(PoliPage::class, $client);
            self::assertInstanceOf(PoliPageResponseFactory::class, $factory);
            self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

            $route = static fn (): StreamedResponse => $factory->stream($client->render->pdfStream(new RenderInput(
                project: 'getting-started',
                template: 'welcome',
                data: ['name' => 'Stream'],
            )), 'welcome.pdf');
            $httpKernel = new HttpKernel($dispatcher, new class($route) implements ControllerResolverInterface {
                public function __construct(private readonly Closure $route)
                {
                }

                public function getController(Request $request): callable
                {
                    return $this->route;
                }
            }, new RequestStack(), new ArgumentResolver());

            $response = $httpKernel->handle(Request::create('/render/stream'));
            if ($response instanceof StreamedResponse) {
                ob_start();
                $response->sendContent();
                $body = (string) ob_get_clean();
            }

            return $response;
        } finally {
            $mock->stop();
            $kernel->shutdown();
        }
    }
}
