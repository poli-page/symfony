<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoliPage\DocumentDescriptor;
use PoliPage\DocumentPreviewResult;
use PoliPage\PreviewResult;
use PoliPage\RenderMetadata;
use PoliPage\Symfony\Http\PoliPageResponseFactory;
use ReflectionClass;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PoliPageResponseFactoryTest extends TestCase
{
    private PoliPageResponseFactory $factory;
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $this->factory = new PoliPageResponseFactory();
        $this->psr17 = new Psr17Factory();
    }

    public function testBytesReturnsResponseWithPdfHeaders(): void
    {
        $pdf = "%PDF-1.7\n%fake bytes for testing\n%%EOF\n";
        $response = $this->factory->bytes($pdf, 'invoice.pdf');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame((string) \strlen($pdf), $response->headers->get('Content-Length'));
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('filename=invoice.pdf', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame($pdf, $response->getContent());
    }

    public function testBytesInlineFlipsDisposition(): void
    {
        $response = $this->factory->bytes('%PDF-1.7', 'report.pdf', inline: true);
        self::assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function testBytesNonAsciiFilenameUsesRfc5987Encoding(): void
    {
        $response = $this->factory->bytes('%PDF-1.7', 'résumé François.pdf');
        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringContainsString('filename=', $disposition);
        self::assertStringContainsString("filename*=utf-8''", $disposition);
    }

    /**
     * Same cases as poli-page/django#1. Quoting and `"`/`\` escaping are HeaderUtils's job;
     * HeaderUtils forbids `/` and `\` (path separators) and `%` in the fallback, so those
     * are replaced instead of making the response throw.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function contentDispositionCases(): iterable
    {
        yield 'double-quote-is-escaped' => ['say "hi".pdf', 'attachment; filename="say \"hi\".pdf"'];
        yield 'backslash-is-replaced-instead-of-throwing' => ['a\b.pdf', 'attachment; filename=a_b.pdf'];
        yield 'crlf-is-stripped' => ["evil.pdf\r\nSet-Cookie: sid=1", 'attachment; filename="evil.pdfSet-Cookie: sid=1"'];
        yield 'control-chars-are-stripped' => ["tab\there\x00\x1f\x7f.pdf", 'attachment; filename=tabhere.pdf'];
        yield 'parameter-injection-stays-inside-the-quoted-string' => [
            'x.pdf"; filename="pwn.exe',
            'attachment; filename="x.pdf\"; filename=\"pwn.exe"',
        ];
        yield 'non-ascii-uses-rfc5987-dual-notation' => [
            'résumé François.pdf',
            "attachment; filename=\"r?sum? Fran?ois.pdf\"; filename*=utf-8''r%C3%A9sum%C3%A9%20Fran%C3%A7ois.pdf",
        ];
        yield 'non-ascii-fallback-is-escaped' => [
            'résumé "final"\v2.pdf',
            "attachment; filename=\"r?sum? \\\"final\\\"_v2.pdf\"; filename*=utf-8''r%C3%A9sum%C3%A9%20%22final%22_v2.pdf",
        ];
        yield 'non-ascii-control-chars-are-stripped-from-both-forms' => [
            "résumé\r\n\u{85}.pdf",
            "attachment; filename=\"r?sum?.pdf\"; filename*=utf-8''r%C3%A9sum%C3%A9.pdf",
        ];
        yield 'percent-sign-does-not-throw' => ['100%.pdf', "attachment; filename=\"100?.pdf\"; filename*=utf-8''100%25.pdf"];
        yield 'slash-is-replaced-instead-of-throwing' => ['Q1/Q2.pdf', 'attachment; filename=Q1_Q2.pdf'];
        yield 'only-control-chars-falls-back-to-default' => ["\r\n\t", 'attachment; filename=document.pdf'];
    }

    #[DataProvider('contentDispositionCases')]
    public function testBytesContentDispositionIsRfc6266Safe(string $filename, string $expected): void
    {
        $response = $this->factory->bytes('%PDF-1.7', $filename);
        self::assertSame($expected, $response->headers->get('Content-Disposition'));
    }

    public function testStreamContentDispositionIsEscapedAndStripped(): void
    {
        $response = $this->factory->stream($this->psr17->createStream('x'), "q\"\r\n.pdf", inline: true);
        self::assertSame('inline; filename="q\".pdf"', $response->headers->get('Content-Disposition'));
    }

    public function testStreamReturnsStreamedResponse(): void
    {
        $stream = $this->psr17->createStream('%PDF-1.7 streamed bytes');
        $response = $this->factory->stream($stream, 'streamed.pdf');

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('filename=streamed.pdf', (string) $response->headers->get('Content-Disposition'));

        ob_start();
        $response->sendContent();
        $emitted = (string) ob_get_clean();
        self::assertSame('%PDF-1.7 streamed bytes', $emitted);
    }

    public function testPreviewReturnsHtmlResponse(): void
    {
        $preview = new PreviewResult('<html>...</html>', 3, 'sandbox');
        $response = $this->factory->preview($preview);

        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('<html>...</html>', $response->getContent());
    }

    public function testPreviewAcceptsDocumentPreviewResult(): void
    {
        $preview = new DocumentPreviewResult('<html>stored</html>', 5);
        $response = $this->factory->preview($preview);

        self::assertSame('<html>stored</html>', $response->getContent());
    }

    public function testDocumentRedirectGoesTo302PresignedUrl(): void
    {
        $descriptor = $this->makeDescriptor('https://cdn.example/abc.pdf?sig=xyz');
        $response = $this->factory->documentRedirect($descriptor);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('https://cdn.example/abc.pdf?sig=xyz', $response->getTargetUrl());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    /**
     * Transport is internal; reflection-construct so the test does not depend
     * on a real Transport (which requires an HTTP client + factories).
     */
    private function makeDescriptor(string $url): DocumentDescriptor
    {
        $reflection = new ReflectionClass(DocumentDescriptor::class);
        /** @var DocumentDescriptor $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        foreach ([
            'documentId' => 'doc_abc',
            'organizationId' => 'org_abc',
            'projectId' => null,
            'projectSlug' => null,
            'templateId' => null,
            'templateSlug' => null,
            'version' => null,
            'environment' => 'sandbox',
            'apiKeyId' => null,
            'format' => 'A4',
            'orientation' => null,
            'locale' => null,
            'pageCount' => 1,
            'sizeBytes' => 1234,
            'createdAt' => '2026-05-24T00:00:00Z',
            'metadata' => new RenderMetadata([]),
            'presignedPdfUrl' => $url,
            'expiresAt' => '2026-05-24T00:15:00Z',
        ] as $name => $value) {
            $prop = $reflection->getProperty($name);
            $prop->setValue($instance, $value);
        }

        return $instance;
    }
}
