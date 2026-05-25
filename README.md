# Poli Page Symfony Bundle

[![CI](https://github.com/poli-page/symfony-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/poli-page/symfony-bundle/actions/workflows/ci.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/poli-page/symfony-bundle.svg)](https://packagist.org/packages/poli-page/symfony-bundle)
[![License](https://img.shields.io/packagist/l/poli-page/symfony-bundle.svg)](LICENSE)

Official Symfony bundle for [Poli Page](https://poli.page) — render polished PDFs from HTML templates via the Poli Page API. Wraps the [official PHP SDK](https://packagist.org/packages/poli-page/sdk) with Symfony-native DI, response helpers, console tooling, and EventDispatcher integration.

→ API reference (auto-generated from source): **https://docs.poli.page/reference/sdk/php/**

## Requirements

- PHP 8.3+
- Symfony 6.4 LTS or 7.x

## Install

```bash
composer require poli-page/symfony-bundle
```

If you use Symfony Flex, the recipe registers the bundle and creates `config/packages/poli_page.yaml` + appends `POLI_PAGE_API_KEY=` to `.env` automatically.

Otherwise, register the bundle manually:

```php
// config/bundles.php
return [
    // ...
    PoliPage\Symfony\PoliPageBundle::class => ['all' => true],
];
```

And create `config/packages/poli_page.yaml`:

```yaml
poli_page:
    api_key: '%env(POLI_PAGE_API_KEY)%'
```

## Get an API key

Sign up at [app.poli.page](https://app.poli.page) (or [app-develop.poli.page](https://app-develop.poli.page) for develop), then **Settings → API Keys** → create a `pp_test_*` key. Put it in `.env.local`:

```
POLI_PAGE_API_KEY=pp_test_your_key_here
```

## Quick start — render a PDF from a controller

```php
use PoliPage\PoliPage;
use PoliPage\ProjectModeInput;
use PoliPage\Symfony\Http\PoliPageResponseFactory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class InvoiceController
{
    public function __construct(
        private readonly PoliPage $poliPage,
        private readonly PoliPageResponseFactory $factory,
    ) {}

    #[Route('/invoice/{id}', methods: ['GET'])]
    public function invoice(string $id): Response
    {
        $pdf = $this->poliPage->render->pdf(new ProjectModeInput(
            project: 'invoices',
            template: 'default',
            data: ['invoice_id' => $id],
            version: '1.0.0',
        ));

        return $this->factory->bytes($pdf, "invoice-{$id}.pdf");
    }
}
```

`$factory->bytes(...)` sets the right `Content-Type`, RFC 5987 `Content-Disposition`, `Cache-Control: no-store, private`, and `X-Content-Type-Options: nosniff` — the parts you'd otherwise get wrong.

## Smoke-test your config from the CLI

```bash
bin/console poli-page:render \
    --project=getting-started \
    --template=welcome \
    --template-version=1.0.0 \
    --data='{"name":"World"}' \
    -o welcome.pdf
```

## Full configuration

```yaml
poli_page:
    api_key: '%env(POLI_PAGE_API_KEY)%'        # required
    base_url: ~                                  # optional; SDK default applies
    timeout: ~                                   # seconds (float); SDK default applies
    user_agent: ~                                # SDK builds default
    retries:
        max_attempts: ~                          # SDK default applies
        delay_seconds: ~                         # SDK default applies
    http_client: ~                               # PSR-18 service id; default: symfony/http-client
    request_factory: ~                           # PSR-17 service id; default: nyholm/psr7
    stream_factory: ~                            # PSR-17 service id; default: nyholm/psr7
    logger: ~                                    # PSR-3 service id; default: 'logger'
    on_retry: ~                                  # service implementing __invoke(RetryEvent): void
    on_error: ~                                  # service implementing __invoke(PoliPageException): void
```

For the resolved value of any key, run:

```bash
bin/console debug:config poli_page
```

## EventDispatcher integration

The SDK fires `onRetry` / `onError` Closure hooks. The bundle bridges those into Symfony events you subscribe to like any other:

```php
use PoliPage\Symfony\Event\PoliPageRetryEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class LogPoliPageRetries
{
    public function __construct(private LoggerInterface $logger) {}

    public function __invoke(PoliPageRetryEvent $event): void
    {
        $this->logger->warning('Poli Page retry', [
            'attempt'  => $event->sdkEvent->attempt,
            'delay_ms' => $event->sdkEvent->delayMs,
            'reason'   => $event->sdkEvent->reason->getMessage(),
        ]);
    }
}
```

## Try the example app

A full runnable Symfony 7 app showing every public method of the SDK is in `example-app/`. Visit `http://127.0.0.1:8000/` after `composer serve` for an **interactive dashboard** — one button per SDK feature, inline PDF/HTML previews, no `curl` recipes to copy. See `example-app/README.md` for the full walkthrough.

## Errors

Everything thrown is a `PoliPage\PoliPageException` (or a subclass). The bundle does not catch or transform exceptions; let them propagate or handle them in your controllers / event listeners.

## Contributing

See [`CLAUDE.md`](CLAUDE.md). PRs welcome — please open an issue first for anything beyond a small fix.

## License

[MIT](LICENSE).
