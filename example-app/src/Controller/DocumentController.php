<?php

declare(strict_types=1);

namespace App\Controller;

use PoliPage\PoliPage;
use PoliPage\RenderInput;
use PoliPage\Symfony\Http\PoliPageResponseFactory;
use PoliPage\ThumbnailOptions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DocumentController
{
    public function __construct(
        private readonly PoliPage $poliPage,
        private readonly PoliPageResponseFactory $factory,
    ) {
    }

    /**
     * Demo step 5: documents->get(id) — fresh descriptor + presigned URL, then 302.
     */
    #[Route('/documents/{id}', name: 'document_get', methods: ['GET'])]
    public function get(string $id): Response
    {
        $descriptor = $this->poliPage->documents->get($id);

        return $this->factory->documentRedirect($descriptor);
    }

    /**
     * Demo step 6: documents->thumbnails(id, opts).
     */
    #[Route('/documents/{id}/thumbnails', name: 'document_thumbnails', methods: ['GET'])]
    public function thumbnails(string $id): JsonResponse
    {
        $thumbnails = $this->poliPage->documents->thumbnails($id, new ThumbnailOptions(width: 240));

        return new JsonResponse([
            'count' => \count($thumbnails),
            'thumbnails' => array_map(static fn ($t) => [
                'page' => $t->page,
                'width' => $t->width,
                'height' => $t->height,
                'contentType' => $t->contentType,
                'base64Bytes' => $t->data,
            ], $thumbnails),
        ]);
    }

    /**
     * Demo step 7: documents->preview(id).
     */
    #[Route('/documents/{id}/preview', name: 'document_preview', methods: ['GET'])]
    public function preview(string $id): Response
    {
        $result = $this->poliPage->documents->preview($id);

        return $this->factory->preview($result);
    }

    /**
     * Demo step 8: documents->delete(id).
     */
    #[Route('/documents/{id}', name: 'document_delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $this->poliPage->documents->delete($id);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Demo step 9: error handling. Renders a template that does not exist; the
     * API answers 404 TEMPLATE_NOT_FOUND and the bundle's exception listener
     * turns the uncaught PoliPageException into its toPayload() body with status 404.
     */
    #[Route('/errors/not-found', name: 'error_not_found', methods: ['GET'])]
    public function notFound(): Response
    {
        $pdf = $this->poliPage->render->pdf(new RenderInput(
            project: 'getting-started',
            template: 'does-not-exist',
            // Why: poli-page/sdk encodes an empty PHP array as JSON [], not {}; one key keeps data an object.
            data: ['name' => 'Symfony'],
        ));

        return $this->factory->bytes($pdf, 'does-not-exist.pdf');
    }
}
