<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit\EventListener;

use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use PoliPage\PoliPageException;
use PoliPage\Symfony\EventListener\PoliPageExceptionListener;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A PoliPageException thrown by a kernel.terminate listener, after the
 * response was sent: the listener must add nothing to it.
 */
#[RequiresMethod(ExceptionEvent::class, 'isKernelTerminating')]
final class KernelTerminateErrorTest extends TestCase
{
    public function testAnErrorWhileTheKernelTerminatesAddsNothing(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new PoliPageExceptionListener());
        $thrown = false;
        $dispatcher->addListener(KernelEvents::TERMINATE, static function () use (&$thrown): void {
            if (!$thrown) {
                $thrown = true;

                throw new PoliPageException('Failed to download PDF: network error', PoliPageException::DOWNLOAD_FAILED);
            }
        });
        $kernel = new HttpKernel($dispatcher, new class implements ControllerResolverInterface {
            public function getController(Request $request): callable
            {
                return static fn (): Response => new Response('done');
            }
        }, new RequestStack(), new ArgumentResolver());

        $request = Request::create('/report');
        $response = $kernel->handle($request);
        try {
            $kernel->terminate($request, $response);
            self::fail('The terminate listener did not throw.');
        } catch (PoliPageException $e) {
            // terminateWithException() sends the listener's answer: capture it.
            ob_start();
            try {
                $kernel->terminateWithException($e, $request);
            } finally {
                $sent = ob_get_clean();
            }
        }

        self::assertSame('', $sent);
    }
}
