<?php

declare(strict_types=1);

namespace PoliPage\Symfony\EventListener;

use PoliPage\PoliPageException;
use SensitiveParameter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Translates PoliPageException thrown in controllers into a JSON response
 * carrying the SDK's canonical error payload and the underlying HTTP
 * status (e.g. 404 for a missing document, 403 for a paid-feature gate).
 * Mirrors the global error mapping shipped by the NestJS / Laravel /
 * Next.js / FastAPI demos so all framework integrations expose the same
 * wire shape on errors. Opt-in via `poli_page.exception_listener.enabled`.
 */
final class PoliPageExceptionListener implements EventSubscriberInterface
{
    public function __construct(#[SensitiveParameter] private readonly string $apiKey)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onKernelException'];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof PoliPageException) {
            return;
        }

        $payload = $exception->toPayload();
        $status = $payload['status'] ?? 500;

        $response = new JsonResponse([
            'code' => $payload['code'],
            // Why: a key the HTTP layer rejects (e.g. one ending in a newline)
            // comes back in the message with the whole "Bearer <key>" value.
            'message' => str_replace($this->apiKey, '[redacted API key]', $payload['message']),
            'status' => $status,
            'requestId' => $payload['requestId'],
        ], $status);
        $response->headers->set('Cache-Control', 'no-store, private');

        $event->setResponse($response);
    }
}
