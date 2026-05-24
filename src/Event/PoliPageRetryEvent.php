<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Event;

use PoliPage\Events\RetryEvent;

final readonly class PoliPageRetryEvent
{
    public function __construct(public RetryEvent $sdkEvent)
    {
    }
}
