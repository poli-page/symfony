<?php

declare(strict_types=1);

namespace App\Controller;

use PoliPage\InlineModeInput;
use PoliPage\PoliPage;
use PoliPage\ProjectModeInput;
use PoliPage\Symfony\Http\PoliPageResponseFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function PoliPage\renderToFile;

final class RenderController
{
    public function __construct(
        private readonly PoliPage $poliPage,
        private readonly PoliPageResponseFactory $factory,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Demo step 1: render->pdf() — fetch PDF bytes into memory.
     */
    #[Route('/render/pdf', name: 'render_pdf', methods: ['GET'])]
    public function pdf(): Response
    {
        $pdf = $this->poliPage->render->pdf(new ProjectModeInput(
            project: 'getting-started',
            template: 'welcome',
            data: ['name' => 'Symfony'],
            version: '1.0.0',
        ));

        return $this->factory->bytes($pdf, 'welcome.pdf');
    }

    /**
     * Demo step 2: render->pdfStream() — get a PSR-7 stream of PDF bytes.
     */
    #[Route('/render/stream', name: 'render_stream', methods: ['GET'])]
    public function stream(): Response
    {
        $stream = $this->poliPage->render->pdfStream(new ProjectModeInput(
            project: 'getting-started',
            template: 'welcome',
            data: ['name' => 'Symfony streamed'],
            version: '1.0.0',
        ));

        return $this->factory->stream($stream, 'welcome-streamed.pdf');
    }

    /**
     * Demo step 4: render->preview() — paginated HTML preview output.
     * Accepts ?html=<raw html> for inline mode, otherwise project mode.
     */
    #[Route('/render/preview', name: 'render_preview', methods: ['GET'])]
    public function preview(Request $request): Response
    {
        $html = $request->query->get('html');
        $input = null !== $html
            ? new InlineModeInput(template: (string) $html, data: ['name' => 'Inline'])
            : new ProjectModeInput(
                project: 'getting-started',
                template: 'welcome',
                data: ['name' => 'Preview from project'],
                version: '1.0.0',
            );

        $result = $this->poliPage->render->preview($input);

        return $this->factory->preview($result);
    }

    /**
     * Demo step 3: renderToFile() — stream the PDF straight to disk, memory-bounded.
     */
    #[Route('/render/file', name: 'render_file', methods: ['POST'])]
    public function renderFile(): JsonResponse
    {
        $output = $this->projectDir.'/var/poli-page/welcome.pdf';
        if (!is_dir(\dirname($output))) {
            mkdir(\dirname($output), 0o775, true);
        }

        renderToFile($this->poliPage, new ProjectModeInput(
            project: 'getting-started',
            template: 'welcome',
            data: ['name' => 'renderToFile demo'],
            version: '1.0.0',
        ), $output);

        return new JsonResponse([
            'path' => $output,
            'sizeBytes' => filesize($output) ?: 0,
        ]);
    }

    /**
     * Demo step 5: render->document() — store the document, return descriptor as JSON.
     */
    #[Route('/documents', name: 'document_create', methods: ['POST'])]
    public function createDocument(): JsonResponse
    {
        $descriptor = $this->poliPage->render->document(new ProjectModeInput(
            project: 'getting-started',
            template: 'welcome',
            data: ['name' => 'Stored doc'],
            version: '1.0.0',
        ));

        return new JsonResponse([
            'documentId' => $descriptor->documentId,
            'pageCount' => $descriptor->pageCount,
            'sizeBytes' => $descriptor->sizeBytes,
            'environment' => $descriptor->environment,
            'expiresAt' => $descriptor->expiresAt,
            'presignedPdfUrl' => $descriptor->presignedPdfUrl,
        ]);
    }
}
