<?php

declare(strict_types=1);

namespace App\Controller;

use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\ProjectModeInput;
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
     * Demo step 6: documents->get(id) — fresh descriptor + presigned URL, then 302.
     */
    #[Route('/documents/{id}', name: 'document_get', methods: ['GET'])]
    public function get(string $id): Response
    {
        $descriptor = $this->poliPage->documents->get($id);

        return $this->factory->documentRedirect($descriptor);
    }

    /**
     * Demo step 7: documents->thumbnails(id, opts).
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
     * Demo step 8: documents->preview(id).
     */
    #[Route('/documents/{id}/preview', name: 'document_preview', methods: ['GET'])]
    public function preview(string $id): Response
    {
        $result = $this->poliPage->documents->preview($id);

        return $this->factory->preview($result);
    }

    /**
     * Demo step 9: documents->delete(id).
     */
    #[Route('/documents/{id}', name: 'document_delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $this->poliPage->documents->delete($id);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Demo step 10: error handling — deliberately trigger INVALID_VERSION_FORMAT.
     */
    #[Route('/errors/bad-version', name: 'error_bad_version', methods: ['GET'])]
    public function badVersion(): JsonResponse
    {
        try {
            $this->poliPage->render->pdf(new ProjectModeInput(
                project: 'getting-started',
                template: 'welcome',
                data: [],
                version: 'not-semver',
            ));
        } catch (PoliPageException $e) {
            return new JsonResponse([
                'caught' => true,
                'status' => $e->status,
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
                'requestId' => $e->requestId,
            ], 400);
        }

        return new JsonResponse(['caught' => false, 'note' => 'expected an exception, got success'], 500);
    }
}
