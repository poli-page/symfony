<?php

declare(strict_types=1);

namespace PoliPage\Symfony\EventListener;

use PoliPage\Events\RetryEvent;
use PoliPage\Symfony\Event\PoliPageRetryEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class RetryListener
{
    public function __construct(private EventDispatcherInterface $dispatcher)
    {
    }

    public function __invoke(RetryEvent $event): void
    {
        $this->dispatcher->dispatch(new PoliPageRetryEvent($event));
    }
}
