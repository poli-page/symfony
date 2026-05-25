# `poli-page/symfony-bundle` — Specification

> Self-contained specification for **v0.1.0** of the Poli Page Symfony bundle. A new agent should be able to read this document end-to-end and implement the bundle without consulting external chat history.

**Status**: approved design, ready to implement.
**Roadmap slot**: P2.3 (taken first in our integrations queue — see `/Users/mickael/Projects/INTEGRATIONS_PLAN.md` §"Order we're working" for the deliberate departure from `sdk-roadmap.md`).
**Last updated**: 2026-05-24.

---

## 1. What this bundle is, and what it isn't

**Is**: a thin Symfony bundle that wraps the official Poli Page PHP SDK (`poli-page/sdk`, see `/Users/mickael/Projects/sdk-php.md/`) so that a Symfony application can `composer require poli-page/symfony-bundle`, set one env var, and inject the `PoliPage` client into any service or controller via autowiring. Also ships a Symfony-flavored response factory for returning rendered PDFs with correct HTTP headers, a console command for smoke-testing config, and EventDispatcher integration for the SDK's retry/error hooks.

**Is not**:
- A reimplementation of HTTP, retries, error mapping, or PSR plumbing — that all lives in the SDK and is exhaustively tested there. The bundle's job is **wiring, not behavior**.
- A "kitchen sink" bundle. Twig functions, Messenger handlers, Profiler panels, Mailer integration, Cache adapters, named/multi-client config — all deferred to v0.2 (see §17).

**Quality bar**: match what these bundles deliver today: `sentry/sentry-symfony`, `algolia/search-bundle`, `aws/aws-sdk-php-symfony`, `api-platform/core`. If our shape differs from theirs, we have a reason.

---

## 2. Required reading (concrete file paths)

Before writing code, read:

| File | Why |
|---|---|
| `/Users/mickael/Projects/sdk-php.md/src/PoliPage.php` | The client class we're wiring. Note the constructor signature (§7.2 below). |
| `/Users/mickael/Projects/sdk-php.md/src/Render.php` | The `$client->render` property. Methods: `pdf`, `pdfStream`, `document`, `preview`. |
| `/Users/mickael/Projects/sdk-php.md/src/Documents.php` | The `$client->documents` property. Methods: `get`, `preview`, `thumbnails`, `delete`. |
| `/Users/mickael/Projects/sdk-php.md/src/Events/RetryEvent.php` | Payload of the `onRetry` SDK hook we'll dispatch as a Symfony event. |
| `/Users/mickael/Projects/sdk-php.md/src/Exception/` (full directory) | Exception hierarchy. Bundle does not catch or remap these — they propagate. |
| `/Users/mickael/Projects/sdk-php.md/examples/demo.php` | The 10-step canonical demo. `example-app/` mirrors this 1:1 (§14). |
| `/Users/mickael/Projects/sdk-php.md/composer.json` | SDK's composer manifest. Our bundle requires `poli-page/sdk` as a dep (§10 explains the workaround until publish). |
| `/Users/mickael/Projects/poli-page/docs/onboarding/micka/project-briefing.md` | Platform context (what Poli Page is, API model, key prefixes, develop env). |
| `/Users/mickael/Projects/poli-page/docs/onboarding/micka/sdk-specification.md` | API contract every SDK implements. |
| `/Users/mickael/Projects/INTEGRATIONS_PLAN.md` | Cross-repo verdict and order. |

Reference bundles to compare patterns against (open on GitHub):
- `getsentry/sentry-symfony` — closest in shape (third-party SDK + Symfony bundle wrapper, ships Flex recipe + console command + EventDispatcher hooks). **Primary reference.**
- `algolia/search-bundle` — bundle structure for a vendor SDK.
- `aws/aws-sdk-php-symfony` — DI binding for a constructor-heavy SDK client.
- `symfony/framework-bundle` itself — for the `debug:config` machinery we get for free.

---

## 3. Version targets

| Dimension | Constraint | Rationale |
|---|---|---|
| PHP | `^8.3` | Inherits from SDK (`poli-page/sdk` requires `^8.3`). |
| Symfony | `^6.4 \|\| ^7.0` | 6.4 is the current LTS (security until Nov 2027); enterprise audience targeted by this bundle pins to LTS. Matches Sentry/Algolia/AWS bundles. |
| Composer | `^2.5` | Required for `path` repository symlink behavior used in §10. |

CI matrix is the 4-cell grid: PHP `{8.3, 8.4}` × Symfony `{6.4, 7.x}`. See §15.

---

## 4. Architecture style

Use **modern `AbstractBundle`** (Symfony ≥ 6.1). Single bundle class declares config tree inline via `definitionConfig()` callback and loads services via `loadExtension()`. No separate `Extension` + `Configuration` classes.

**Why**: it's what current Symfony docs lead with for new bundles, our config tree is small (6 keys), Sentry migrated to this pattern recently. The classic three-class scaffold is fine but redundant for our shape.

DI services declared in **PHP-based config** (`config/services.php`), not YAML or XML — type-safe and what current Symfony recommends.

---

## 5. File layout

```
symfony-bundle/
├── src/
│   ├── PoliPageBundle.php                          # AbstractBundle: config tree + service loading
│   ├── Http/
│   │   └── PoliPageResponseFactory.php             # PDF/HTML response helper (§8)
│   ├── Console/
│   │   └── RenderCommand.php                       # bin/console poli-page:render (§9)
│   └── Event/
│       ├── PoliPageRetryEvent.php                  # Dispatched on SDK retry (§10)
│       └── PoliPageErrorEvent.php                  # Dispatched on SDK terminal failure (§10)
├── config/
│   └── services.php                                # PHP DI definitions
├── tests/
│   ├── Unit/
│   │   ├── PoliPageBundleTest.php                  # boot kernel, assert services resolve
│   │   ├── ConfigurationTest.php                   # config validation cases
│   │   ├── Http/
│   │   │   └── PoliPageResponseFactoryTest.php
│   │   ├── Console/
│   │   │   └── RenderCommandTest.php
│   │   └── Event/
│   │       └── EventDispatcherIntegrationTest.php  # onRetry/onError → events
│   ├── Integration/
│   │   └── RenderAgainstDevelopApiTest.php         # gated on POLI_PAGE_API_KEY
│   └── Fixtures/
│       └── TestKernel.php                          # minimal kernel for KernelTestCase
├── example-app/                                    # see §14
├── recipes/                                        # Flex recipe source (PR'd separately to symfony/recipes-contrib)
│   ├── manifest.json
│   └── config/packages/poli_page.yaml
├── composer.json
├── composer.local.json                             # dev-time override, deleted at v0.1.0 publish (§10)
├── phpunit.xml.dist
├── phpstan.neon                                    # level 8
├── .php-cs-fixer.dist.php
├── .github/workflows/ci.yml
├── README.md
├── CHANGELOG.md                                    # Keep a Changelog format
├── LICENSE                                         # MIT
└── CLAUDE.md                                       # replaces the inherited SDK-flavored template
```

**File count**: 5 source files (`PoliPageBundle`, `PoliPageResponseFactory`, `RenderCommand`, two event classes). That is the entire bundle. Anything beyond is scope creep — refer to §17 before adding.

---

## 6. Configuration tree

User-facing YAML in `config/packages/poli_page.yaml`:

```yaml
poli_page:
    api_key: '%env(POLI_PAGE_API_KEY)%'   # required
    base_url: ~                            # optional; SDK applies its own default (https://api.poli.page)
    timeout: ~                             # optional, seconds (float); SDK default applies when omitted
    user_agent: ~                          # optional; SDK builds default 'poli-page-php/{version}' when omitted
    retries:
        max_attempts: ~                    # optional integer; SDK default applies when omitted
        delay_seconds: ~                   # optional float SECONDS (not ms — match SDK constructor); SDK default applies when omitted
    http_client: ~                         # optional service id implementing Psr\Http\Client\ClientInterface
                                           # default: psr18-adapter over symfony/http-client
    request_factory: ~                     # optional Psr\Http\Message\RequestFactoryInterface service id
                                           # default: nyholm/psr7 factory
    stream_factory: ~                      # optional Psr\Http\Message\StreamFactoryInterface service id
                                           # default: nyholm/psr7 factory
    logger: ~                              # optional Psr\Log\LoggerInterface service id
                                           # default: 'logger' (monolog)
    on_retry: ~                            # optional service id implementing __invoke(RetryEvent): void
    on_error: ~                            # optional service id implementing __invoke(PoliPageException): void
```

**One-to-one mapping with the SDK `PoliPage` constructor parameters** — no Symfony-only invented options, no SDK options omitted.

**Default-value discipline**: for every option except `api_key`, the bundle's config tree defaults to `null` and the bundle passes `null` to the SDK constructor when not set. The SDK alone owns default values (via its own `Constants` class). Reason: a single source of truth for defaults means a future change in the SDK (e.g. raising `DEFAULT_TIMEOUT` from 30 to 60 seconds) takes effect for bundle users automatically, without a bundle release. Never duplicate a default literal across SDK and bundle.

### 6.1 Validation rules (enforced in the config tree)

- `api_key`: required, non-empty string, **must match `/^pp_(test|live)_/`**. The regex catches the #1 misconfiguration: pasting a dashboard token instead of an API key. Error message: `"Poli Page API key must start with pp_test_ or pp_live_. Get one at https://app.poli.page/settings/api-keys."`
- `base_url`: nullable, if set must be a valid URL with `http`/`https` scheme.
- `timeout`: numeric, > 0, ≤ 600.
- `retries.max_attempts`: integer, ≥ 0, ≤ 10.
- `retries.delay_seconds`: numeric, ≥ 0, ≤ 30.
- `http_client`, `request_factory`, `stream_factory`, `logger`, `on_retry`, `on_error`: nullable strings (service IDs); existence in the container is validated by Symfony's DI compilation, not by us.

### 6.2 Environment variable convention

`POLI_PAGE_API_KEY` is the documented env var name. The Flex recipe (§11) appends it to `.env` automatically. The bundle does not read env vars directly — it reads from the resolved config tree, exactly like Symfony recommends.

---

## 7. DI services & wiring

### 7.1 Service map

Declared in `config/services.php`:

| Service ID | Class | Public alias | Notes |
|---|---|---|---|
| `poli_page.client` | `PoliPage\PoliPage` | yes, aliased as `PoliPage\PoliPage` (autowireable) | Constructed once per request scope (singleton). |
| `poli_page.response_factory` | `PoliPage\Symfony\Http\PoliPageResponseFactory` | yes, aliased as the FQCN | Stateless. |
| `poli_page.command.render` | `PoliPage\Symfony\Console\RenderCommand` | private, tagged `console.command` | §9. |
| `poli_page.retry_listener` | internal closure adapter | private | Wraps EventDispatcher dispatch into a `Closure(RetryEvent): void` passed to the SDK constructor. §10. |
| `poli_page.error_listener` | internal closure adapter | private | Same pattern for `onError`. §10. |
| `poli_page.http_client` | `Psr\Http\Client\ClientInterface` | private, alias to `Symfony\Component\HttpClient\Psr18Client` by default; user-overridable via `http_client` config | §7.3. |
| `poli_page.request_factory` | `Psr\Http\Message\RequestFactoryInterface` | private, alias to `Nyholm\Psr7\Factory\Psr17Factory` by default; user-overridable | §7.3. |
| `poli_page.stream_factory` | `Psr\Http\Message\StreamFactoryInterface` | private, alias to `Nyholm\Psr7\Factory\Psr17Factory` (same instance) by default; user-overridable | §7.3. |

**Property access on `PoliPage` is free**: `$client->render` and `$client->documents` are real properties (see SDK constructor, last two lines). No extra DI bindings needed for `Render` or `Documents` — users access them as `$this->poliPage->render->pdf(...)`. This matches the SDK's idiomatic usage in `examples/demo.php`.

### 7.2 SDK constructor mapping

Reference (from `/Users/mickael/Projects/sdk-php.md/src/PoliPage.php:76-93`):

```php
public function __construct(
    string $apiKey,
    ?string $baseUrl = null,
    ?int $maxRetries = null,
    ?float $retryDelay = null,        // seconds (float)
    ?float $timeout = null,
    ?ClientInterface $httpClient = null,
    ?RequestFactoryInterface $requestFactory = null,
    ?StreamFactoryInterface $streamFactory = null,
    ?LoggerInterface $logger = null,
    ?\Closure $onRetry = null,
    ?\Closure $onError = null,
    ?\Closure $jitterSource = null,   // test hook, do NOT expose
)
```

DI binding (PHP config):

```php
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

$services->set('poli_page.client', PoliPage::class)
    ->args([
        '$apiKey'         => param('poli_page.api_key'),
        '$baseUrl'        => param('poli_page.base_url'),         // null when unset → SDK default
        '$maxRetries'     => param('poli_page.retries.max_attempts'),
        '$retryDelay'     => param('poli_page.retries.delay_seconds'),
        '$timeout'        => param('poli_page.timeout'),
        '$httpClient'     => service('poli_page.http_client'),
        '$requestFactory' => service('poli_page.request_factory'),
        '$streamFactory'  => service('poli_page.stream_factory'),
        '$logger'         => service('poli_page.logger'),
        '$onRetry'        => service('poli_page.retry_listener')->closure(),
        '$onError'        => service('poli_page.error_listener')->closure(),
    ])
    ->public()
    ->autowire(false);

$services->alias(PoliPage::class, 'poli_page.client')->public();
```

Two important details in this binding:

1. **Container parameters from config**: the bundle's `loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder)` method must set `poli_page.api_key`, `poli_page.base_url`, etc. as container parameters from the resolved `$config` array before loading `services.php`. Parameters not set in YAML resolve to `null` (because the config tree defaults are `null`) and pass through to the SDK constructor as `null` — triggering the SDK's own defaults.
2. **`->closure()` on the listener services**: type-hinted `?\Closure` parameters need a `Closure`, not the raw service. The `service('id')->closure()` DSL emits `\Closure::fromCallable($container->get('id'))` automatically. Without `->closure()`, Symfony will pass the raw `RetryListener` object and the SDK constructor will fail type-checking.

`$jitterSource` is **not** exposed in config — it's a test-only hook in the SDK and must not appear in user-facing surface.

### 7.3 Default PSR providers

If `http_client`, `request_factory`, `stream_factory` are unset in config, the bundle binds these defaults:

- HTTP client: `symfony/http-client`'s built-in `Psr18Client` (wraps `HttpClientInterface`). Adds `symfony/http-client` as a `composer.json` `require` (not just `require-dev`). Composer satisfies it from any Symfony app already.
- Request/stream factory: `nyholm/psr7`'s `Psr17Factory`. Add `nyholm/psr7` as a `require`. The SDK already `suggest`s it.

Users with a different PSR-18 client (Guzzle, Buzz) override by setting `http_client: app.my_guzzle_client` (service ID) in config.

### 7.4 Logger default

Bundle binds `logger` (the standard Symfony service ID, provided by `monolog/monolog` or the framework's `NullLogger` fallback). Users can override with any PSR-3 service ID.

---

## 8. `PoliPageResponseFactory` contract

Single class, `PoliPage\Symfony\Http\PoliPageResponseFactory`, four public methods. **No state.** Pure transformation from SDK output → Symfony Response.

### 8.1 Signatures

```php
final class PoliPageResponseFactory
{
    public function bytes(
        string $pdf,
        string $filename = 'document.pdf',
        bool $inline = false,
    ): Response;

    public function stream(
        StreamInterface $stream,
        string $filename = 'document.pdf',
        bool $inline = false,
    ): StreamedResponse;

    public function preview(PreviewResult|DocumentPreviewResult $preview): Response;

    public function documentRedirect(DocumentDescriptor $doc): RedirectResponse;
}
```

### 8.2 Headers each method sets

`bytes()` and `stream()`:
- `Content-Type: application/pdf`
- `Content-Length: <byte count>` (computed on `bytes()`; omitted on `stream()` if not known)
- `Content-Disposition: attachment; filename="..."; filename*=UTF-8''...` (or `inline` if `$inline === true`) — RFC 5987 encoding for non-ASCII filenames
- `Cache-Control: private, no-store` — PDFs typically contain personalized data; never let intermediaries cache
- `X-Content-Type-Options: nosniff`

`preview()`:
- `Content-Type: text/html; charset=utf-8`
- `Cache-Control: private, no-store`

`documentRedirect()`:
- HTTP 302
- `Location: <descriptor->url>` (the presigned URL from the SDK's `DocumentDescriptor`)
- `Cache-Control: private, no-store` (the presigned URL has its own expiry, but never cache the redirect itself)

### 8.3 Filename encoding

RFC 5987 helper: if `$filename` is pure ASCII, emit only `filename="..."`. If it contains non-ASCII, emit both `filename="..."` (with ASCII fallback) and `filename*=UTF-8''<percent-encoded>`. This is the part users get wrong; the helper exists to make sure they don't have to think about it.

Reference: `Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition()` provides this exact logic. Use it.

---

## 9. `bin/console poli-page:render` command

**Purpose**: smoke-test that the bundle's config + the SDK + the user's API key work end-to-end, from inside any Symfony project, without writing a controller.

### 9.1 Signature

```
poli-page:render [options]

  --project=PROJECT        Project slug (required unless --html given)
  --template=TEMPLATE      Template slug (required)
  --template-version=VER   Template version (required unless --html given)
                           (named --template-version, not --version, because
                           Symfony Console reserves -V/--version globally)
  --data=JSON              Inline JSON for the data payload
  --data-file=PATH         Read data payload from a file
  --html=PATH              Inline-mode: render raw HTML from a file instead of a published template
  -o, --output=PATH        Output file path (default: ./poli-page-render.pdf)
  --preview                Render HTML preview instead of PDF; writes to .html
```

### 9.2 Behavior

- Resolves `PoliPage` from the container.
- Builds a `ProjectModeInput` or `InlineModeInput` from the options.
- Calls `render->pdf()` (or `render->preview()` if `--preview`).
- Writes to `--output`.
- Prints a one-line success: `Rendered <bytes> bytes in <ms>ms (requestId=<id>). Wrote to <path>.`
- On `PoliPageException`: print a structured error (`status`, `code`, `requestId`, `detail`) and exit with code matching the exception family (4xx → 1, 5xx → 2, network → 3).

### 9.3 Output expectations

- Default output path is `./poli-page-render.pdf` so a curious user can run with zero `-o` flag and immediately have a file.
- `--data-file` accepts `-` for stdin (Unix convention).

---

## 10. EventDispatcher integration

The SDK exposes `onRetry: Closure(RetryEvent): void` and `onError: Closure(PoliPageException): void` constructor hooks. We surface these as **Symfony events** so users can subscribe with normal `#[AsEventListener]`.

### 10.1 Event classes

```php
namespace PoliPage\Symfony\Event;

final class PoliPageRetryEvent
{
    public function __construct(
        public readonly \PoliPage\Events\RetryEvent $sdkEvent,
    ) {}
}

final class PoliPageErrorEvent
{
    public function __construct(
        public readonly \PoliPage\PoliPageException $exception,
    ) {}
}
```

Wrappers, not reimplementations. Carry the SDK's own event/exception verbatim so users have full access to status, code, requestId, attempt number, etc.

### 10.2 Wiring

`poli_page.retry_listener` is an internal service:

```php
final class RetryListener
{
    public function __construct(private readonly EventDispatcherInterface $dispatcher) {}

    public function __invoke(\PoliPage\Events\RetryEvent $event): void
    {
        $this->dispatcher->dispatch(new PoliPageRetryEvent($event));
    }
}
```

Same shape for `ErrorListener`.

The bundle passes `service('poli_page.retry_listener')` as the `$onRetry` constructor argument. Symfony's autowiring converts a callable service into a `Closure` automatically when type-hinted as `\Closure`.

### 10.3 User-defined hooks (config-level)

If the user sets `poli_page.on_retry: app.my_listener` in config, **that hook fires INSTEAD of dispatching the Symfony event** (not in addition). Rationale: keep the SDK's single-Closure constraint visible; users who want both can write a listener that dispatches the event manually. This is the same trade-off Sentry's bundle makes for its `before_send` callback.

### 10.4 Listener example (user code, for docs)

```php
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

---

## 11. Symfony Flex recipe

Shipped as a separate PR to `symfony/recipes-contrib`. Lives in `recipes/` in this repo as the canonical source.

### 11.1 `recipes/manifest.json`

```json
{
    "bundles": {
        "PoliPage\\Symfony\\PoliPageBundle": ["all"]
    },
    "copy-from-recipe": {
        "config/": "%CONFIG_DIR%/"
    },
    "env": {
        "POLI_PAGE_API_KEY": "pp_test_replace_me"
    }
}
```

### 11.2 `recipes/config/packages/poli_page.yaml`

```yaml
poli_page:
    api_key: '%env(POLI_PAGE_API_KEY)%'
```

Single-line config so the user can `composer require` and immediately try `bin/console poli-page:render` with no further setup. All other options take SDK defaults.

### 11.3 Registration

After v0.1.0 publishes on Packagist, open a PR to `github.com/symfony/recipes-contrib` adding the recipe at `poli-page/symfony-bundle/0.1/`. Until then, users wire the bundle manually (documented in README).

---

## 12. Unpublished SDK workaround — Composer Merge Plugin

**Problem**: `poli-page/sdk` is not yet on Packagist and we don't want to publish it yet. The bundle nonetheless needs to `composer install` against the local SDK source at `/Users/mickael/Projects/sdk-php.md/`.

**Constraint**: when the SDK does publish (any time after we ship v0.1.0), zero changes are required to the bundle's source code or its published `composer.json`.

### 12.1 Solution

The bundle's main `composer.json` declares the SDK requirement **cleanly**, as if Packagist already serves it:

```json
{
    "name": "poli-page/symfony-bundle",
    "require": {
        "php": "^8.3",
        "symfony/framework-bundle": "^6.4 || ^7.0",
        "symfony/http-client": "^6.4 || ^7.0",
        "nyholm/psr7": "^1.8",
        "psr/http-client": "^1.0",
        "psr/http-message": "^1.1 || ^2.0",
        "psr/log": "^3.0",
        "poli-page/sdk": "^0.1"
    },
    "require-dev": {
        "wikimedia/composer-merge-plugin": "^2.1",
        "symfony/phpunit-bridge": "^7.0",
        "phpunit/phpunit": "^11.0",
        "phpstan/phpstan": "^2.0",
        "phpstan/phpstan-symfony": "^2.0",
        "friendsofphp/php-cs-fixer": "^3.64"
    },
    "extra": {
        "merge-plugin": {
            "include": [ "composer.local.json" ],
            "recurse": false,
            "replace": false,
            "ignore-duplicates": false,
            "merge-dev": true,
            "merge-extra": false,
            "merge-extra-deep": false,
            "merge-scripts": false
        }
    },
    "config": {
        "allow-plugins": {
            "wikimedia/composer-merge-plugin": true,
            "php-http/discovery": true
        }
    }
}
```

A second file, `composer.local.json` (committed to the repo, deleted at v0.1.0 publish), supplies the path repository:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../sdk-php.md",
            "options": {
                "symlink": true,
                "versions": {
                    "poli-page/sdk": "0.1.0"
                }
            }
        }
    ]
}
```

The `versions` map tells Composer to treat the local copy as exactly `0.1.0`, so the bundle's `"poli-page/sdk": "^0.1"` constraint resolves against it.

### 12.2 What changes when the SDK publishes

1. `git rm composer.local.json`
2. `composer remove --dev wikimedia/composer-merge-plugin`
3. Remove the `extra.merge-plugin` and `config.allow-plugins.wikimedia/composer-merge-plugin` entries from `composer.json`.
4. `composer update poli-page/sdk` — resolves from Packagist this time.
5. Tag and publish v0.1.0.

**The bundle's source code (everything in `src/`, `tests/`, `config/`) does not change.** The only changes are dev-environment housekeeping (removing the override mechanism).

### 12.3 CI handling

`.github/workflows/ci.yml` includes a step before `composer install`:

```yaml
- name: Checkout SDK alongside bundle
  uses: actions/checkout@v4
  with:
      repository: poli-page/sdk-php
      path: ../sdk-php.md
      ref: main
```

This makes `../sdk-php.md` resolve correctly inside the CI runner just as it does on the maintainer's machine. After v0.1.0 publishes, this step is also removed (alongside the `composer.local.json` removal).

### 12.4 example-app workaround

`example-app/composer.json` is a **separate** Composer project. It uses its own path-repo block (no merge plugin needed — example-app is not a published artifact, so the path repos can live in its main composer.json forever):

```json
{
    "repositories": [
        { "type": "path", "url": "../", "options": { "symlink": true } },
        { "type": "path", "url": "../../sdk-php.md", "options": { "symlink": true } }
    ],
    "require": {
        "poli-page/symfony-bundle": "@dev",
        "poli-page/sdk": "@dev",
        "symfony/framework-bundle": "^7.0"
    }
}
```

This stays as-is forever — example-app is meant to install from local sources, not Packagist.

---

## 13. Testing strategy

### 13.1 Layers

**Unit tests** (90%+ of the suite, run in milliseconds, no network):

| Test | What it covers |
|---|---|
| `PoliPageBundleTest` | Boot a `TestKernel` with the bundle + a `poli_page.yaml` fixture. Assert `PoliPage` is autowireable. Assert config values reach the constructor (snapshot the resolved `Definition`). Assert defaults apply when keys are omitted. |
| `ConfigurationTest` | Drive `Configuration::getConfigTreeBuilder()` directly. Assert valid configs pass; assert invalid `api_key` (no `pp_` prefix), `timeout` (`-1`, `0`, `601`), `retries.max_attempts` (`-1`, `11`) raise `InvalidConfigurationException` with the expected message. |
| `PoliPageResponseFactoryTest` | Each of the 4 methods returns the correct response class with the correct headers. Cover ASCII and non-ASCII filenames (verify RFC 5987 encoding). Verify `inline: true` flips disposition. |
| `RenderCommandTest` | Use `CommandTester`. Stub the `PoliPage` service. Assert flag → input mapping (`--html` builds `InlineModeInput`, otherwise `ProjectModeInput`). Assert output file is written. Assert `PoliPageException` surfaces with the right exit code per family. |
| `EventDispatcherIntegrationTest` | Boot kernel, register a test listener for `PoliPageRetryEvent`, manually invoke `poli_page.retry_listener` with a fake `RetryEvent`, assert the listener fires with the right payload. Same for error event. |

**Integration test** (single test, gated):

`RenderAgainstDevelopApiTest`:
- Skipped automatically when `POLI_PAGE_API_KEY` env var is unset (so PR contributors without a key get green local runs).
- Boots `TestKernel`, resolves `PoliPage`, renders the canonical `getting-started/welcome` template against `https://api-develop.poli.page`.
- Asserts the result is non-empty bytes whose first 5 bytes are `%PDF-`.
- That's it. One test, idempotent, ~3 seconds when it runs.

### 13.2 What we explicitly do NOT test

Anything tested by the SDK:
- HTTP transport behavior (Guzzle/Symfony HTTP client edge cases).
- Retry policy (exponential backoff, max attempts, `Retry-After` parsing, never retrying 4xx).
- 4xx / 5xx → exception mapping.
- Idempotency-key generation.
- PSR-18/17 plumbing.
- Stream handling, byte-range correctness, etc.

The bundle wraps — it does not re-test. If a bug in those areas appears, fix it in the SDK.

### 13.3 Tooling

- **PHPUnit 11** (modern attribute-based test definitions, no docblock annotations).
- **`symfony/phpunit-bridge`** for deprecation tracking — fails CI if our code (or our deps) raise Symfony deprecations.
- **PHPStan level 8**, `phpstan/phpstan-symfony` extension enabled.
- **PHP CS Fixer** with the `@Symfony` rule set + project-specific overrides.

---

## 14. `example-app/` structure

A minimal Symfony 7.4 skeleton that demonstrates every public method of the SDK through Symfony idioms. **Mirrors `examples/demo.php` in the SDK 1:1**, so a reader can put the two files side-by-side and verify the bundle adds shape, not behavior.

### 14.1 Layout

```
example-app/
├── composer.json                       # path-repo to bundle + SDK (see §12.4)
├── .env                                # POLI_PAGE_API_KEY=
├── config/
│   ├── bundles.php                     # FrameworkBundle + PoliPageBundle
│   └── packages/
│       ├── framework.yaml
│       └── poli_page.yaml              # api_key only
├── src/
│   ├── Kernel.php
│   ├── Controller/
│   │   ├── RenderController.php        # routes for SDK demo.php steps 1, 2, 4
│   │   └── DocumentController.php      # routes for steps 5, 6, 7, 8, 9
│   └── Command/
│       └── RenderToFileCommand.php     # step 3 (free function `renderToFile`)
├── public/index.php
├── bin/console
└── README.md                           # `composer install && symfony serve && curl localhost:8000/invoice.pdf`
```

### 14.2 Route-to-demo mapping

| SDK demo step | example-app endpoint | Method called |
|---|---|---|
| 1. `render->pdf()` | `GET /render/pdf` | `$client->render->pdf(...)` → `$factory->bytes(...)` |
| 2. `render->pdfStream()` | `GET /render/stream` | `$client->render->pdfStream(...)` → `$factory->stream(...)` |
| 3. `renderToFile()` | `bin/console app:demo:render-to-file` | The free function from `src/render_to_file.php` (example-app's own command, in `app:` namespace to avoid clashing with the bundle's `poli-page:render` command) |
| 4. `render->preview()` | `GET /render/preview` | `$client->render->preview(...)` → `$factory->preview(...)` |
| 5. `render->document()` | `POST /documents` | `$client->render->document(...)` returns descriptor as JSON |
| 6. `documents->get(id)` | `GET /documents/{id}` | `$factory->documentRedirect(...)` (302 to presigned URL) |
| 7. `documents->thumbnails(id)` | `GET /documents/{id}/thumbnails` | Returns base64 thumbnails as JSON |
| 8. `documents->preview(id)` | `GET /documents/{id}/preview` | `$factory->preview(...)` |
| 9. `documents->delete(id)` | `DELETE /documents/{id}` | Returns 204 |
| 10. Error handling | `GET /errors/bad-version` | Deliberately triggers 400 INVALID_VERSION_FORMAT, returns the exception as JSON |

### 14.3 What example-app proves

- The bundle's autowiring works in a real Symfony app (not just `KernelTestCase`).
- The PDF actually streams to a browser with the right headers (open in Chrome, see the PDF render).
- Every SDK surface is reachable through DI without manual `new PoliPage(...)` calls.
- A reader who knows the SDK can read the controllers and immediately see the wrapping pattern.

---

## 15. CI matrix

`.github/workflows/ci.yml`:

```yaml
name: CI
on:
  push:
  pull_request:
    branches: [main]

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['8.3', '8.4']
        symfony: ['6.4.*', '7.*']
    steps:
      # Both repos must end up as sibling dirs so composer.local.json's
      # `url: ../sdk-php.md` resolves. Checkout the bundle into a subdir,
      # then the SDK as its sibling.
      - uses: actions/checkout@v4
        with:
          path: symfony-bundle
      - uses: actions/checkout@v4
        with:
          repository: poli-page/sdk-php
          path: sdk-php.md
          ref: main
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none
      - name: Pin Symfony version
        working-directory: symfony-bundle
        run: composer config extra.symfony.require "${{ matrix.symfony }}"
      - name: Install
        working-directory: symfony-bundle
        run: composer update --prefer-dist --no-progress
      - name: Lint
        working-directory: symfony-bundle
        run: vendor/bin/php-cs-fixer check --diff
      - name: Static analysis
        working-directory: symfony-bundle
        run: vendor/bin/phpstan analyse
      - name: Unit tests
        working-directory: symfony-bundle
        run: vendor/bin/phpunit --testsuite=unit

  integration:
    runs-on: ubuntu-latest
    if: github.event_name == 'push' && github.ref == 'refs/heads/main'
    steps:
      - uses: actions/checkout@v4
        with:
          path: symfony-bundle
      - uses: actions/checkout@v4
        with:
          repository: poli-page/sdk-php
          path: sdk-php.md
          ref: main
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
      - working-directory: symfony-bundle
        run: composer update --prefer-dist
      - name: Integration test against develop API
        working-directory: symfony-bundle
        env:
          POLI_PAGE_API_KEY: ${{ secrets.POLI_PAGE_DEVELOP_API_KEY }}
        run: vendor/bin/phpunit --testsuite=integration
```

Note: `actions/checkout` requires the `path:` to be inside `$GITHUB_WORKSPACE`, so both repos check out as siblings under the workspace root. `composer.local.json`'s `../sdk-php.md` then resolves correctly relative to `symfony-bundle/`. This is exactly the same relative layout the maintainer has locally (`/Users/mickael/Projects/symfony-bundle/` + `/Users/mickael/Projects/sdk-php.md/`).

**Auto-skip behavior** (inherited from the SDK CI convention): each step short-circuits if the relevant config file is missing. Don't change this — a freshly scaffolded repo must be green from day one.

Once SDK publishes (§12.2), remove the "Checkout SDK alongside bundle" step.

---

## 16. Versioning & release

- **SemVer**. v0.x while the API stabilizes, mirroring the SDK's own `0.x` early-life.
- **`CHANGELOG.md`** in [Keep a Changelog](https://keepachangelog.com/) format. Updated in the same commit as every version bump.
- **Conventional Commits** for every commit (`feat:`, `fix:`, `docs:`, `chore:`, `refactor:`, `test:`).
- **Release process**:
  1. Bump version in `CHANGELOG.md`.
  2. `git tag v0.x.y && git push --tags`.
  3. Packagist webhook publishes automatically (one-time setup: register the package on Packagist after first release).
- **v0.1.0 launch sequence**:
  1. Land the unpublished-SDK workaround removal (§12.2).
  2. Verify CI green on all 4 matrix cells.
  3. Tag v0.1.0.
  4. Open PR to `symfony/recipes-contrib` with the Flex recipe (§11.3).
  5. Write a launch blog post on poli.page/blog (optional but recommended for the enterprise audience).

---

## 17. Deferred to v0.2+ (do not build in v0.1.0)

Calling these out explicitly so they don't sneak in mid-implementation. Each has a real use case but adds maintenance surface beyond v0.1.0's scope.

| Feature | Why deferred |
|---|---|
| **Twig functions** (`{{ poli_page_url(...) }}`, `{{ poli_page_render(...) }}`) | Niche use case; needs design work on caching/perf. Easy to add later without breaking v0.1 API. |
| **Messenger handler** for async rendering | Requires schema for `RenderMessage`, retry config, dead-letter handling. Substantial spec on its own. |
| **Profiler/WebDebugToolbar data collector** | Requires intercepting all SDK calls (decorator pattern). Real value but not blocking. |
| **Symfony Mailer integration** (auto-attach PDF) | Easy DX win but very specific use case; better as a recipe than wiring. |
| **Cache adapter for `documents->get(id)`** | Premature optimization — no evidence users need it. |
| **Named/multi-client config** (`clients.live`, `clients.test`) | Decided already (§"Multi-client" in design discussion). v0.2 add-on; v0.1 single-client config is purely additive. |
| **`#[AsPoliPageRender]` controller attribute** | Cute but YAGNI; users can return responses directly via the factory. |
| **Health check / `/_health` endpoint** | Niche; better via the existing `bin/console poli-page:render` smoke check. |
| **Sentry-style auto-instrumentation** (HTTP spans, breadcrumbs) | Real value but only if users are already using Sentry — add as a separate `sentry-extension` package later. |

**Discipline rule**: when implementing, if a "small addition" feels tempting, check this list first. If it's here, defer. If it's not here, ask before adding.

---

## 18. Decision log

Capturing the "why we chose X" so future-agents don't relitigate:

| Decision | Choice | Why |
|---|---|---|
| Bundle architecture style | Modern `AbstractBundle` | Current Symfony recommendation, less boilerplate, Sentry's current pattern. Classic 3-class scaffold is fine but unnecessary for our config size. |
| Multi-client support in v0.1.0 | Single client only | Covers ~70% of cases. Multi-client lands cleanly in v0.2 without breaking v0.1 API. |
| Symfony version range | `^6.4 \|\| ^7.0` | Enterprise audience pins to LTS; 6.4 has security support through Nov 2027. Matches Sentry/Algolia/AWS bar. |
| PHP version | `^8.3` | Inherits from SDK; no reason to deviate. |
| `base_url` default | `https://api.poli.page` | Minimal config wins over "force user to think about develop vs prod". The `pp_test_*` / `pp_live_*` prefix already separates concerns. |
| `PoliPageResponseFactory` inclusion | Yes, in v0.1.0 | Users get headers wrong without it; this is the one genuine Symfony-flavored value beyond DI wiring. |
| Config format | PHP (`services.php`) | Type-safe, current Symfony recommendation. |
| Unpublished SDK workaround | Composer Merge Plugin + `composer.local.json` | Bundle's published `composer.json` correct from day one; dev override lives in a separate, deletable file. Considered: path repo in main composer.json (rejected — leaves warning for end users); publish stub SDK (rejected — user explicitly doesn't want to publish yet). |
| Test framework | PHPUnit 11 + `KernelTestCase` | PHPUnit 11 has attribute-based test definitions (modern). `KernelTestCase` from `symfony/framework-bundle` is the standard way to test a bundle. |
| Flex recipe | Yes, ship in v0.1.0 | Quality bundle bar — Sentry, Algolia, Doctrine all ship one. Without it, "auto-discovery" doesn't work and users hand-edit `bundles.php` + `config/packages/`. |
| EventDispatcher for retry/error | Yes, in v0.1.0 | Sentry pattern; ~40 lines; turns SDK Closure hooks into idiomatic Symfony listeners. High DX value. |
| `bin/console poli-page:render` | Yes, in v0.1.0 | Lets any Symfony dev sanity-check config from any project. Sentry's `sentry:test` equivalent. Direct industry parity. |

---

## 19. Implementation order (for the agent picking this up)

A suggested commit-by-commit sequence — each commit ships green CI, each step is independently reviewable. Strict TDD per the inherited convention (RED → GREEN → refactor).

1. **`chore: bootstrap composer.json + CI workflow`** — manifest, CI stub, php-cs-fixer + phpstan configs. CI green (auto-skip on missing tests).
2. **`chore: add composer.local.json + merge plugin for local SDK`** — §12.1 mechanism. Verify `composer install` resolves SDK from `../sdk-php.md/`.
3. **`feat: PoliPageBundle skeleton with empty config tree`** — modern `AbstractBundle`, no services yet. First passing test: bundle boots in a `TestKernel`.
4. **`feat: configuration tree with validation`** — §6.1 rules. `ConfigurationTest` covers all cases.
5. **`feat: wire PoliPage client service with all constructor args`** — §7.2. Default PSR providers (§7.3). Test asserts client resolves and constructor args match config.
6. **`feat: PoliPageResponseFactory with bytes/stream/preview/documentRedirect`** — §8. Unit tests for each method's headers.
7. **`feat: EventDispatcher integration for retry/error hooks`** — §10. Event classes, internal listener services.
8. **`feat: bin/console poli-page:render command`** — §9. `CommandTester` coverage.
9. **`test: integration test against develop API (gated)`** — §13.1.
10. **`feat: example-app demonstrating all 10 SDK methods`** — §14. Add a README walkthrough.
11. **`docs: replace inherited CLAUDE.md with integration-flavored version`** — drop the SDK-flavored "test 4xx mapping / retry backoff" sections; replace with bundle-flavored guidance per `INTEGRATIONS_PLAN.md`.
12. **`docs: README, CHANGELOG initial entry`** — install snippet, 5-line quick start, link to docs.poli.page, dependency on `poli-page/sdk`.
13. **`feat: ship Flex recipe in recipes/`** — §11. Open PR to `symfony/recipes-contrib` after v0.1.0 tags.

Estimated effort: **3-5 working days** for a single agent with this spec in hand.

---

## 20. Open questions (none blocking v0.1.0)

- Should the bundle's `User-Agent` augmentation include the bundle's own version (`poli-page-symfony-bundle/0.1.0`) alongside the SDK's default? Probably yes; defer to first PR review.
- Does `symfony/recipes-contrib` accept recipes for pre-1.0 packages? If not, ship the recipe inside the bundle itself with a one-line README note.
- Should integration test poll for `Retry-After` behavior, or trust the SDK's own coverage? Trust the SDK; do not test retry behavior here.

These are noted, not blocking. Implementor can decide at first encounter.
