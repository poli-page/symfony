# Poli Page for Symfony

> Render Poli Page documents as Symfony controller responses.

## About

This bundle wires the Poli Page PHP SDK into Symfony's container, EventDispatcher, and logger. You autowire `PoliPage` into any service or controller, return PDFs through `PoliPageResponseFactory`, configure everything in `config/packages/poli_page.yaml`, and dispatch the SDK's retry/error hooks as Symfony events you subscribe to with `#[AsEventListener]`.

**When to use this:**

- You want an autowireable `PoliPage` client that follows your service graph.
- You want the SDK's `onRetry` / `onError` hooks delivered through the EventDispatcher.
- You want a console command to verify your API key and connectivity from CI.

**When not to:**

- You don't use Symfony — install [`poli-page/sdk`](https://packagist.org/packages/poli-page/sdk) directly.
- You need to reimplement transport, retry, or error mapping — that belongs in the SDK, not in a bundle on top of it.

## Requirements

- PHP 8.3+
- Symfony 6.4 LTS or 7.x
- A Poli Page API key from [app.poli.page](https://app.poli.page)

## Install

```bash
composer require poli-page/symfony-bundle
```

With Symfony Flex the recipe registers the bundle, writes `config/packages/poli_page.yaml`, and appends `POLI_PAGE_API_KEY=` to `.env`. Without Flex, register the bundle in `config/bundles.php` and create the config file yourself (see [Configuration](#configuration)).

Set your key in `.env.local`. Each key belongs to one environment of your organization, named in the key:

| Environment                           | Key            | Watermark | Counted |
| ------------------------------------- | -------------- | --------- | ------- |
| `sandbox` (every org)                 | `pp_sandbox_…` | yes       | no      |
| `live` (every org, deletable on Pro+) | `pp_live_…`    | no        | yes     |
| created on Pro+ (e.g. `staging`)      | `pp_staging_…` | no        | yes     |

"Counted" means the render counts toward the organization's monthly PDF quota. A render uses the project's copy published in the key's environment; there is no template version to pick.

```
POLI_PAGE_API_KEY=pp_sandbox_your_key_here
```

Verify everything is wired end-to-end:

```bash
bin/console poli-page:render \
    --project=getting-started \
    --template=welcome \
    --data='{"name":"World"}' \
    -o welcome.pdf
```

## Quick start

```php
// src/Controller/InvoiceController.php
namespace App\Controller;

use PoliPage\PoliPage;
use PoliPage\RenderInput;
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
    public function show(string $id): Response
    {
        $pdf = $this->poliPage->render->pdf(new RenderInput(
            project: 'invoices',
            template: 'default',
            data: ['invoice_id' => $id],
        ));

        return $this->factory->bytes($pdf, "invoice-{$id}.pdf");
    }
}
```

## Configuration

| Option | Default | Description |
|---|---|---|
| `api_key` | _required_ | API key, `pp_{environment}_…` (see the key table above). Any non-empty value is accepted and the API decides; a value that does not look like `pp_{environment}_{32 hex}` logs one warning (showing at most the `pp_{environment}_` prefix, only when `{environment}` is a well-formed environment name) when the client is built. |
| `base_url` | SDK default | Override the API origin (must be http/https). |
| `timeout` | SDK default | Per-request timeout in seconds (0 < t ≤ 600). |
| `user_agent` | SDK default | Suffix appended to the SDK's User-Agent. |
| `retries.max_attempts` | SDK default | Retry budget (0–10). |
| `retries.delay_seconds` | SDK default | Base delay before the first retry (0–30s). |
| `http_client` | `symfony/http-client` | PSR-18 service id. |
| `request_factory` | `nyholm/psr7` | PSR-17 request-factory service id. |
| `stream_factory` | `nyholm/psr7` | PSR-17 stream-factory service id. |
| `logger` | `logger` | PSR-3 service id. |
| `on_retry` | none | Service implementing `__invoke(RetryEvent)`. |
| `on_error` | none | Service implementing `__invoke(PoliPageException)`. |

```yaml
# config/packages/poli_page.yaml
poli_page:
    api_key: '%env(POLI_PAGE_API_KEY)%'
    timeout: 30
    retries:
        max_attempts: 3
```

You inspect the resolved config with `bin/console debug:config poli_page`.

## API at a glance

| Symbol | Purpose |
|---|---|
| `PoliPage\PoliPage` | Autowired SDK client (`->render`, `->documents`). |
| `PoliPage\Symfony\Http\PoliPageResponseFactory` | Builds `bytes` / `stream` / `preview` (a stored document's HTML, `DocumentPreviewResult`) / `documentRedirect` responses with the right headers. |
| `PoliPage\Symfony\Event\PoliPageRetryEvent` | Dispatched when the SDK retries a request. |
| `PoliPage\Symfony\Event\PoliPageErrorEvent` | Dispatched when the SDK raises a `PoliPageException`. |
| `PoliPage\Symfony\PoliPageBundle` | The bundle class registered in `config/bundles.php`. |
| `poli-page:render` | Console command that renders a template end-to-end. |

Full reference: [docs/api.md](docs/api.md).

## Errors

The SDK throws `PoliPage\PoliPageException` (or a subclass). The bundle does not catch or transform them — you handle them in your controller, an event listener, or a Symfony exception subscriber. The four categories you typically discriminate on:

- **Auth** — `PoliPage\Exception\AuthenticationException`. Invalid or missing API key.
- **Rate limit** — `PoliPage\Exception\RateLimitException`. You hit the rate limit; retry per `Retry-After`.
- **Request rejected** — `PoliPage\Exception\BadRequestException`. Template or data rejected by the API.
- **Network / transport** — `PoliPage\Exception\ConnectionException` (includes `TimeoutException`). Connection failure, DNS, TLS, or timeout.

```php
use PoliPage\Exception\AuthenticationException;
use PoliPage\Exception\BadRequestException;
use PoliPage\Exception\ConnectionException;
use PoliPage\Exception\RateLimitException;
use PoliPage\PoliPageException;

try {
    $pdf = $this->poliPage->render->pdf($input);
} catch (AuthenticationException $e) {
    // re-check POLI_PAGE_API_KEY
    throw $e;
} catch (RateLimitException $e) {
    // honour $e->retryAfter
    throw $e;
} catch (BadRequestException $e) {
    // template/data rejected — surface $e->errorCode to the user
    throw $e;
} catch (ConnectionException $e) {
    // network/timeout — safe to retry the whole request
    throw $e;
} catch (PoliPageException $e) {
    // anything else from the SDK
    throw $e;
}
```

## Example app

A runnable Symfony 7 app exercising every public method of the SDK lives in [`example-app/`](example-app/). It exposes an interactive dashboard at `http://127.0.0.1:8000/` with one button per SDK feature, plus the underlying JSON / PDF routes for scripted use.

```bash
cd example-app
composer install
composer serve   # → http://127.0.0.1:8000
```

## Going further

- [docs/events.md](docs/events.md) — Subscribe to `PoliPageRetryEvent` / `PoliPageErrorEvent` with `#[AsEventListener]`.
- [docs/streaming.md](docs/streaming.md) — Stream multi-MB PDFs through `PoliPageResponseFactory::stream()`.
- [docs/responses.md](docs/responses.md) — All four response builders, their headers, and the RFC 5987 filename encoding.
- [docs/cli.md](docs/cli.md) — Every `poli-page:render` flag, exit codes, and CI recipes.

## Compatibility

| Bundle | Symfony | PHP |
|---|---|---|
| 0.1.x | 6.4 LTS / 7.x | 8.3 – 8.4 |

Supported Symfony lines follow the upstream LTS schedule.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

Released under the [MIT License](LICENSE).
