<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DemoController
{
    #[Route('/', name: 'demo_index', methods: ['GET'])]
    public function index(): Response
    {
        $html = file_get_contents(\dirname(__DIR__, 2).'/templates/demo.html');
        if (false === $html) {
            return new Response('demo.html missing', 500);
        }

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
