# CLAUDE.md

> Instructions for Claude Code agents working in `poli-page/symfony-bundle`.

## 1. Repo at a glance

| Field        | Value |
| ------------ | ----- |
| Repository   | `poli-page/symfony-bundle` |
| Type         | Framework integration (Symfony bundle) |
| Language     | PHP 8.3+ |
| Symfony      | 6.4 LTS \|\| 7.x |
| Registry     | Packagist — `poli-page/symfony-bundle` |
| Depends on   | `poli-page/sdk` (Packagist) |
| Roadmap slot | P2.3 |

**Source-of-truth docs (read first):**
- `docs/spec/bundle-specification.md` — full design spec for v0.1.0
- `docs/plan/2026-05-24-bundle-implementation.md` — implementation plan
- `/Users/mickael/Projects/INTEGRATIONS_PLAN.md` — cross-repo umbrella note
- `/Users/mickael/Projects/poli-page/docs/onboarding/micka/` — platform-wide conventions

## 2. The bundle's job

This bundle is a **thin wrapper** around the official Poli Page PHP SDK (`poli-page/sdk`, source at `/Users/mickael/Projects/sdk-php.md/`). It provides:

- DI registration of the `PoliPage` client (autowireable by FQCN)
- A Symfony-friendly response factory (`PoliPageResponseFactory`) for PDF / HTML responses
- A `bin/console poli-page:render` smoke-test command
- EventDispatcher integration for the SDK's `onRetry` / `onError` Closure hooks
- A Symfony Flex recipe (in `recipes/`, PR'd to `symfony/recipes-contrib`)

**This bundle does NOT** reimplement HTTP transport, retries, error mapping, idempotency, PSR-18 plumbing, or anything else the SDK already does. Bug in those areas? Fix it in the SDK, not here.

## 3. Working language

- **Code, comments, file names, commit messages, PR descriptions, repository documentation**: English.
- **Day-to-day conversation with Xavier**: French, tutoiement.
- **Conversation in this Claude Code session**: French is fine for the chat; artifacts stay English.

## 4. TDD is mandatory

RED → GREEN → refactor for every change. Tests live in `tests/Unit/` (mocked, 90%+ of the suite) and `tests/Integration/` (gated on `POLI_PAGE_API_KEY`, one test against the develop API).

### What to test (bundle-specific!)
- **DI compilation**: `PoliPage` resolves from the container, all config keys reach the constructor, default values come from the SDK.
- **Configuration validation**: invalid `api_key` / `timeout` / `retries.*` raise `InvalidConfigurationException` with the documented messages.
- **`PoliPageResponseFactory`**: every method sets the right headers (`Content-Type`, RFC 5987 `Content-Disposition`, `Cache-Control`, `X-Content-Type-Options`); ASCII and non-ASCII filenames both encode correctly.
- **`RenderCommand`**: option mapping, JSON data parsing, exit codes for `PoliPageException` families (4xx → 1, 5xx → 2, network → 3).
- **EventDispatcher integration**: SDK Closure invocation dispatches the wrapped Symfony event.

### What NOT to test (the SDK already does)
- HTTP transport behaviour (Guzzle / Symfony HTTP edge cases)
- Retry policy (backoff, max attempts, `Retry-After`, never-retry-4xx)
- 4xx / 5xx → exception mapping
- Idempotency key generation
- PSR-18 / PSR-17 plumbing
- Stream handling, byte-range correctness, etc.

Re-testing these here wastes time and creates double-maintenance burden. The SDK's `tests/` suite is the home for transport behaviour. **If you find yourself writing a mock HTTP server, stop — you're doing the SDK's job.**

## 5. Robustness over shortcuts

Xavier's hard rule: **no hacks to make a test pass or a corner case go away**. Fix root causes. If a workaround is genuinely required (framework bug, SDK quirk), document it inline with a `// Why:` comment naming the constraint.

## 6. Code conventions

- PHP-CS-Fixer with `@Symfony` + `@PHP83Migration` + project overrides. Pinned in `.php-cs-fixer.dist.php`.
- PHPStan level 8 + Symfony extension + strict rules. Pinned in `phpstan.neon`. Run with `--memory-limit=512M`.
- No commented-out code, no `TODO` without a linked issue, no debug prints in committed code.
- Default to no comments. Add one only when the *why* is non-obvious. Comments restating *what* the code does are noise.

## 7. Commits and PRs

- **Conventional Commits**: `feat:`, `fix:`, `docs:`, `chore:`, `refactor:`, `test:`.
- **One concern per PR**, reviewable in under 30 minutes.
- PR description: what changed, why, how it was tested.
- CI must be green before merge.

## 8. CI

Workflow: `.github/workflows/ci.yml`. Matrix: PHP `8.3`/`8.4` × Symfony `6.4`/`7.x`. Each step auto-skips if the relevant config is missing (so a freshly scaffolded repo is green from day one). Don't change that behaviour.

When working in this repo:
- After adding `composer.json`, the install step lights up.
- After adding `.php-cs-fixer.dist.php`, the lint step lights up.
- After adding tests in `tests/Unit/`, the test step lights up.

## 9. Unpublished SDK note

The PHP SDK is **not yet on Packagist**. We use `composer.local.json` + `wikimedia/composer-merge-plugin` to resolve `poli-page/sdk` from the local sibling directory `../sdk-php.md/`. See `docs/spec/bundle-specification.md` §12 for the full workaround.

When the SDK eventually publishes:
1. `git rm composer.local.json`
2. `composer remove --dev wikimedia/composer-merge-plugin`
3. Remove the `extra.merge-plugin` + `config.allow-plugins.wikimedia/composer-merge-plugin` blocks from `composer.json`.
4. `composer update poli-page/sdk` (resolves from Packagist).
5. Tag v0.1.0.

**Bundle source code is untouched** by this transition — only the dev environment is.

## 10. Known gotchas (battle-tested — don't relearn the hard way)

These caught us once. Recorded so future agents don't burn a session rediscovering them.

### 10.1 PHPUnit 11 handler-leak risky warnings

`Symfony\Bundle\FrameworkBundle::boot()` registers a global error handler (driven by `handle_all_throwables: true` + `php_errors.log: true` in our `TestKernel`) and `Kernel::shutdown()` does **not** unregister it. PHPUnit 11.5+ flags any test that ends with a different handler stack as risky.

**Fix in place**: `tests/RestoresGlobalHandlers.php` trait. Snapshots the handler stack in `setUp()` and unwinds back to baseline in `tearDown()`. Apply to any new test class that boots a kernel:

```php
final class MyKernelBootingTest extends TestCase
{
    use RestoresGlobalHandlers;
    // ...
}
```

**Trait/class `setUp()` collision**: if your test class overrides `setUp()`, the class's method wins and the trait's snapshot never runs. Either move setup logic into the test method, or use trait aliasing.

**Do NOT** "fix" this by setting `failOnRisky="false"` in `phpunit.xml.dist` — that's the hack we explicitly rejected.

### 10.2 `tests/bootstrap.php` loads the repo-root `.env`

PHPUnit's `bootstrap=` points at `tests/bootstrap.php`, not `vendor/autoload.php`. It loads the repo-root `.env` via a hand-rolled parser so `POLI_PAGE_API_KEY` is available to integration tests without a shell export. Real env vars still win (12-factor).

### 10.3 `example-app/bootstrap.php` must NOT require `vendor/autoload.php`

Symfony Runtime's `vendor/autoload_runtime.php` decides "first call vs re-include" by whether `vendor/autoload.php` is already loaded:

```php
if (true === (require_once __DIR__.'/autoload.php') || empty($_SERVER['SCRIPT_FILENAME'])) {
    return;
}
```

If anything pre-loads `vendor/autoload.php`, the runtime skips itself on the first pass, the kernel never boots, and the entry script silently returns 200/empty. The example-app's `bootstrap.php` therefore parses `.env` by hand and never touches the autoloader. There's a `// Why:` comment in the file — preserve it.

### 10.4 Symfony Console reserves `--version` / `-V` globally

The bundle's `poli-page:render` originally defined `--version` and `Application::run()` threw "An option named 'version' already exists." on `--help`, while silently producing no output on a normal invocation. Renamed to `--template-version`. Same hazard for `--help`, `--quiet`, `--verbose`, `--no-interaction`, `--ansi`, `--no-ansi`, `--env` — pick non-reserved names.

### 10.5 Example app env: one root `.env`, not per-app copies

Both the integration test suite (`tests/bootstrap.php`) and the example app (`example-app/bootstrap.php`) read the bundle repo's root `.env`. Don't introduce per-app `.env.local` files — the user explicitly wanted a single source of truth.

## 11. When stuck

- Re-read `docs/spec/bundle-specification.md` first; most "open questions" are answered there.
- Compare with the SDK reference at `/Users/mickael/Projects/sdk-php.md/`.
- Compare patterns with `getsentry/sentry-symfony`, `algolia/search-bundle`, `aws/aws-sdk-php-symfony` (the bundle's industry benchmarks).
- Ask Xavier early. A two-line message is faster than a half-day rebuilding the wrong thing.
- If a CI failure looks unrelated to your change, check `main` first before assuming you caused it.
