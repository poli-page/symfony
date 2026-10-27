<?php

declare(strict_types=1);

namespace App\Controller;

use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\RenderInput;
use PoliPage\Symfony\ApiKeyRedaction;
use PoliPage\Symfony\Http\PoliPageResponseFactory;
use PoliPage\ThumbnailOptions;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DocumentController
{
    public function __construct(
        private readonly PoliPage $poliPage,
        private readonly PoliPageResponseFactory $factory,
        #[Autowire(param: 'poli_page.api_key'), SensitiveParameter]
        private readonly string $apiKey,
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
     * Demo step 9: error handling — render a template that does not exist (404 TEMPLATE_NOT_FOUND).
     */
    #[Route('/errors/not-found', name: 'error_not_found', methods: ['GET'])]
    public function notFound(): JsonResponse
    {
        try {
            $this->poliPage->render->pdf(new RenderInput(
                project: 'getting-started',
                template: 'does-not-exist',
                // Why: poli-page/sdk encodes an empty PHP array as JSON [], not {}; one key keeps data an object.
                data: ['name' => 'Symfony'],
            ));
        } catch (PoliPageException $e) {
            $payload = $e->toPayload();
            $status = $payload['status'] ?? 500;
            return new JsonResponse([
                'caught' => true,
                'code' => $payload['code'],
                'message' => ApiKeyRedaction::apply($this->apiKey, $payload['message']),
                'status' => $status,
                'requestId' => $payload['requestId'],
            ], $status);
        }

        return new JsonResponse(['caught' => false, 'note' => 'expected an exception, got success'], 500);
    }
}
