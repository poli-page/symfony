<?php

declare(strict_types=1);

namespace PoliPage\Symfony\EventListener;

use PoliPage\PoliPageException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
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
    /**
     * Why -10: after FrameworkBundle's ErrorListener::logKernelException (0),
     * so the exception is still logged, and before RouterListener (-64) and
     * ErrorListener::onKernelException (-128), which would render an error page.
     */
    public const PRIORITY = -10;

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', self::PRIORITY]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof PoliPageException) {
            return;
        }

        if (headers_sent()) {
            // The error came after the response started (a streamed PDF whose
            // download failed midway). Anything written now would be appended
            // to the PDF bytes, so add nothing: the client sees a truncated
            // download, and the exception is already logged.
            $event->setResponse(new Response());

            return;
        }

        $payload = $exception->toPayload();
        $response = new JsonResponse($payload, $payload['status'] ?? 500);
        $response->headers->set('Cache-Control', 'no-store, private');

        $event->setResponse($response);
    }
}
