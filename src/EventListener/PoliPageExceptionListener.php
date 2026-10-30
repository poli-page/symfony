<?php

declare(strict_types=1);

namespace PoliPage\Symfony\EventListener;

use PoliPage\PoliPageException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;
use WeakMap;

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

    /**
     * Why the lowest priority: the streamed response to watch is the one left
     * once every other kernel.response listener has had its say.
     */
    public const RESPONSE_PRIORITY = -2048;

    /**
     * Exceptions thrown from a streamed response's body callback, that is
     * after that response sent its headers. Keyed by the exception itself, so
     * the mark belongs to one response and is freed with the exception.
     *
     * @var WeakMap<Throwable, true>
     */
    private WeakMap $thrownWhileStreaming;

    public function __construct()
    {
        $this->thrownWhileStreaming = new WeakMap();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', self::PRIORITY],
            KernelEvents::RESPONSE => ['onKernelResponse', self::RESPONSE_PRIORITY],
        ];
    }

    /**
     * Wraps a streamed response's body callback to mark any exception it
     * throws: by then that response's headers (and usually some bytes) are out.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if (!$response instanceof StreamedResponse) {
            return;
        }
        $callback = $response->getCallback();
        if (null === $callback) {
            return;
        }

        $thrownWhileStreaming = $this->thrownWhileStreaming;
        $response->setCallback(static function () use ($callback, $thrownWhileStreaming): void {
            try {
                $callback();
            } catch (Throwable $e) {
                $thrownWhileStreaming[$e] = true;

                throw $e;
            }
        });
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof PoliPageException) {
            return;
        }

        if ($this->responseAlreadyStarted($event, $exception)) {
            // The error came after this request's response started (a streamed
            // PDF whose download failed midway, or a kernel.terminate listener).
            // Anything written now would be appended to the bytes already sent,
            // so add nothing: the client sees a truncated download, and the
            // exception is already logged.
            $event->setResponse(new Response());

            return;
        }

        $payload = $exception->toPayload();
        $response = new JsonResponse($payload, $payload['status'] ?? 500);
        $response->headers->set('Cache-Control', 'no-store, private');

        $event->setResponse($response);
    }

    /**
     * Why not headers_sent(): it is process-wide. In a long-running worker one
     * stray echo would make every later Poli Page error answer an empty 500.
     */
    private function responseAlreadyStarted(ExceptionEvent $event, PoliPageException $exception): bool
    {
        if (isset($this->thrownWhileStreaming[$exception])) {
            return true;
        }

        // ExceptionEvent::isKernelTerminating() exists from Symfony 7.1.
        return method_exists($event, 'isKernelTerminating') && $event->isKernelTerminating();
    }
}
