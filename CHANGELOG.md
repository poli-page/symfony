# Changelog

All notable changes to `poli-page/symfony-bundle` are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.11.0] - 2026-10-30

First release on Packagist. The 0.1.0 development line was never published; the Changed, Removed and Fixed entries below describe changes made during that line.

### Added
- Modern `AbstractBundle` with full config tree covering every `PoliPage` constructor option.
- Autowired `PoliPage` client (PSR-18 default: `symfony/http-client`; PSR-17 default: `nyholm/psr7`; PSR-3 default: `logger`).
- `PoliPageResponseFactory` with `bytes` / `stream` / `preview` / `documentRedirect` builders.
- `bin/console poli-page:render` command for end-to-end smoke testing.
- Symfony EventDispatcher integration for the SDK's `onRetry` / `onError` Closure hooks (`PoliPageRetryEvent`, `PoliPageErrorEvent`).
- Symfony Flex recipe source in `recipes/`, to be submitted to `symfony/recipes-contrib` (until it is accepted, register the bundle by hand as the README shows).
- Example Symfony 7 app at `example-app/` covering the SDK demo steps.
- Contract tests: CI runs the integration cases of the Poli Page API contract v1.3.0 (`poli-page/api-contract`) against the example app on every matrix leg, and builds the example app on the Symfony 7 legs.
- Dependabot for Composer (the bundle and the example app) and GitHub Actions; the API contract has its own group and no cooldown.
- **Interactive demo UI** at `GET /` in the example app. Single-page editorial-style dashboard with one button per SDK feature, inline PDF/HTML previews via `<iframe>`, pretty-printed JSON for the documents and error endpoints, and copy-buttons for the CLI commands. No new dependencies (no Twig). Files: `example-app/src/Controller/DemoController.php`, `example-app/templates/demo.html`.
- `composer serve` script in `example-app/composer.json` — runs PHP's built-in server on `127.0.0.1:8000`, removing the need for the optional Symfony CLI.
- `tests/RestoresGlobalHandlers.php` trait — snapshots/restores global error/exception handler stack around kernel-booting tests.
- `tests/bootstrap.php` — replaces `vendor/autoload.php` as PHPUnit's bootstrap; loads the repo-root `.env` so integration tests pick up `POLI_PAGE_API_KEY` without a shell export.
- `example-app/bootstrap.php` — loads the repo-root `.env` before Symfony Runtime starts, so the example app uses the same key as the test suite. Deliberately does not require `vendor/autoload.php`: Symfony Runtime must load it.

### Changed
- CI: the live API job runs on `main` (and on demand) against the production API with the sandbox key from the `POLI_PAGE_API_KEY` secret, and skips with a notice when the key is absent or not a `pp_sandbox_` key.
- **The exception listener is on by default** (`poli_page.exception_listener.enabled: true`). An uncaught `PoliPageException` answers its `toPayload()` body, `{ "code", "message", "status", "requestId" }`, as `application/json` with the payload's status (`503` for `network_error`, `504` for `timeout`, `500` when the SDK knows no status). The body's `status` is now `null` when the SDK knows none; it used to repeat the `500`. Set `exception_listener.enabled: false` to turn it off. The listener runs at priority `-10`, after FrameworkBundle logs the exception.
- Example app: `GET /errors/not-found` lets the bundle's exception listener answer (`404` with the `toPayload()` body) instead of catching the error itself.
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
- `PoliPageResponseFactory::stream()` no longer sends `Content-Length: -1` when the download has no known length; it sets the header only when the size is `>= 0`.
- An empty `base_url` (for example `POLI_PAGE_BASE_URL=`) now means the SDK's default API URL. It was passed to the SDK as `''`, so every request went to a relative URL and failed as `network_error`.
- A download that fails after the streamed PDF's headers and first bytes went out no longer gets the listener's JSON appended to the PDF body: the listener adds nothing, so the client sees a truncated download, and the exception is still logged.
- `PoliPageResponseFactory::stream()` reads the first chunk before it returns, so a download that fails on its first read (`DOWNLOAD_FAILED`, `network_error`) reaches the exception listener and answers its JSON payload, instead of a `200` with PDF headers and no body.
- Example app: the controllers carry `#[AsController]`, so the routes work on Symfony before 7.3 (they answered 500, "cannot be fetched from the container because it is private"). The example app reads `POLI_PAGE_BASE_URL` with `%env(default::POLI_PAGE_BASE_URL)%`, so it no longer fails with "Environment variable not found" when the variable is unset; unset or empty means the SDK's default URL.
- 8 PHPUnit "risky" warnings caused by Symfony's `FrameworkBundle::boot()` leaking a global error handler past test boundaries.
- A key ending in a newline now logs the malformed-key warning (the format check is anchored with `\z`, not `$`).
- `poli-page:render` no longer prints the configured API key. With older SDK releases, a key ending in a newline made the HTTP layer reject the `Authorization` header with the whole `Bearer <key>` value in the error message. The command now replaces the key with `[redacted API key]`, also with trailing spaces and tabs removed, the form Guzzle PSR-7 (a supported `request_factory`) puts in its message. `RenderCommand` takes the API key as a new constructor argument, which the bundle wires.
- `composer install` in `example-app/` no longer errors with "non-existent script @auto-scripts" on PHP 8.5+.
- `PoliPageResponseFactory::bytes()` / `stream()`: control characters (CR/LF, TAB, DEL, C1) are now stripped from the `Content-Disposition` filename instead of surviving as `?` in the fallback and `%0D%0A` in `filename*`. Filenames containing `/`, `\` or `%` no longer throw `InvalidArgumentException` from `HeaderUtils::makeDisposition()` (a 500): path separators become `_`, and `%` becomes `?` in the ASCII fallback only. The fallback now has one `?` per non-ASCII character (was one per UTF-8 byte), and a filename made only of control characters falls back to `document.pdf`.

[Unreleased]: https://github.com/poli-page/symfony/compare/v0.11.0...HEAD
[0.11.0]: https://github.com/poli-page/symfony/releases/tag/v0.11.0
