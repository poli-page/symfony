<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Http;

use PoliPage\DocumentDescriptor;
use PoliPage\DocumentPreviewResult;
use PoliPage\PreviewResult;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PoliPageResponseFactory
{
    public function bytes(string $pdf, string $filename = 'document.pdf', bool $inline = false): Response
    {
        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) \strlen($pdf),
            'Content-Disposition' => $this->disposition($filename, $inline),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function stream(StreamInterface $stream, string $filename = 'document.pdf', bool $inline = false): StreamedResponse
    {
        $response = new StreamedResponse(static function () use ($stream): void {
            $stream->rewind();
            while (!$stream->eof()) {
                echo $stream->read(8192);
                flush();
            }
        });
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', $this->disposition($filename, $inline));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        $size = $stream->getSize();
        if (null !== $size) {
            $response->headers->set('Content-Length', (string) $size);
        }

        return $response;
    }

    public function preview(PreviewResult|DocumentPreviewResult $preview): Response
    {
        return new Response($preview->html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function documentRedirect(DocumentDescriptor $doc): RedirectResponse
    {
        return new RedirectResponse($doc->presignedPdfUrl, Response::HTTP_FOUND, [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function disposition(string $filename, bool $inline): string
    {
        $type = $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT;
        // Why: control characters (C0 incl. TAB/CR/LF, DEL, C1) never belong in a filename;
        // HeaderUtils would keep them as %0D%0A in filename*, which clients decode back.
        $clean = (string) preg_replace('/[\x{0}-\x{1F}\x{7F}-\x{9F}]/u', '', mb_scrub($filename, 'UTF-8'));
        // Why: HeaderUtils rejects path separators in both forms (it throws); browsers replace
        // them with '_' when saving anyway.
        $clean = strtr($clean, ['/' => '_', '\\' => '_']);
        if ('' === $clean) {
            $clean = 'document.pdf';
        }
        // Why: the ASCII fallback must be printable ASCII without '%' (HeaderUtils throws on
        // it); every other character becomes one '?'. Quoting and escaping are HeaderUtils's job.
        $fallback = (string) preg_replace('/[^\x20-\x24\x26-\x7e]/u', '?', $clean);

        return HeaderUtils::makeDisposition($type, $clean, $fallback);
    }
}
