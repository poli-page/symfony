# poli-page/symfony-bundle

Symfony Bundle for [Poli Page](https://poli.page) — generate PDFs from controllers, messenger handlers, and console commands with idiomatic dependency injection.

> **Status**: scaffold only. Implementation begins in P2.3 of the [SDK roadmap](https://github.com/poli-page/poli-page/blob/develop/docs/onboarding/micka/sdk-roadmap.md).

## Install

```bash
composer require poli-page/symfony-bundle
```

## Quick start

To be filled in as the integration is built. The bundle will register the `PoliPage` client as an autowireable service and expose configuration under `config/packages/poli_page.yaml`.

## Dependencies

This package depends on [`poli-page/sdk`](https://github.com/poli-page/sdk-php) (the core PHP SDK). It is declared in `composer.json` and installed automatically. All HTTP, retry, and error-handling logic lives in the core SDK — this repo only adds the Symfony bundle wiring (DI, configuration tree, autowiring aliases).

## Publishing

Published to **Packagist** as [`poli-page/symfony-bundle`](https://packagist.org/packages/poli-page/symfony-bundle).

## Documentation

Full Poli Page documentation is at [docs.poli.page](https://docs.poli.page).

## License

MIT — see [LICENSE](./LICENSE).
