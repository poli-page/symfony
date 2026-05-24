<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Event;

use PoliPage\PoliPageException;

final readonly class PoliPageErrorEvent
{
    public function __construct(public PoliPageException $exception)
    {
    }
}
