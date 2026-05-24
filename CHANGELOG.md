# Changelog

All notable changes to `poli-page/symfony-bundle` are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial release scaffolding.

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
