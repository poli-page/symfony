# Changelog

All notable changes to `poli-page/symfony-bundle` are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Interactive demo UI** at `GET /` in the example app. Single-page editorial-style dashboard with one button per SDK feature, inline PDF/HTML previews via `<iframe>`, pretty-printed JSON for the documents and error endpoints, and copy-buttons for the CLI commands. No new dependencies (no Twig). Files: `example-app/src/Controller/DemoController.php`, `example-app/templates/demo.html`.
- `composer serve` script in `example-app/composer.json` — runs PHP's built-in server on `127.0.0.1:8000`, removing the need for the optional Symfony CLI.
- `tests/RestoresGlobalHandlers.php` trait — snapshots/restores global error/exception handler stack around kernel-booting tests.
- `tests/bootstrap.php` — replaces `vendor/autoload.php` as PHPUnit's bootstrap; loads the repo-root `.env` so integration tests pick up `POLI_PAGE_API_KEY` without a shell export.
- `example-app/bootstrap.php` — loads the repo-root `.env` before Symfony Runtime starts, so the example app uses the same key as the test suite. Deliberately does not require `vendor/autoload.php`: Symfony Runtime must load it.

### Changed
- **Environment keys.** An API key is `pp_{environment}_{32 hex}` (`pp_sandbox_…`, `pp_live_…`, `pp_staging_…`). The `api_key` config node no longer checks the key's format, so keys for any environment boot. When the client is built, a key that does not match the SDK contract's full-key pattern logs one warning through `poli_page.logger` that names the expected format `pp_{environment}_{32 lowercase hex characters}` and shows no part of the key, not even its `pp_{environment}_` prefix; the API stays the only authority on keys. An empty key is still refused by the SDK.
- **Template versions are gone.** Renders use the project's copy published in the key's environment: `poli-page:render`, the example app, the README and the integration test build a `RenderInput` with no `version`.
- `PoliPageResponseFactory::preview()` takes a `DocumentPreviewResult` only (the stored HTML of a rendered document).
- The Flex recipe seeds `POLI_PAGE_API_KEY=pp_sandbox_replace_me`.
- The integration test runs only with a `pp_sandbox_…` key (renders in other environments count toward the monthly quota).
- Example app: `GET /errors/bad-version` is replaced by `GET /errors/not-found`, which renders `getting-started` / `does-not-exist` and answers `404` with code `TEMPLATE_NOT_FOUND`; the demo steps follow the SDK demo's numbering.
- Requires `poli-page/sdk` `^0.11.0` from Packagist, the first SDK release on the environments contract (environment keys, no template versions). `composer.local.json`, which pinned a local path to `0.1.0`, is removed.
- `example-app/composer.json`: removed dead Symfony Flex `auto-scripts` hooks. Composer no longer warns on `composer install`.
- `example-app/.env`: removed the placeholder `POLI_PAGE_API_KEY` value. Value now comes from the repo-root `.env` via `example-app/bootstrap.php`. A comment in the file explains the override hierarchy.

### Removed
- `poli-page:render` options `--template-version`, `--preview` and `--html` (HTML preview and inline-HTML rendering are gone from the API).
- Example app: `GET /render/preview` and its dashboard button. `GET /documents/{id}/preview` stays.

### Fixed
- 8 PHPUnit "risky" warnings caused by Symfony's `FrameworkBundle::boot()` leaking a global error handler past test boundaries.
- A key ending in a newline now logs the malformed-key warning (the format check is anchored with `\z`, not `$`).
- `poli-page:render` and the opt-in exception listener no longer show the configured API key. A key ending in a newline makes the HTTP layer reject the `Authorization` header with the whole `Bearer <key>` value in the error message. On the default transport (nyholm/psr7 + Symfony HttpClient) the SDK raises that as a `network_error`, which the command printed and the listener returned in its JSON `message`. Both now replace the key with `[redacted API key]`, also with trailing spaces and tabs removed, the form Guzzle PSR-7 (a supported `request_factory`) puts in its message. `RenderCommand` and `PoliPageExceptionListener` take the API key as a new constructor argument, which the bundle wires. The example app's `GET /errors/not-found`, which catches the exception itself and so bypasses the listener, redacts its `message` the same way.
- `composer install` in `example-app/` no longer errors with "non-existent script @auto-scripts" on PHP 8.5+.
- `PoliPageResponseFactory::bytes()` / `stream()`: control characters (CR/LF, TAB, DEL, C1) are now stripped from the `Content-Disposition` filename instead of surviving as `?` in the fallback and `%0D%0A` in `filename*`. Filenames containing `/`, `\` or `%` no longer throw `InvalidArgumentException` from `HeaderUtils::makeDisposition()` (a 500): path separators become `_`, and `%` becomes `?` in the ASCII fallback only. The fallback now has one `?` per non-ASCII character (was one per UTF-8 byte), and a filename made only of control characters falls back to `document.pdf`.

## [0.1.0] — TBD

### Added
- Modern `AbstractBundle` with full config tree covering every `PoliPage` constructor option.
- Autowired `PoliPage` client (PSR-18 default: `symfony/http-client`; PSR-17 default: `nyholm/psr7`; PSR-3 default: `logger`).
- `PoliPageResponseFactory` with `bytes` / `stream` / `preview` / `documentRedirect` builders.
- `bin/console poli-page:render` command for end-to-end smoke testing.
- Symfony EventDispatcher integration for the SDK's `onRetry` / `onError` Closure hooks (`PoliPageRetryEvent`, `PoliPageErrorEvent`).
- Symfony Flex recipe (separate PR to `symfony/recipes-contrib`).
- Example Symfony 7 app at `example-app/` covering all 10 SDK demo steps.

[Unreleased]: https://github.com/poli-page/symfony-bundle/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/poli-page/symfony-bundle/releases/tag/v0.1.0
