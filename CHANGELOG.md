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
- **Renamed `poli-page:render` option `--version` to `--template-version`** to avoid collision with Symfony Console's global `--version` / `-V`. Old name silently produced no output. (CLAUDE.md §10.4)
- `example-app/composer.json`: removed dead Symfony Flex `auto-scripts` hooks. Composer no longer warns on `composer install`.
- `example-app/.env`: removed placeholder `POLI_PAGE_API_KEY=pp_test_replace_me_with_real_key`. Value now comes from the repo-root `.env` via `example-app/bootstrap.php`. A comment in the file explains the override hierarchy.

### Fixed
- 8 PHPUnit "risky" warnings caused by Symfony's `FrameworkBundle::boot()` leaking a global error handler past test boundaries.
- `composer install` in `example-app/` no longer errors with "non-existent script @auto-scripts" on PHP 8.5+.

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
