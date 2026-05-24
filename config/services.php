<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PoliPage\PoliPage;
use PoliPage\Symfony\EventListener\ErrorListener;
use PoliPage\Symfony\EventListener\RetryListener;
use PoliPage\Symfony\Http\PoliPageResponseFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\Psr18Client;

use function Symfony\Component\DependencyInjection\Loader\Configurator\closure;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // ─── PSR factories (overridable via config) ──────────────────────────────

    $services->set('poli_page.psr17_factory', Psr17Factory::class);

    $services->set('poli_page.http_client.default', Psr18Client::class)
        ->args([
            service('http_client'),
            service('poli_page.psr17_factory'),
            service('poli_page.psr17_factory'),
        ]);

    $services->alias('poli_page.http_client', 'poli_page.http_client.default');
    $services->alias('poli_page.request_factory', 'poli_page.psr17_factory');
    $services->alias('poli_page.stream_factory', 'poli_page.psr17_factory');
    $services->alias('poli_page.logger', 'logger');

    // ─── Internal SDK-hook → Symfony-event bridge ────────────────────────────

    $services->set('poli_page.retry_listener', RetryListener::class)
        ->args([service('event_dispatcher')])
        ->public();

    $services->set('poli_page.error_listener', ErrorListener::class)
        ->args([service('event_dispatcher')])
        ->public();

    // ─── PoliPage client ─────────────────────────────────────────────────────

    $services->set('poli_page.client', PoliPage::class)
        ->args([
            '$apiKey' => param('poli_page.api_key'),
            '$baseUrl' => param('poli_page.base_url'),
            '$maxRetries' => param('poli_page.retries.max_attempts'),
            '$retryDelay' => param('poli_page.retries.delay_seconds'),
            '$timeout' => param('poli_page.timeout'),
            '$httpClient' => service('poli_page.http_client'),
            '$requestFactory' => service('poli_page.request_factory'),
            '$streamFactory' => service('poli_page.stream_factory'),
            '$logger' => service('poli_page.logger'),
            '$onRetry' => closure(service('poli_page.retry_listener')),
            '$onError' => closure(service('poli_page.error_listener')),
        ])
        ->public();

    $services->alias(PoliPage::class, 'poli_page.client')->public();

    // ─── PoliPageResponseFactory ─────────────────────────────────────────────

    $services->set('poli_page.response_factory', PoliPageResponseFactory::class)
        ->public();
    $services->alias(PoliPageResponseFactory::class, 'poli_page.response_factory')->public();
};
