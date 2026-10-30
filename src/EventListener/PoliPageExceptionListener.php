<?php

declare(strict_types=1);

namespace PoliPage\Symfony\EventListener;

use PoliPage\PoliPageException;
use PoliPage\Symfony\ApiKeyRedaction;
use SensitiveParameter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers a PoliPageException thrown in a controller the way every Poli Page
 * integration does: status `toPayload()['status']` (503 for network_error,
 * 504 for timeout, 500 when the SDK knows none) and the body `toPayload()`:
 * `{ code, message, status, requestId }`. On by default; turned off with
 * `poli_page.exception_listener.enabled: false`.
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
        // Defence in depth: the SDK's messages never hold the key, but a
        // transport error about the Authorization header could.
        $payload['message'] = ApiKeyRedaction::apply($this->apiKey, $payload['message']);

        $response = new JsonResponse($payload, $payload['status'] ?? 500);
        $response->headers->set('Cache-Control', 'no-store, private');

        $event->setResponse($response);
    }
}
