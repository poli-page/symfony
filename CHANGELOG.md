# Changelog

All notable changes to `poli-page/symfony-bundle` are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Interactive demo UI** at `GET /` in the example app. Single-page editorial-style dashboard with one button per SDK feature, inline PDF/HTML previews via `<iframe>`, pretty-printed JSON for the documents and error endpoints, and copy-buttons for the CLI commands. No new dependencies (no Twig). Files: `example-app/src/Controller/DemoController.php`, `example-app/templates/demo.html`.
- `composer serve` script in `example-app/composer.json` — runs PHP's built-in server on `127.0.0.1:8000`, removing the need for the optional Symfony CLI.
- `tests/RestoresGlobalHandlers.php` trait — snapshots/restores global error/exception handler stack around kernel-booting tests (see CLAUDE.md §10.1).
- `tests/bootstrap.php` — replaces `vendor/autoload.php` as PHPUnit's bootstrap; loads the repo-root `.env` so integration tests pick up `POLI_PAGE_API_KEY` without a shell export.
- `example-app/bootstrap.php` — loads the repo-root `.env` before Symfony Runtime starts, so the example app uses the same key as the test suite. Deliberately does not require `vendor/autoload.php` (see CLAUDE.md §10.3).

### Changed
- **Environment keys.** An API key is `pp_{environment}_{32 hex}` (`pp_sandbox_…`, `pp_live_…`, `pp_staging_…`). The `api_key` config node no longer checks the key's format, so keys for any environment boot. When the client is built, a key that does not match the SDK contract's full-key pattern logs one warning through `poli_page.logger` (showing at most the `pp_{environment}_` prefix, never the secret); the API stays the only authority on keys. An empty key is still refused by the SDK.
- **Template versions are gone.** Renders use the project's copy published in the key's environment: `poli-page:render`, the example app, the README and the integration test build a `RenderInput` with no `version`.
- `PoliPageResponseFactory::preview()` takes a `DocumentPreviewResult` only (the stored HTML of a rendered document).
- The Flex recipe seeds `POLI_PAGE_API_KEY=pp_sandbox_replace_me`.
- The integration test runs only with a `pp_sandbox_…` key (renders in other environments count toward the monthly quota).
- Example app: `GET /errors/bad-version` is replaced by `GET /errors/not-found`, which renders `getting-started` / `does-not-exist` and answers `404` with code `TEMPLATE_NOT_FOUND`; the demo steps follow the SDK demo's numbering.
- `poli-page/sdk` now resolves from the SDK's environments change (`dev-main` at `7e16f75`, through a VCS repository in `composer.local.json`) until the next SDK release; `composer.local.json` no longer pins a local path to `0.1.0`.
- `example-app/composer.json`: removed dead Symfony Flex `auto-scripts` hooks. Composer no longer warns on `composer install`.
- `example-app/.env`: removed the placeholder `POLI_PAGE_API_KEY` value. Value now comes from the repo-root `.env` via `example-app/bootstrap.php`. A comment in the file explains the override hierarchy.

### Removed
- `poli-page:render` options `--template-version`, `--preview` and `--html` (HTML preview and inline-HTML rendering are gone from the API).
- Example app: `GET /render/preview` and its dashboard button. `GET /documents/{id}/preview` stays.

### Fixed
- 8 PHPUnit "risky" warnings caused by Symfony's `FrameworkBundle::boot()` leaking a global error handler past test boundaries.
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
