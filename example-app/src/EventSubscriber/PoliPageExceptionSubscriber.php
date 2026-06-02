<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use PoliPage\PoliPageException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Why: surface SDK errors as their underlying HTTP status (e.g. 404 for a
 * missing document) instead of Symfony's default 500. Mirrors the global
 * error mapping shipped by the Next.js / NestJS / FastAPI demos.
 */
final class PoliPageExceptionSubscriber implements EventSubscriberInterface
{
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

        $status = $exception->status >= 400 ? $exception->status : 500;

        $event->setResponse(new JsonResponse([
            'error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'status' => $exception->status,
                'requestId' => $exception->requestId,
            ],
        ], $status));
    }
}
