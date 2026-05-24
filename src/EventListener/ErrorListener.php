<?php

declare(strict_types=1);

namespace PoliPage\Symfony\EventListener;

use PoliPage\PoliPageException;
use PoliPage\Symfony\Event\PoliPageErrorEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class ErrorListener
{
    public function __construct(private EventDispatcherInterface $dispatcher)
    {
    }

    public function __invoke(PoliPageException $exception): void
    {
        $this->dispatcher->dispatch(new PoliPageErrorEvent($exception));
    }
}
