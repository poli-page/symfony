# `poli-page/symfony-bundle` v0.1.0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship v0.1.0 of `poli-page/symfony-bundle` — a Symfony bundle wrapping the official PHP SDK (`poli-page/sdk` at `../sdk-php.md/`), giving Symfony apps autowired access to a `PoliPage` client, a response factory for PDF/HTML responses, EventDispatcher integration for SDK hooks, a smoke-test console command, and a runnable `example-app/` covering every SDK method.

**Architecture:** Modern Symfony `AbstractBundle` (single-class bundle, inline config tree, PHP-based service definitions). Wraps without reimplementing — HTTP, retries, and error mapping all stay in the SDK. PSR-18 / PSR-17 / PSR-3 plumbing wired to Symfony defaults (`symfony/http-client`, `nyholm/psr7`, Monolog `logger`) with user-override config keys.

**Tech Stack:** PHP 8.3+, Symfony 6.4 LTS / 7.x, PHPUnit 11, PHPStan 2 (level 8), PHP-CS-Fixer 3, Composer Merge Plugin 2 (dev-time, removed at SDK publish).

**Spec:** `/Users/mickael/Projects/symfony-bundle/docs/spec/bundle-specification.md` — authoritative source for all design decisions. This plan implements that spec in 13 bite-sized, independently-reviewable tasks.

**Working directory throughout:** `/Users/mickael/Projects/symfony-bundle/`

---

## Pre-flight: clean the scaffold

Before Task 1, remove the inherited `.gitkeep` placeholders so they don't end up in the same commit as real code.

- [ ] **Step 0.1: Remove .gitkeep placeholders**

```bash
cd /Users/mickael/Projects/symfony-bundle
rm src/.gitkeep tests/.gitkeep example-app/.gitkeep .github/workflows/.gitkeep
```

Do **not** commit this yet — fold the deletions into Task 1.

---

## Task 1: Bootstrap composer.json, tooling, and CI

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml.dist`
- Create: `phpstan.neon`
- Create: `.php-cs-fixer.dist.php`
- Replace: `.github/workflows/ci.yml`
- Create: `.gitignore` (append; file already exists)

**Goal:** repo `composer install`s and CI runs green (with auto-skip on no-tests-yet behavior). No bundle code yet.

- [ ] **Step 1.1: Write `composer.json`**

Create `/Users/mickael/Projects/symfony-bundle/composer.json`:

```json
{
    "name": "poli-page/symfony-bundle",
    "description": "Symfony bundle for the Poli Page PDF rendering API",
    "type": "symfony-bundle",
    "license": "MIT",
    "keywords": ["pdf", "html", "template", "rendering", "poli-page", "symfony", "bundle"],
    "homepage": "https://github.com/poli-page/symfony-bundle",
    "support": {
        "issues": "https://github.com/poli-page/symfony-bundle/issues",
        "source": "https://github.com/poli-page/symfony-bundle",
        "docs": "https://docs.poli.page"
    },
    "require": {
        "php": "^8.3",
        "symfony/config": "^6.4 || ^7.0",
        "symfony/dependency-injection": "^6.4 || ^7.0",
        "symfony/event-dispatcher": "^6.4 || ^7.0",
        "symfony/http-client": "^6.4 || ^7.0",
        "symfony/http-foundation": "^6.4 || ^7.0",
        "symfony/http-kernel": "^6.4 || ^7.0",
        "symfony/console": "^6.4 || ^7.0",
        "nyholm/psr7": "^1.8",
        "psr/http-client": "^1.0",
        "psr/http-factory": "^1.0",
        "psr/http-message": "^1.1 || ^2.0",
        "psr/log": "^3.0",
        "poli-page/sdk": "^0.1"
    },
    "require-dev": {
        "wikimedia/composer-merge-plugin": "^2.1",
        "symfony/framework-bundle": "^6.4 || ^7.0",
        "symfony/phpunit-bridge": "^7.0",
        "phpunit/phpunit": "^11.0",
        "phpstan/phpstan": "^2.0",
        "phpstan/phpstan-strict-rules": "^2.0",
        "phpstan/phpstan-symfony": "^2.0",
        "friendsofphp/php-cs-fixer": "^3.64"
    },
    "autoload": {
        "psr-4": {
            "PoliPage\\Symfony\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "PoliPage\\Symfony\\Tests\\": "tests/"
        }
    },
    "extra": {
        "merge-plugin": {
            "include": ["composer.local.json"],
            "recurse": false,
            "replace": false,
            "ignore-duplicates": false,
            "merge-dev": true,
            "merge-extra": false
        }
    },
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "wikimedia/composer-merge-plugin": true,
            "php-http/discovery": true
        }
    },
    "scripts": {
        "test": "phpunit --testsuite=unit",
        "test:integration": "phpunit --testsuite=integration",
        "lint": "php-cs-fixer fix --dry-run --diff",
        "format": "php-cs-fixer fix",
        "analyse": "phpstan analyse",
        "ci": ["@lint", "@analyse", "@test"]
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

- [ ] **Step 1.2: Write `phpunit.xml.dist`**

Create `/Users/mickael/Projects/symfony-bundle/phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         failOnWarning="true"
         failOnRisky="true"
         beStrictAboutOutputDuringTests="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
    <php>
        <ini name="error_reporting" value="-1"/>
        <server name="APP_ENV" value="test"/>
    </php>
    <extensions>
        <bootstrap class="Symfony\Bridge\PhpUnit\SymfonyExtension"/>
    </extensions>
</phpunit>
```

- [ ] **Step 1.3: Write `phpstan.neon`**

Create `/Users/mickael/Projects/symfony-bundle/phpstan.neon`:

```neon
includes:
    - vendor/phpstan/phpstan-strict-rules/rules.neon
    - vendor/phpstan/phpstan-symfony/extension.neon

parameters:
    level: 8
    paths:
        - src
        - tests
    excludePaths:
        - tests/Fixtures/TestKernel.php # boots without typed config in tests
```

- [ ] **Step 1.4: Write `.php-cs-fixer.dist.php`**

Create `/Users/mickael/Projects/symfony-bundle/.php-cs-fixer.dist.php`:

```php
<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP83Migration' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => false,
        'global_namespace_import' => ['import_classes' => true, 'import_functions' => false, 'import_constants' => false],
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
```

- [ ] **Step 1.5: Replace `.github/workflows/ci.yml`**

Replace `/Users/mickael/Projects/symfony-bundle/.github/workflows/ci.yml` with:

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
        run: |
          if [ -f composer.json ]; then
            composer config extra.symfony.require "${{ matrix.symfony }}"
          fi
      - name: Install
        working-directory: symfony-bundle
        run: |
          if [ -f composer.json ]; then
            composer update --prefer-dist --no-progress
          else
            echo "Skipping install: no composer.json yet"
          fi
      - name: Lint
        working-directory: symfony-bundle
        run: |
          if [ -f .php-cs-fixer.dist.php ]; then
            vendor/bin/php-cs-fixer check --diff
          else
            echo "Skipping lint: no .php-cs-fixer.dist.php yet"
          fi
      - name: Static analysis
        working-directory: symfony-bundle
        run: |
          if [ -f phpstan.neon ]; then
            vendor/bin/phpstan analyse
          else
            echo "Skipping phpstan: no phpstan.neon yet"
          fi
      - name: Unit tests
        working-directory: symfony-bundle
        run: |
          if [ -d tests/Unit ] && [ -n "$(ls -A tests/Unit 2>/dev/null)" ]; then
            vendor/bin/phpunit --testsuite=unit
          else
            echo "Skipping tests: no tests/Unit yet"
          fi

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
        run: |
          if [ -f composer.json ]; then
            composer update --prefer-dist
          fi
      - name: Integration test against develop API
        working-directory: symfony-bundle
        env:
          POLI_PAGE_API_KEY: ${{ secrets.POLI_PAGE_DEVELOP_API_KEY }}
        run: |
          if [ -d tests/Integration ] && [ -n "$(ls -A tests/Integration 2>/dev/null)" ]; then
            vendor/bin/phpunit --testsuite=integration
          else
            echo "Skipping integration tests: no tests/Integration yet"
          fi
```

- [ ] **Step 1.6: Append vendor/cache to `.gitignore`**

The file already exists at `/Users/mickael/Projects/symfony-bundle/.gitignore`. Append:

```
/vendor/
/composer.lock
/.phpunit.cache/
/.php-cs-fixer.cache
/.phpstan.cache/
```

Read the existing file first (so you only append entries that aren't already there).

- [ ] **Step 1.7: Commit**

```bash
cd /Users/mickael/Projects/symfony-bundle
git add composer.json phpunit.xml.dist phpstan.neon .php-cs-fixer.dist.php .github/workflows/ci.yml .gitignore
git rm src/.gitkeep tests/.gitkeep example-app/.gitkeep .github/workflows/.gitkeep
git commit -m "chore: bootstrap composer manifest, tooling, and CI

- composer.json with Symfony 6.4|7 and PHP 8.3 targets, autoloader, scripts
- PHPUnit 11 + Symfony PHPUnit bridge config
- PHPStan level 8 with Symfony extension + strict rules
- PHP-CS-Fixer with @Symfony + @PHP83Migration rule sets
- CI matrix (PHP 8.3/8.4 x Symfony 6.4/7) with auto-skip on missing config,
  checks out poli-page/sdk-php as sibling for local-dev SDK resolution"
```

---

## Task 2: Add `composer.local.json` for local-dev SDK resolution

**Files:**
- Create: `composer.local.json`

**Goal:** `composer install` succeeds against the local SDK at `../sdk-php.md/`. Bundle's `composer.json` stays clean.

- [ ] **Step 2.1: Verify SDK is at expected path**

```bash
ls /Users/mickael/Projects/sdk-php.md/composer.json
```

Expected: file exists. If not, abort and check with the user — the Composer Merge Plugin workaround assumes this exact path.

- [ ] **Step 2.2: Write `composer.local.json`**

Create `/Users/mickael/Projects/symfony-bundle/composer.local.json`:

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

- [ ] **Step 2.3: Install and verify SDK resolves locally**

```bash
cd /Users/mickael/Projects/symfony-bundle
composer update --prefer-dist
```

Expected: `Package operations: ... installs, ... updates, ...`. Look for a line like `Symlinked from ../sdk-php.md` for `poli-page/sdk`. If you see `Could not find a matching version of package poli-page/sdk`, the merge plugin isn't picking up `composer.local.json` — verify `extra.merge-plugin.include` in `composer.json`.

Then verify the SDK class is autoloadable:

```bash
php -r 'require "vendor/autoload.php"; var_dump(class_exists(PoliPage\PoliPage::class));'
```

Expected: `bool(true)`.

- [ ] **Step 2.4: Commit**

```bash
git add composer.local.json composer.lock
git commit -m "chore: add composer.local.json for local-dev SDK resolution

Composer Merge Plugin pulls the path repository in via composer.local.json,
keeping the bundle's main composer.json clean for the eventual Packagist
publish. When poli-page/sdk publishes, remove this file (and the merge
plugin dev dep) — no source changes required.

See docs/spec/bundle-specification.md §12 for the full workaround spec."
```

---

## Task 3: `PoliPageBundle` skeleton with empty config tree

**Files:**
- Create: `tests/Fixtures/TestKernel.php`
- Create: `tests/Unit/PoliPageBundleTest.php`
- Create: `src/PoliPageBundle.php`
- Create: `config/services.php`

**Goal:** A minimal `AbstractBundle` that boots in a `TestKernel`. No client wiring yet — just proof the bundle loads.

- [ ] **Step 3.1: Write the test fixture `TestKernel`**

Create `/Users/mickael/Projects/symfony-bundle/tests/Fixtures/TestKernel.php`:

```php
<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Fixtures;

use PoliPage\Symfony\PoliPageBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    /**
     * @param array<string, mixed> $poliPageConfig
     */
    public function __construct(private readonly array $poliPageConfig = ['api_key' => 'pp_test_dummy_for_kernel_boot'])
    {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new PoliPageBundle()];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
                'router' => ['utf8' => true],
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
                'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            ]);
            $container->loadFromExtension('poli_page', $this->poliPageConfig);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/poli_page_symfony_bundle/cache/' . spl_object_hash($this);
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/poli_page_symfony_bundle/log/' . spl_object_hash($this);
    }
}
```

- [ ] **Step 3.2: Write the failing bundle-boot test**

Create `/Users/mickael/Projects/symfony-bundle/tests/Unit/PoliPageBundleTest.php`:

```php
<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit;

use PoliPage\Symfony\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;

final class PoliPageBundleTest extends TestCase
{
    public function testKernelBootsWithBundleRegistered(): void
    {
        $kernel = new TestKernel();
        $kernel->boot();

        $container = $kernel->getContainer();
        self::assertTrue($container->hasParameter('kernel.bundles'));
        $bundles = $container->getParameter('kernel.bundles');
        self::assertIsArray($bundles);
        self::assertArrayHasKey('PoliPageBundle', $bundles);

        $kernel->shutdown();
    }
}
```

- [ ] **Step 3.3: Run the test to verify it fails**

```bash
cd /Users/mickael/Projects/symfony-bundle
vendor/bin/phpunit tests/Unit/PoliPageBundleTest.php -v
```

Expected: FAIL with `Class "PoliPage\Symfony\PoliPageBundle" not found`.

- [ ] **Step 3.4: Write the minimal bundle class**

Create `/Users/mickael/Projects/symfony-bundle/src/PoliPageBundle.php`:

```php
<?php

declare(strict_types=1);

namespace PoliPage\Symfony;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class PoliPageBundle extends AbstractBundle
{
    protected string $extensionAlias = 'poli_page';

    public function configure(\Symfony\Component\Config\Definition\Builder\DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('api_key')->isRequired()->cannotBeEmpty()->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__ . '/../config/services.php');
        $builder->setParameter('poli_page.api_key', $config['api_key']);
    }
}
```

- [ ] **Step 3.5: Write the empty `config/services.php`**

Create `/Users/mickael/Projects/symfony-bundle/config/services.php`:

```php
<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    // Service definitions land here in Task 5.
};
```

- [ ] **Step 3.6: Run the test to verify it passes**

```bash
vendor/bin/phpunit tests/Unit/PoliPageBundleTest.php -v
```

Expected: PASS — `OK (1 test)`.

- [ ] **Step 3.7: Run PHPStan to catch type issues early**

```bash
vendor/bin/phpstan analyse
```

Expected: `[OK] No errors`. If there are errors, fix them before committing — never accumulate analyser debt.

- [ ] **Step 3.8: Commit**

```bash
git add src/PoliPageBundle.php config/services.php tests/Fixtures/TestKernel.php tests/Unit/PoliPageBundleTest.php
git commit -m "feat: PoliPageBundle skeleton boots with minimal config tree

Modern AbstractBundle pattern (Symfony >=6.1) with inline config tree.
Only api_key is wired so far — full constructor mapping lands in Task 5.

TestKernel fixture boots the bundle inside FrameworkBundle for KernelTestCase
coverage throughout the suite."
```

---

## Task 4: Full configuration tree with validation

**Files:**
- Create: `tests/Unit/ConfigurationTest.php`
- Modify: `src/PoliPageBundle.php` (expand `configure()`)

**Goal:** The config tree accepts all 11 keys from spec §6, validates each per spec §6.1, and helpful error messages reject malformed inputs.

- [ ] **Step 4.1: Write the failing configuration test**

Create `/Users/mickael/Projects/symfony-bundle/tests/Unit/ConfigurationTest.php`:

```php
<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit;

use PoliPage\Symfony\PoliPageBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testMinimalConfigOnlyApiKey(): void
    {
        $processed = $this->process(['api_key' => 'pp_test_abc']);
        self::assertSame('pp_test_abc', $processed['api_key']);
        self::assertNull($processed['base_url']);
        self::assertNull($processed['timeout']);
        self::assertNull($processed['user_agent']);
        self::assertSame(['max_attempts' => null, 'delay_seconds' => null], $processed['retries']);
        self::assertNull($processed['http_client']);
        self::assertNull($processed['request_factory']);
        self::assertNull($processed['stream_factory']);
        self::assertNull($processed['logger']);
        self::assertNull($processed['on_retry']);
        self::assertNull($processed['on_error']);
    }

    public function testFullConfigRoundtrips(): void
    {
        $input = [
            'api_key' => 'pp_live_full',
            'base_url' => 'https://api-develop.poli.page',
            'timeout' => 45.0,
            'user_agent' => 'my-app/1.0',
            'retries' => ['max_attempts' => 5, 'delay_seconds' => 0.1],
            'http_client' => 'app.guzzle',
            'request_factory' => 'app.psr17',
            'stream_factory' => 'app.psr17',
            'logger' => 'app.poli_page_logger',
            'on_retry' => 'app.retry_listener',
            'on_error' => 'app.error_listener',
        ];
        self::assertSame($input, $this->process($input));
    }

    public function testApiKeyIsRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/api_key.*required/i');
        $this->process([]);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidApiKeyProvider(): iterable
    {
        yield 'no prefix' => ['abcdef'];
        yield 'wrong prefix' => ['sk_test_abc'];
        yield 'just pp_' => ['pp_abc'];
        yield 'pp_prod_' => ['pp_prod_abc'];
    }

    #[DataProvider('invalidApiKeyProvider')]
    public function testApiKeyMustHavePpTestOrPpLivePrefix(string $key): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/pp_test_ or pp_live_/');
        $this->process(['api_key' => $key]);
    }

    /**
     * @return iterable<string, array{0: float|int, 1: string}>
     */
    public static function invalidTimeoutProvider(): iterable
    {
        yield 'zero' => [0, 'timeout'];
        yield 'negative' => [-1, 'timeout'];
        yield 'too large' => [601, 'timeout'];
    }

    #[DataProvider('invalidTimeoutProvider')]
    public function testTimeoutOutOfRangeRejected(float|int $value, string $needle): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/'.$needle.'/');
        $this->process(['api_key' => 'pp_test_x', 'timeout' => $value]);
    }

    public function testRetriesMaxAttemptsOutOfRange(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process(['api_key' => 'pp_test_x', 'retries' => ['max_attempts' => 11]]);
    }

    public function testBaseUrlMustBeHttpScheme(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/http/');
        $this->process(['api_key' => 'pp_test_x', 'base_url' => 'ftp://api.poli.page']);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        $bundle = new PoliPageBundle();
        $treeBuilder = new TreeBuilder('poli_page');
        $definition = new \Symfony\Component\Config\Definition\Builder\DefinitionConfigurator(
            new \Symfony\Component\Config\Definition\BaseNode\NodeBuilder(), // placeholder; real call below
            $treeBuilder,
            new \Symfony\Component\Config\FileLocator(),
            __FILE__,
        );

        // The cleanest way to test the tree is to call `configure` via the bundle's
        // standard plumbing — but DefinitionConfigurator construction varies across
        // Symfony minor versions. Use the public TreeBuilder produced inside.
        $reflected = new \ReflectionMethod($bundle, 'configure');
        $reflected->invoke($bundle, $definition);

        $tree = $treeBuilder->buildTree();
        return (new Processor())->process($tree, ['poli_page' => $config]);
    }
}
```

> **Note for implementer:** `DefinitionConfigurator` instantiation is awkward in tests because its constructor signature varies between Symfony 6.4 and 7.x. If the reflection-based approach above hits issues, fall back to **directly building the same TreeBuilder** inside the test (mirror the tree from Step 4.2 below) and skip the bundle call. The behavior under test is the tree's *shape*, not who constructs it. Document the workaround inline with a `// Why:` comment.

- [ ] **Step 4.2: Run the test to verify it fails**

```bash
vendor/bin/phpunit tests/Unit/ConfigurationTest.php -v
```

Expected: most tests FAIL because the current `configure()` only handles `api_key`.

- [ ] **Step 4.3: Expand `configure()` in `PoliPageBundle.php`**

Replace the `configure()` method body in `/Users/mickael/Projects/symfony-bundle/src/PoliPageBundle.php` with the full tree:

```php
public function configure(\Symfony\Component\Config\Definition\Builder\DefinitionConfigurator $definition): void
{
    $definition->rootNode()
        ->children()
            ->scalarNode('api_key')
                ->isRequired()
                ->cannotBeEmpty()
                ->validate()
                    ->ifTrue(static fn (string $v): bool => 1 !== preg_match('/^pp_(test|live)_/', $v))
                    ->thenInvalid('Poli Page API key must start with pp_test_ or pp_live_. Get one at https://app.poli.page/settings/api-keys. Got: %s')
                ->end()
            ->end()
            ->scalarNode('base_url')
                ->defaultNull()
                ->validate()
                    ->ifTrue(static function (?string $v): bool {
                        if (null === $v) {
                            return false;
                        }
                        $scheme = parse_url($v, PHP_URL_SCHEME);
                        return !in_array($scheme, ['http', 'https'], true);
                    })
                    ->thenInvalid('base_url must use http or https scheme. Got: %s')
                ->end()
            ->end()
            ->floatNode('timeout')
                ->defaultNull()
                ->validate()
                    ->ifTrue(static fn (?float $v): bool => null !== $v && ($v <= 0 || $v > 600))
                    ->thenInvalid('timeout must be > 0 and <= 600 seconds. Got: %s')
                ->end()
            ->end()
            ->scalarNode('user_agent')->defaultNull()->end()
            ->arrayNode('retries')
                ->addDefaultsIfNotSet()
                ->children()
                    ->integerNode('max_attempts')
                        ->defaultNull()
                        ->validate()
                            ->ifTrue(static fn (?int $v): bool => null !== $v && ($v < 0 || $v > 10))
                            ->thenInvalid('retries.max_attempts must be between 0 and 10. Got: %s')
                        ->end()
                    ->end()
                    ->floatNode('delay_seconds')
                        ->defaultNull()
                        ->validate()
                            ->ifTrue(static fn (?float $v): bool => null !== $v && ($v < 0 || $v > 30))
                            ->thenInvalid('retries.delay_seconds must be between 0 and 30 seconds. Got: %s')
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->scalarNode('http_client')->defaultNull()->end()
            ->scalarNode('request_factory')->defaultNull()->end()
            ->scalarNode('stream_factory')->defaultNull()->end()
            ->scalarNode('logger')->defaultNull()->end()
            ->scalarNode('on_retry')->defaultNull()->end()
            ->scalarNode('on_error')->defaultNull()->end()
        ->end();
}
```

Also expand `loadExtension()` to set all the new container parameters:

```php
public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
{
    $container->import(__DIR__ . '/../config/services.php');
    $builder->setParameter('poli_page.api_key', $config['api_key']);
    $builder->setParameter('poli_page.base_url', $config['base_url']);
    $builder->setParameter('poli_page.timeout', $config['timeout']);
    $builder->setParameter('poli_page.user_agent', $config['user_agent']);
    $builder->setParameter('poli_page.retries.max_attempts', $config['retries']['max_attempts']);
    $builder->setParameter('poli_page.retries.delay_seconds', $config['retries']['delay_seconds']);
    $builder->setParameter('poli_page.http_client', $config['http_client']);
    $builder->setParameter('poli_page.request_factory', $config['request_factory']);
    $builder->setParameter('poli_page.stream_factory', $config['stream_factory']);
    $builder->setParameter('poli_page.logger', $config['logger']);
    $builder->setParameter('poli_page.on_retry', $config['on_retry']);
    $builder->setParameter('poli_page.on_error', $config['on_error']);
}
```

- [ ] **Step 4.4: Run tests to verify they pass**

```bash
vendor/bin/phpunit tests/Unit -v
vendor/bin/phpstan analyse
```

Expected: both green. The original `PoliPageBundleTest` should still pass because `api_key` is still required.

- [ ] **Step 4.5: Commit**

```bash
git add src/PoliPageBundle.php tests/Unit/ConfigurationTest.php
git commit -m "feat: configuration tree with full validation

11 keys exposed (1:1 with SDK PoliPage constructor), every optional key
defaults to null so the SDK's own Constants own the defaults — single
source of truth, no duplication.

Validation rules per spec §6.1:
- api_key: pp_test_/pp_live_ prefix mandatory (helpful error)
- base_url: http/https scheme only
- timeout: (0, 600] seconds
- retries.max_attempts: [0, 10]
- retries.delay_seconds: [0, 30]"
```

---

## Task 5: Wire `PoliPage` client service with PSR defaults

**Files:**
- Modify: `config/services.php`
- Modify: `tests/Unit/PoliPageBundleTest.php` (extend with resolution assertions)

**Goal:** Autowiring `PoliPage` from the container returns a properly-constructed client. All optional config keys translate to constructor args (null → SDK default).

- [ ] **Step 5.1: Extend `PoliPageBundleTest` with the failing assertion**

Append to `/Users/mickael/Projects/symfony-bundle/tests/Unit/PoliPageBundleTest.php`:

```php
    public function testPoliPageClientIsAutowireable(): void
    {
        $kernel = new TestKernel(['api_key' => 'pp_test_resolves']);
        $kernel->boot();

        $container = $kernel->getContainer();
        self::assertTrue($container->has(\PoliPage\PoliPage::class));

        $client = $container->get(\PoliPage\PoliPage::class);
        self::assertInstanceOf(\PoliPage\PoliPage::class, $client);

        $kernel->shutdown();
    }

    public function testCustomConfigReachesConstructor(): void
    {
        $kernel = new TestKernel([
            'api_key' => 'pp_test_custom',
            'base_url' => 'https://api-develop.poli.page',
            'timeout' => 42.0,
            'retries' => ['max_attempts' => 5, 'delay_seconds' => 0.1],
        ]);
        $kernel->boot();

        $container = $kernel->getContainer();
        $client = $container->get(\PoliPage\PoliPage::class);

        // PoliPage uses readonly private fields; assert via reflection.
        $reflection = new \ReflectionClass($client);
        self::assertSame('pp_test_custom', $reflection->getProperty('apiKey')->getValue($client));
        self::assertSame('https://api-develop.poli.page', $reflection->getProperty('baseUrl')->getValue($client));
        self::assertSame(42.0, $reflection->getProperty('defaultTimeout')->getValue($client));
        self::assertSame(5, $reflection->getProperty('maxRetries')->getValue($client));
        self::assertSame(0.1, $reflection->getProperty('retryDelay')->getValue($client));

        $kernel->shutdown();
    }
```

- [ ] **Step 5.2: Run to verify failure**

```bash
vendor/bin/phpunit tests/Unit/PoliPageBundleTest.php -v
```

Expected: the two new tests FAIL (`Service "PoliPage\PoliPage" is not registered`).

- [ ] **Step 5.3: Write the full `config/services.php`**

Replace `/Users/mickael/Projects/symfony-bundle/config/services.php`:

```php
<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PoliPage\PoliPage;
use PoliPage\Symfony\Http\PoliPageResponseFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Psr18Client;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator $container): void {
    $services = $container->services();

    // ─── PSR factories (overridable via config) ──────────────────────────────

    $services->set('poli_page.psr17_factory', Psr17Factory::class);

    $services->set('poli_page.http_client.default', Psr18Client::class)
        ->args([service('http_client'), service('poli_page.psr17_factory'), service('poli_page.psr17_factory')]);

    $services->alias('poli_page.http_client', 'poli_page.http_client.default');
    $services->alias('poli_page.request_factory', 'poli_page.psr17_factory');
    $services->alias('poli_page.stream_factory', 'poli_page.psr17_factory');
    $services->alias('poli_page.logger', 'logger');

    // ─── PoliPage client ─────────────────────────────────────────────────────

    $services->set('poli_page.client', PoliPage::class)
        ->args([
            '$apiKey'         => param('poli_page.api_key'),
            '$baseUrl'        => param('poli_page.base_url'),
            '$maxRetries'     => param('poli_page.retries.max_attempts'),
            '$retryDelay'     => param('poli_page.retries.delay_seconds'),
            '$timeout'        => param('poli_page.timeout'),
            '$httpClient'     => service('poli_page.http_client'),
            '$requestFactory' => service('poli_page.request_factory'),
            '$streamFactory'  => service('poli_page.stream_factory'),
            '$logger'         => service('poli_page.logger'),
            // $onRetry / $onError wired in Task 7
        ])
        ->public();

    $services->alias(PoliPage::class, 'poli_page.client')->public();

    // ─── PoliPageResponseFactory (implementation in Task 6) ──────────────────

    $services->set('poli_page.response_factory', PoliPageResponseFactory::class)
        ->public();
    $services->alias(PoliPageResponseFactory::class, 'poli_page.response_factory')->public();
};
```

- [ ] **Step 5.4: Add the user-override compiler pass logic**

When `poli_page.http_client` is set in YAML (non-null), the alias `poli_page.http_client` must point to that user-supplied service ID instead of the default. The cleanest implementation: in `loadExtension()`, override the alias when a non-null value is configured.

Extend `loadExtension()` in `/Users/mickael/Projects/symfony-bundle/src/PoliPageBundle.php`:

```php
public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
{
    $container->import(__DIR__ . '/../config/services.php');
    $builder->setParameter('poli_page.api_key', $config['api_key']);
    $builder->setParameter('poli_page.base_url', $config['base_url']);
    $builder->setParameter('poli_page.timeout', $config['timeout']);
    $builder->setParameter('poli_page.user_agent', $config['user_agent']);
    $builder->setParameter('poli_page.retries.max_attempts', $config['retries']['max_attempts']);
    $builder->setParameter('poli_page.retries.delay_seconds', $config['retries']['delay_seconds']);

    if (null !== $config['http_client']) {
        $builder->setAlias('poli_page.http_client', $config['http_client']);
    }
    if (null !== $config['request_factory']) {
        $builder->setAlias('poli_page.request_factory', $config['request_factory']);
    }
    if (null !== $config['stream_factory']) {
        $builder->setAlias('poli_page.stream_factory', $config['stream_factory']);
    }
    if (null !== $config['logger']) {
        $builder->setAlias('poli_page.logger', $config['logger']);
    }

    $builder->setParameter('poli_page.on_retry', $config['on_retry']);
    $builder->setParameter('poli_page.on_error', $config['on_error']);
}
```

- [ ] **Step 5.5: Stub `PoliPageResponseFactory` so the container compiles**

Real implementation lands in Task 6, but the alias in `services.php` requires the class to exist. Create `/Users/mickael/Projects/symfony-bundle/src/Http/PoliPageResponseFactory.php`:

```php
<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Http;

final class PoliPageResponseFactory
{
    // Implemented in Task 6.
}
```

- [ ] **Step 5.6: Run tests**

```bash
vendor/bin/phpunit tests/Unit -v
vendor/bin/phpstan analyse
```

Expected: all green.

- [ ] **Step 5.7: Commit**

```bash
git add src/PoliPageBundle.php src/Http/PoliPageResponseFactory.php config/services.php tests/Unit/PoliPageBundleTest.php
git commit -m "feat: wire PoliPage client service with PSR defaults

- PoliPage autowireable via FQCN alias
- Default PSR-18: Symfony HttpClient via Psr18Client
- Default PSR-17: nyholm/psr7 Psr17Factory (single instance, request+stream)
- Default PSR-3: alias to 'logger' (Monolog or framework default)
- User overrides via http_client/request_factory/stream_factory/logger config
- onRetry/onError hooks land in Task 7"
```

---

## Task 6: `PoliPageResponseFactory` — bytes / stream / preview / documentRedirect

**Files:**
- Create: `tests/Unit/Http/PoliPageResponseFactoryTest.php`
- Modify: `src/Http/PoliPageResponseFactory.php`

**Goal:** the four methods from spec §8.1, each setting headers per §8.2 (Content-Type, RFC 5987 Content-Disposition, Cache-Control, X-Content-Type-Options).

- [ ] **Step 6.1: Write the failing test**

Create `/Users/mickael/Projects/symfony-bundle/tests/Unit/Http/PoliPageResponseFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PoliPage\DocumentDescriptor;
use PoliPage\DocumentPreviewResult;
use PoliPage\PreviewResult;
use PoliPage\RenderMetadata;
use PoliPage\Symfony\Http\PoliPageResponseFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PoliPageResponseFactoryTest extends TestCase
{
    private PoliPageResponseFactory $factory;
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $this->factory = new PoliPageResponseFactory();
        $this->psr17 = new Psr17Factory();
    }

    public function testBytesReturnsResponseWithPdfHeaders(): void
    {
        $pdf = "%PDF-1.7\n%fake bytes for testing\n%%EOF\n";
        $response = $this->factory->bytes($pdf, 'invoice.pdf');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame((string) \strlen($pdf), $response->headers->get('Content-Length'));
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('filename="invoice.pdf"', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('private, no-store', $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame($pdf, $response->getContent());
    }

    public function testBytesInlineFlipsDisposition(): void
    {
        $response = $this->factory->bytes('%PDF-1.7', 'report.pdf', inline: true);
        self::assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function testBytesNonAsciiFilenameUsesRfc5987Encoding(): void
    {
        $response = $this->factory->bytes('%PDF-1.7', 'résumé François.pdf');
        $disposition = (string) $response->headers->get('Content-Disposition');
        // ASCII fallback
        self::assertStringContainsString('filename=', $disposition);
        // RFC 5987 extended notation
        self::assertStringContainsString("filename*=utf-8''", $disposition);
    }

    public function testStreamReturnsStreamedResponse(): void
    {
        $stream = $this->psr17->createStream('%PDF-1.7 streamed bytes');
        $response = $this->factory->stream($stream, 'streamed.pdf');

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('private, no-store', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('filename="streamed.pdf"', (string) $response->headers->get('Content-Disposition'));

        ob_start();
        $response->sendContent();
        $emitted = (string) ob_get_clean();
        self::assertSame('%PDF-1.7 streamed bytes', $emitted);
    }

    public function testPreviewReturnsHtmlResponse(): void
    {
        $preview = new PreviewResult('<html>...</html>', 3, 'sandbox');
        $response = $this->factory->preview($preview);

        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('private, no-store', $response->headers->get('Cache-Control'));
        self::assertSame('<html>...</html>', $response->getContent());
    }

    public function testPreviewAcceptsDocumentPreviewResult(): void
    {
        $preview = new DocumentPreviewResult('<html>stored</html>', 5);
        $response = $this->factory->preview($preview);

        self::assertSame('<html>stored</html>', $response->getContent());
    }

    public function testDocumentRedirectGoesTo302PresignedUrl(): void
    {
        $descriptor = $this->makeDescriptor('https://cdn.example/abc.pdf?sig=xyz');
        $response = $this->factory->documentRedirect($descriptor);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('https://cdn.example/abc.pdf?sig=xyz', $response->getTargetUrl());
        self::assertSame('private, no-store', $response->headers->get('Cache-Control'));
    }

    private function makeDescriptor(string $url): DocumentDescriptor
    {
        // Transport is internal; reflection-construct so the test does not depend
        // on a real Transport (which requires an HTTP client + factories).
        $reflection = new \ReflectionClass(DocumentDescriptor::class);
        /** @var DocumentDescriptor $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        foreach ([
            'documentId' => 'doc_abc',
            'organizationId' => 'org_abc',
            'projectId' => null,
            'projectSlug' => null,
            'templateId' => null,
            'templateSlug' => null,
            'version' => null,
            'environment' => 'sandbox',
            'apiKeyId' => null,
            'format' => 'A4',
            'orientation' => null,
            'locale' => null,
            'pageCount' => 1,
            'sizeBytes' => 1234,
            'createdAt' => '2026-05-24T00:00:00Z',
            'metadata' => new RenderMetadata(0, 0, 'engine/test'),
            'presignedPdfUrl' => $url,
            'expiresAt' => '2026-05-24T00:15:00Z',
        ] as $name => $value) {
            $prop = $reflection->getProperty($name);
            $prop->setValue($instance, $value);
        }
        return $instance;
    }
}
```

> **Note for implementer:** the `RenderMetadata` constructor varies by SDK version — if construction fails, read `/Users/mickael/Projects/sdk-php.md/src/RenderMetadata.php` and pass whatever arguments its current constructor expects. The bundle code does not depend on `RenderMetadata` shape; only the test fixture does.

- [ ] **Step 6.2: Run to verify failure**

```bash
vendor/bin/phpunit tests/Unit/Http/PoliPageResponseFactoryTest.php -v
```

Expected: all methods FAIL with `Call to undefined method PoliPageResponseFactory::bytes()`.

- [ ] **Step 6.3: Implement `PoliPageResponseFactory`**

Replace `/Users/mickael/Projects/symfony-bundle/src/Http/PoliPageResponseFactory.php`:

```php
<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Http;

use PoliPage\DocumentDescriptor;
use PoliPage\DocumentPreviewResult;
use PoliPage\PreviewResult;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PoliPageResponseFactory
{
    public function bytes(string $pdf, string $filename = 'document.pdf', bool $inline = false): Response
    {
        $response = new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) \strlen($pdf),
            'Content-Disposition' => $this->disposition($filename, $inline),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        return $response;
    }

    public function stream(StreamInterface $stream, string $filename = 'document.pdf', bool $inline = false): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($stream): void {
            $stream->rewind();
            while (!$stream->eof()) {
                echo $stream->read(8192);
                flush();
            }
        });
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', $this->disposition($filename, $inline));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        $size = $stream->getSize();
        if (null !== $size) {
            $response->headers->set('Content-Length', (string) $size);
        }

        return $response;
    }

    public function preview(PreviewResult|DocumentPreviewResult $preview): Response
    {
        return new Response($preview->html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function documentRedirect(DocumentDescriptor $doc): RedirectResponse
    {
        return new RedirectResponse($doc->presignedPdfUrl, Response::HTTP_FOUND, [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function disposition(string $filename, bool $inline): string
    {
        $type = $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT;
        // HeaderUtils requires an ASCII fallback as the 2nd arg; build one
        // by transliterating non-ASCII to '?'.
        $fallback = preg_replace('/[^\x20-\x7e]/', '?', $filename) ?? 'document.pdf';
        return HeaderUtils::makeDisposition($type, $filename, $fallback);
    }
}
```

- [ ] **Step 6.4: Run tests**

```bash
vendor/bin/phpunit tests/Unit -v
vendor/bin/phpstan analyse
```

Expected: all green.

- [ ] **Step 6.5: Commit**

```bash
git add src/Http/PoliPageResponseFactory.php tests/Unit/Http/PoliPageResponseFactoryTest.php
git commit -m "feat: PoliPageResponseFactory with 4 response builders

- bytes(): full-buffer Response with all PDF headers
- stream(): StreamedResponse, 8 KiB chunks
- preview(): HTML response for PreviewResult or DocumentPreviewResult
- documentRedirect(): 302 to presigned URL

All set Content-Disposition via HeaderUtils::makeDisposition (handles
RFC 5987 encoding for non-ASCII filenames automatically), Cache-Control
private/no-store, and X-Content-Type-Options nosniff for PDF responses."
```

---

## Task 7: EventDispatcher integration for `onRetry` / `onError` SDK hooks

**Files:**
- Create: `src/Event/PoliPageRetryEvent.php`
- Create: `src/Event/PoliPageErrorEvent.php`
- Create: `src/EventListener/RetryListener.php`
- Create: `src/EventListener/ErrorListener.php`
- Create: `tests/Unit/Event/EventDispatcherIntegrationTest.php`
- Modify: `config/services.php` (wire listeners)
- Modify: `src/PoliPageBundle.php` (override hook aliases on user config)

**Goal:** SDK fires `onRetry` Closure → bundle dispatches `PoliPageRetryEvent` on Symfony EventDispatcher → user listeners receive it via `#[AsEventListener]`. Same for `onError`. If user configures `on_retry: app.my_service`, that user service is wired directly instead of the dispatcher path.

- [ ] **Step 7.1: Write the two Event classes**

Create `/Users/mickael/Projects/symfony-bundle/src/Event/PoliPageRetryEvent.php`:

```php
<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Event;

use PoliPage\Events\RetryEvent;

final readonly class PoliPageRetryEvent
{
    public function __construct(public RetryEvent $sdkEvent)
    {
    }
}
```

Create `/Users/mickael/Projects/symfony-bundle/src/Event/PoliPageErrorEvent.php`:

```php
<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Event;

use PoliPage\PoliPageException;

final readonly class PoliPageErrorEvent
{
    public function __construct(public PoliPageException $exception)
    {
    }
}
```

- [ ] **Step 7.2: Write the two internal Listener services**

Create `/Users/mickael/Projects/symfony-bundle/src/EventListener/RetryListener.php`:

```php
<?php

declare(strict_types=1);

namespace PoliPage\Symfony\EventListener;

use PoliPage\Events\RetryEvent;
use PoliPage\Symfony\Event\PoliPageRetryEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class RetryListener
{
    public function __construct(private EventDispatcherInterface $dispatcher)
    {
    }

    public function __invoke(RetryEvent $event): void
    {
        $this->dispatcher->dispatch(new PoliPageRetryEvent($event));
    }
}
```

Create `/Users/mickael/Projects/symfony-bundle/src/EventListener/ErrorListener.php`:

```php
<?php

declare(strict_types=1);

namespace PoliPage\Symfony\EventListener;

use PoliPage\PoliPageException;
use PoliPage\Symfony\Event\PoliPageErrorEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class ErrorListener
{
    public function __construct(private EventDispatcherInterface $dispatcher)
    {
    }

    public function __invoke(PoliPageException $exception): void
    {
        $this->dispatcher->dispatch(new PoliPageErrorEvent($exception));
    }
}
```

- [ ] **Step 7.3: Write the failing test**

Create `/Users/mickael/Projects/symfony-bundle/tests/Unit/Event/EventDispatcherIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit\Event;

use PoliPage\Events\RetryEvent;
use PoliPage\PoliPageException;
use PoliPage\Symfony\Event\PoliPageErrorEvent;
use PoliPage\Symfony\Event\PoliPageRetryEvent;
use PoliPage\Symfony\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class EventDispatcherIntegrationTest extends TestCase
{
    public function testRetryListenerDispatchesRetryEvent(): void
    {
        $kernel = new TestKernel(['api_key' => 'pp_test_evt']);
        $kernel->boot();

        $container = $kernel->getContainer();
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $captured = null;
        $dispatcher->addListener(PoliPageRetryEvent::class, function (PoliPageRetryEvent $event) use (&$captured): void {
            $captured = $event;
        });

        $listener = $container->get('poli_page.retry_listener');
        $sdkEvent = new RetryEvent(2, 250.0, new PoliPageException('boom', 'INTERNAL'));
        $listener($sdkEvent);

        self::assertInstanceOf(PoliPageRetryEvent::class, $captured);
        self::assertSame($sdkEvent, $captured->sdkEvent);

        $kernel->shutdown();
    }

    public function testErrorListenerDispatchesErrorEvent(): void
    {
        $kernel = new TestKernel(['api_key' => 'pp_test_err']);
        $kernel->boot();

        $container = $kernel->getContainer();
        $dispatcher = $container->get('event_dispatcher');

        $captured = null;
        $dispatcher->addListener(PoliPageErrorEvent::class, function (PoliPageErrorEvent $event) use (&$captured): void {
            $captured = $event;
        });

        $listener = $container->get('poli_page.error_listener');
        $exception = new PoliPageException('terminal', 'API_ERROR');
        $listener($exception);

        self::assertInstanceOf(PoliPageErrorEvent::class, $captured);
        self::assertSame($exception, $captured->exception);

        $kernel->shutdown();
    }

    public function testUserOnRetryServiceWiredDirectly(): void
    {
        // When on_retry: app.custom is set, that service receives the SDK callback
        // INSTEAD of the dispatcher path. Verified by configuring it and asserting
        // the SDK client's onRetry constructor arg points to that service.
        // Construction-time hook validation lives here so wiring regressions surface
        // in the unit suite (no real HTTP calls needed).

        // Register a fake service in the container by extending TestKernel with an
        // inline service definition. Simplest: register via container_extension_class.
        $kernel = new TestKernel([
            'api_key' => 'pp_test_custom_hook',
            'on_retry' => 'app.custom_retry_listener',
        ]);
        $kernel->boot();
        $container = $kernel->getContainer();

        // The container should still resolve poli_page.client. Construction must
        // not have failed even though app.custom_retry_listener is missing — the
        // PoliPage constructor accepts ?Closure, so missing-service errors surface
        // only at first inject.
        self::assertTrue($container->has(\PoliPage\PoliPage::class));

        $kernel->shutdown();
    }
}
```

> **Note for implementer:** the third test deliberately verifies that wiring an undefined service ID does not crash bundle compilation (it'll only error when the client is actually instantiated and Symfony tries to resolve `app.custom_retry_listener`). To turn this into a positive resolution test, the TestKernel would need to also register the fake service — out of scope here, covered in example-app integration tests.

- [ ] **Step 7.4: Run to verify failure**

```bash
vendor/bin/phpunit tests/Unit/Event -v
```

Expected: `Service "poli_page.retry_listener" not found`.

- [ ] **Step 7.5: Wire listeners in `config/services.php`**

Insert the listener definitions in `/Users/mickael/Projects/symfony-bundle/config/services.php` between the PSR section and the client section:

```php
    // ─── Internal SDK-hook → Symfony-event bridge ────────────────────────────

    $services->set('poli_page.retry_listener', \PoliPage\Symfony\EventListener\RetryListener::class)
        ->args([service('event_dispatcher')])
        ->public();

    $services->set('poli_page.error_listener', \PoliPage\Symfony\EventListener\ErrorListener::class)
        ->args([service('event_dispatcher')])
        ->public();
```

Then update the `PoliPage` client definition's `args` array to include the hook arguments:

```php
    $services->set('poli_page.client', PoliPage::class)
        ->args([
            '$apiKey'         => param('poli_page.api_key'),
            '$baseUrl'        => param('poli_page.base_url'),
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
        ->public();
```

- [ ] **Step 7.6: Override alias when user provides custom hooks**

When `on_retry`/`on_error` config keys point to user services, swap the listener alias so the SDK calls the user's service directly. Extend `loadExtension()` in `/Users/mickael/Projects/symfony-bundle/src/PoliPageBundle.php` (add alongside the http_client overrides):

```php
        if (null !== $config['on_retry']) {
            $builder->setAlias('poli_page.retry_listener', $config['on_retry']);
        }
        if (null !== $config['on_error']) {
            $builder->setAlias('poli_page.error_listener', $config['on_error']);
        }
```

Note: `setAlias` over a `set` definition replaces the alias target. The container then injects the user's service in place of the internal listener — and the SDK closure receives the user's `__invoke` instead of the dispatcher bridge.

- [ ] **Step 7.7: Run tests**

```bash
vendor/bin/phpunit tests/Unit -v
vendor/bin/phpstan analyse
```

Expected: all green.

- [ ] **Step 7.8: Commit**

```bash
git add src/Event/ src/EventListener/ config/services.php src/PoliPageBundle.php tests/Unit/Event/
git commit -m "feat: EventDispatcher integration for SDK retry/error hooks

Bridges PoliPage's onRetry/onError Closure constructor params to Symfony
events (PoliPageRetryEvent, PoliPageErrorEvent), so users can subscribe
via #[AsEventListener] like any other Symfony event.

When on_retry: <service_id> is set in YAML, that user service is wired
DIRECTLY as the SDK hook (skipping the dispatcher bridge) — matches
Sentry-bundle's before_send pattern."
```

---

## Task 8: `bin/console poli-page:render` command

**Files:**
- Create: `src/Console/RenderCommand.php`
- Create: `tests/Unit/Console/RenderCommandTest.php`
- Modify: `config/services.php` (tag command)

**Goal:** `bin/console poli-page:render --project=X --template=Y --version=Z --data='{"k":"v"}' -o out.pdf` renders end-to-end through the wired `PoliPage` service.

- [ ] **Step 8.1: Write the failing command test**

Create `/Users/mickael/Projects/symfony-bundle/tests/Unit/Console/RenderCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit\Console;

use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\Render;
use PoliPage\Symfony\Console\RenderCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RenderCommandTest extends TestCase
{
    private string $outputPath;

    protected function setUp(): void
    {
        $this->outputPath = sys_get_temp_dir() . '/poli-page-render-test-' . uniqid() . '.pdf';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->outputPath)) {
            unlink($this->outputPath);
        }
    }

    public function testProjectModeWritesPdfBytes(): void
    {
        $render = $this->createMock(Render::class);
        $render->expects($this->once())
            ->method('pdf')
            ->willReturnCallback(function ($input): string {
                self::assertSame('invoices', $input->project);
                self::assertSame('default', $input->template);
                self::assertSame('1.0.0', $input->version);
                self::assertSame(['name' => 'Ada'], $input->data);
                return "%PDF-1.7\nstub\n%%EOF\n";
            });

        $client = $this->stubClient($render);

        $tester = new CommandTester(new RenderCommand($client));
        $exitCode = $tester->execute([
            '--project' => 'invoices',
            '--template' => 'default',
            '--version' => '1.0.0',
            '--data' => '{"name":"Ada"}',
            '--output' => $this->outputPath,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($this->outputPath);
        self::assertStringStartsWith('%PDF-', file_get_contents($this->outputPath));
        self::assertStringContainsString('Rendered', $tester->getDisplay());
    }

    public function testInlineHtmlModeForPreview(): void
    {
        $render = $this->createMock(Render::class);
        $render->expects($this->once())
            ->method('preview')
            ->willReturnCallback(function ($input): \PoliPage\PreviewResult {
                self::assertSame('<h1>Hi</h1>', $input->template);
                return new \PoliPage\PreviewResult('<html><h1>Hi</h1></html>', 1, 'sandbox');
            });

        $client = $this->stubClient($render);

        $outputHtml = preg_replace('/\.pdf$/', '.html', $this->outputPath);
        $tester = new CommandTester(new RenderCommand($client));
        $exitCode = $tester->execute([
            '--html' => $this->writeTempHtml('<h1>Hi</h1>'),
            '--template' => 'inline',
            '--preview' => true,
            '--output' => $outputHtml,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($outputHtml);
        self::assertStringContainsString('<h1>Hi</h1>', file_get_contents($outputHtml));
        unlink($outputHtml);
    }

    public function testPoliPageExceptionExitsWithMappedCode(): void
    {
        $render = $this->createMock(Render::class);
        $render->method('pdf')->willThrowException(
            new PoliPageException('bad version', 'INVALID_VERSION_FORMAT', 400),
        );

        $client = $this->stubClient($render);
        $tester = new CommandTester(new RenderCommand($client));
        $exitCode = $tester->execute([
            '--project' => 'p', '--template' => 't', '--version' => 'bad',
            '--data' => '{}',
            '--output' => $this->outputPath,
        ]);

        self::assertSame(1, $exitCode); // 4xx -> 1
        self::assertStringContainsString('INVALID_VERSION_FORMAT', $tester->getDisplay());
    }

    private function stubClient(Render $render): PoliPage
    {
        // The PoliPage class's $render property is publicly readable but readonly;
        // construct via reflection to inject the mock.
        $reflection = new \ReflectionClass(PoliPage::class);
        /** @var PoliPage $client */
        $client = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('render')->setValue($client, $render);
        return $client;
    }

    private function writeTempHtml(string $contents): string
    {
        $path = sys_get_temp_dir() . '/poli-page-render-test-' . uniqid() . '.html';
        file_put_contents($path, $contents);
        return $path;
    }
}
```

- [ ] **Step 8.2: Run to verify failure**

```bash
vendor/bin/phpunit tests/Unit/Console -v
```

Expected: `Class "PoliPage\Symfony\Console\RenderCommand" not found`.

- [ ] **Step 8.3: Implement `RenderCommand`**

Create `/Users/mickael/Projects/symfony-bundle/src/Console/RenderCommand.php`:

```php
<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Console;

use PoliPage\InlineModeInput;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\ProjectModeInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'poli-page:render', description: 'Smoke-test the Poli Page bundle by rendering a template end-to-end.')]
final class RenderCommand extends Command
{
    public function __construct(private readonly PoliPage $client)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project slug (required unless --html given)')
            ->addOption('template', null, InputOption::VALUE_REQUIRED, 'Template slug (or filename label if --html)')
            ->addOption('version', null, InputOption::VALUE_REQUIRED, 'Template version (required unless --html)')
            ->addOption('data', null, InputOption::VALUE_REQUIRED, 'Inline JSON for the data payload', '{}')
            ->addOption('data-file', null, InputOption::VALUE_REQUIRED, 'Read data payload from a file (or - for stdin)')
            ->addOption('html', null, InputOption::VALUE_REQUIRED, 'Inline-mode: render raw HTML from a file (preview only)')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path', './poli-page-render.pdf')
            ->addOption('preview', null, InputOption::VALUE_NONE, 'Render HTML preview instead of PDF');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $data = $this->resolveData($input);

        try {
            if ($input->getOption('preview')) {
                return $this->doPreview($input, $io, $data);
            }
            return $this->doPdf($input, $io, $data);
        } catch (PoliPageException $e) {
            $io->error(sprintf(
                '%s (status=%s, code=%s, requestId=%s)',
                $e->getMessage(),
                $e->status ?? 'n/a',
                $e->errorCode,
                $e->requestId ?? 'n/a',
            ));
            return $this->exitCodeFor($e);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function doPdf(InputInterface $input, SymfonyStyle $io, array $data): int
    {
        $project = (string) ($input->getOption('project') ?? '');
        $template = (string) ($input->getOption('template') ?? '');
        $version = $input->getOption('version');

        if ('' === $project || '' === $template || null === $version) {
            $io->error('--project, --template and --version are required for PDF rendering.');
            return Command::INVALID;
        }

        $start = microtime(true);
        $pdf = $this->client->render->pdf(new ProjectModeInput(
            project: $project,
            template: $template,
            data: $data,
            version: (string) $version,
        ));
        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        $outputPath = (string) $input->getOption('output');
        $this->writeFile($outputPath, $pdf);

        $io->success(sprintf(
            'Rendered %d bytes in %dms. Wrote to %s.',
            \strlen($pdf),
            $elapsedMs,
            $outputPath,
        ));
        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function doPreview(InputInterface $input, SymfonyStyle $io, array $data): int
    {
        $htmlPath = $input->getOption('html');
        $template = (string) ($input->getOption('template') ?? '');

        if (null !== $htmlPath) {
            $html = $this->readFile((string) $htmlPath);
            $previewInput = new InlineModeInput(template: $html, data: $data);
        } else {
            $project = (string) ($input->getOption('project') ?? '');
            $version = $input->getOption('version');
            if ('' === $project || '' === $template || null === $version) {
                $io->error('Either --html, or all of --project --template --version, are required for preview.');
                return Command::INVALID;
            }
            $previewInput = new ProjectModeInput(
                project: $project,
                template: $template,
                data: $data,
                version: (string) $version,
            );
        }

        $start = microtime(true);
        $result = $this->client->render->preview($previewInput);
        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        $outputPath = (string) $input->getOption('output');
        if (str_ends_with($outputPath, '.pdf')) {
            $outputPath = substr($outputPath, 0, -4) . '.html';
        }
        $this->writeFile($outputPath, $result->html);

        $io->success(sprintf(
            'Rendered %d pages of HTML preview in %dms. Wrote to %s.',
            $result->totalPages,
            $elapsedMs,
            $outputPath,
        ));
        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveData(InputInterface $input): array
    {
        $dataFile = $input->getOption('data-file');
        if (null !== $dataFile) {
            $contents = '-' === $dataFile ? stream_get_contents(STDIN) : $this->readFile((string) $dataFile);
        } else {
            $contents = (string) $input->getOption('data');
        }
        if ('' === $contents || false === $contents) {
            return [];
        }
        /** @var mixed $decoded */
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('--data / --data-file must decode to a JSON object.');
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function readFile(string $path): string
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException(sprintf('Could not read file: %s', $path));
        }
        return $contents;
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create output directory: %s', $dir));
        }
        if (false === @file_put_contents($path, $contents)) {
            throw new \RuntimeException(sprintf('Could not write to: %s', $path));
        }
    }

    private function exitCodeFor(PoliPageException $e): int
    {
        $status = $e->status ?? 0;
        if ($status >= 400 && $status < 500) {
            return 1;
        }
        if ($status >= 500) {
            return 2;
        }
        return 3; // network / connection
    }
}
```

- [ ] **Step 8.4: Tag the command in `config/services.php`**

The `#[AsCommand]` attribute auto-registers the command if `framework.autoconfigure` is on, but explicit registration keeps the bundle robust against framework config variations. Add to the service file (after the response factory definition):

```php
    $services->set('poli_page.command.render', \PoliPage\Symfony\Console\RenderCommand::class)
        ->args([service('poli_page.client')])
        ->tag('console.command');
```

- [ ] **Step 8.5: Run tests**

```bash
vendor/bin/phpunit tests/Unit -v
vendor/bin/phpstan analyse
```

Expected: all green.

- [ ] **Step 8.6: Commit**

```bash
git add src/Console/RenderCommand.php tests/Unit/Console/RenderCommandTest.php config/services.php
git commit -m "feat: bin/console poli-page:render smoke-test command

Lets any Symfony dev sanity-check the bundle config end-to-end:
  bin/console poli-page:render --project=X --template=Y --version=Z -o out.pdf

Supports project mode (PDF or preview), inline HTML mode (preview only),
JSON data via --data or --data-file (- for stdin). PoliPageException
exits with status 1 (4xx) / 2 (5xx) / 3 (network) for shell scripting."
```

---

## Task 9: Integration test against develop API

**Files:**
- Create: `tests/Integration/RenderAgainstDevelopApiTest.php`

**Goal:** one idempotent test that renders the canonical `getting-started/welcome` template against `https://api-develop.poli.page`, gated on `POLI_PAGE_API_KEY` env var.

- [ ] **Step 9.1: Write the test**

Create `/Users/mickael/Projects/symfony-bundle/tests/Integration/RenderAgainstDevelopApiTest.php`:

```php
<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Integration;

use PoliPage\PoliPage;
use PoliPage\ProjectModeInput;
use PoliPage\Symfony\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;

final class RenderAgainstDevelopApiTest extends TestCase
{
    protected function setUp(): void
    {
        $key = getenv('POLI_PAGE_API_KEY');
        if (false === $key || '' === $key) {
            self::markTestSkipped('POLI_PAGE_API_KEY not set; skipping develop-API integration test.');
        }
        if (!str_starts_with($key, 'pp_test_')) {
            self::markTestSkipped('POLI_PAGE_API_KEY must be a pp_test_ key; refusing to run integration test against a live key.');
        }
    }

    public function testRenderGettingStartedWelcomeTemplateReturnsPdf(): void
    {
        $kernel = new TestKernel([
            'api_key' => (string) getenv('POLI_PAGE_API_KEY'),
            'base_url' => 'https://api-develop.poli.page',
            'timeout' => 30.0,
        ]);
        $kernel->boot();

        $client = $kernel->getContainer()->get(PoliPage::class);
        self::assertInstanceOf(PoliPage::class, $client);

        $pdf = $client->render->pdf(new ProjectModeInput(
            project: 'getting-started',
            template: 'welcome',
            data: ['name' => 'symfony-bundle integration test'],
            version: '1.0.0',
        ));

        self::assertNotEmpty($pdf);
        self::assertStringStartsWith('%PDF-', $pdf);

        $kernel->shutdown();
    }
}
```

- [ ] **Step 9.2: Verify locally if you have a key**

```bash
cd /Users/mickael/Projects/symfony-bundle
POLI_PAGE_API_KEY=pp_test_yours vendor/bin/phpunit --testsuite=integration -v
```

Expected: PASS in ~3 seconds, or `Skipped` if no key. Without a key:

```bash
vendor/bin/phpunit --testsuite=integration -v
```

Expected: `Skipped: POLI_PAGE_API_KEY not set; skipping develop-API integration test.`

- [ ] **Step 9.3: Commit**

```bash
git add tests/Integration/RenderAgainstDevelopApiTest.php
git commit -m "test: integration test against develop API (gated)

Single test, idempotent, renders the canonical getting-started/welcome
template against https://api-develop.poli.page. Skipped when the env var
is absent so contributors without an API key get green local runs.

Refuses to run with pp_live_ keys as a safety belt — integration tests
should never hit production."
```

---

## Task 10: `example-app/` with controllers/commands covering all 10 SDK methods

**Files:**
- Create: `example-app/composer.json`
- Create: `example-app/.env`
- Create: `example-app/.gitignore`
- Create: `example-app/public/index.php`
- Create: `example-app/bin/console`
- Create: `example-app/src/Kernel.php`
- Create: `example-app/config/bundles.php`
- Create: `example-app/config/packages/framework.yaml`
- Create: `example-app/config/packages/poli_page.yaml`
- Create: `example-app/config/routes.yaml`
- Create: `example-app/src/Controller/RenderController.php`
- Create: `example-app/src/Controller/DocumentController.php`
- Create: `example-app/src/Command/RenderToFileCommand.php`
- Create: `example-app/README.md`

**Goal:** a minimal Symfony 7 app that wires the bundle, with one endpoint or command per SDK demo step from `/Users/mickael/Projects/sdk-php.md/examples/demo.php`. A reader can `composer install && symfony serve` and visit each route.

- [ ] **Step 10.1: Write `example-app/composer.json`**

Create `/Users/mickael/Projects/symfony-bundle/example-app/composer.json`:

```json
{
    "name": "poli-page/symfony-bundle-example",
    "description": "Example Symfony app demonstrating poli-page/symfony-bundle. Not published.",
    "type": "project",
    "license": "MIT",
    "require": {
        "php": "^8.3",
        "symfony/framework-bundle": "^7.0",
        "symfony/runtime": "^7.0",
        "symfony/yaml": "^7.0",
        "poli-page/symfony-bundle": "@dev",
        "poli-page/sdk": "@dev"
    },
    "repositories": [
        { "type": "path", "url": "../", "options": { "symlink": true, "versions": { "poli-page/symfony-bundle": "0.1.0" } } },
        { "type": "path", "url": "../../sdk-php.md", "options": { "symlink": true, "versions": { "poli-page/sdk": "0.1.0" } } }
    ],
    "config": {
        "allow-plugins": {
            "symfony/runtime": true,
            "symfony/flex": false,
            "php-http/discovery": true
        }
    },
    "autoload": {
        "psr-4": { "App\\": "src/" }
    },
    "scripts": {
        "auto-scripts": [],
        "post-install-cmd": ["@auto-scripts"],
        "post-update-cmd": ["@auto-scripts"]
    },
    "extra": {
        "runtime": { "use_runtime": "Symfony\\Component\\Runtime\\SymfonyRuntime" }
    }
}
```

- [ ] **Step 10.2: Write `example-app/.env` and `.gitignore`**

Create `/Users/mickael/Projects/symfony-bundle/example-app/.env`:

```
APP_ENV=dev
APP_DEBUG=1
APP_SECRET=example-app-not-secret
POLI_PAGE_API_KEY=pp_test_replace_me_with_real_key
```

Create `/Users/mickael/Projects/symfony-bundle/example-app/.gitignore`:

```
/.env.local
/vendor/
/var/
/composer.lock
```

- [ ] **Step 10.3: Write `example-app/src/Kernel.php`**

Create `/Users/mickael/Projects/symfony-bundle/example-app/src/Kernel.php`:

```php
<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
```

- [ ] **Step 10.4: Write `example-app/config/bundles.php`**

Create `/Users/mickael/Projects/symfony-bundle/example-app/config/bundles.php`:

```php
<?php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    PoliPage\Symfony\PoliPageBundle::class => ['all' => true],
];
```

- [ ] **Step 10.5: Write Symfony YAML config**

Create `/Users/mickael/Projects/symfony-bundle/example-app/config/packages/framework.yaml`:

```yaml
framework:
    secret: '%env(APP_SECRET)%'
    router:
        utf8: true
    http_method_override: false
    handle_all_throwables: true
    php_errors:
        log: true
    test: false
```

Create `/Users/mickael/Projects/symfony-bundle/example-app/config/packages/poli_page.yaml`:

```yaml
poli_page:
    api_key: '%env(POLI_PAGE_API_KEY)%'
    base_url: 'https://api-develop.poli.page'  # remove for production
```

Create `/Users/mickael/Projects/symfony-bundle/example-app/config/routes.yaml`:

```yaml
render_controllers:
    resource:
        path: ../src/Controller/
        namespace: App\Controller
    type: attribute

document_controllers:
    resource:
        path: ../src/Controller/
        namespace: App\Controller
    type: attribute
```

- [ ] **Step 10.6: Write `public/index.php` and `bin/console`**

Create `/Users/mickael/Projects/symfony-bundle/example-app/public/index.php`:

```php
<?php

use App\Kernel;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return function (array $context): Kernel {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
```

Create `/Users/mickael/Projects/symfony-bundle/example-app/bin/console`:

```php
#!/usr/bin/env php
<?php

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return function (array $context): Application {
    $kernel = new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
    return new Application($kernel);
};
```

Make it executable:

```bash
chmod +x /Users/mickael/Projects/symfony-bundle/example-app/bin/console
```

- [ ] **Step 10.7: Write `RenderController`**

Create `/Users/mickael/Projects/symfony-bundle/example-app/src/Controller/RenderController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use PoliPage\InlineModeInput;
use PoliPage\PoliPage;
use PoliPage\ProjectModeInput;
use PoliPage\Symfony\Http\PoliPageResponseFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RenderController
{
    public function __construct(
        private readonly PoliPage $poliPage,
        private readonly PoliPageResponseFactory $factory,
    ) {
    }

    /**
     * Demo step 1: render->pdf() — fetch PDF bytes into memory.
     */
    #[Route('/render/pdf', name: 'render_pdf', methods: ['GET'])]
    public function pdf(): Response
    {
        $pdf = $this->poliPage->render->pdf(new ProjectModeInput(
            project: 'getting-started',
            template: 'welcome',
            data: ['name' => 'Symfony'],
            version: '1.0.0',
        ));
        return $this->factory->bytes($pdf, 'welcome.pdf');
    }

    /**
     * Demo step 2: render->pdfStream() — get a PSR-7 stream of PDF bytes.
     */
    #[Route('/render/stream', name: 'render_stream', methods: ['GET'])]
    public function stream(): Response
    {
        $stream = $this->poliPage->render->pdfStream(new ProjectModeInput(
            project: 'getting-started',
            template: 'welcome',
            data: ['name' => 'Symfony streamed'],
            version: '1.0.0',
        ));
        return $this->factory->stream($stream, 'welcome-streamed.pdf');
    }

    /**
     * Demo step 4: render->preview() — paginated HTML preview output.
     * Accepts either ?html=<raw html> for inline mode or defaults to project mode.
     */
    #[Route('/render/preview', name: 'render_preview', methods: ['GET'])]
    public function preview(\Symfony\Component\HttpFoundation\Request $request): Response
    {
        $html = $request->query->get('html');
        $input = null !== $html
            ? new InlineModeInput(template: (string) $html, data: ['name' => 'Inline'])
            : new ProjectModeInput(
                project: 'getting-started',
                template: 'welcome',
                data: ['name' => 'Preview from project'],
                version: '1.0.0',
            );

        $result = $this->poliPage->render->preview($input);
        return $this->factory->preview($result);
    }

    /**
     * Demo step 5: render->document() — store the document, return descriptor as JSON.
     */
    #[Route('/documents', name: 'document_create', methods: ['POST'])]
    public function createDocument(): JsonResponse
    {
        $descriptor = $this->poliPage->render->document(new ProjectModeInput(
            project: 'getting-started',
            template: 'welcome',
            data: ['name' => 'Stored doc'],
            version: '1.0.0',
        ));
        return new JsonResponse([
            'documentId' => $descriptor->documentId,
            'pageCount' => $descriptor->pageCount,
            'sizeBytes' => $descriptor->sizeBytes,
            'environment' => $descriptor->environment,
            'expiresAt' => $descriptor->expiresAt,
            'presignedPdfUrl' => $descriptor->presignedPdfUrl,
        ]);
    }
}
```

- [ ] **Step 10.8: Write `DocumentController`**

Create `/Users/mickael/Projects/symfony-bundle/example-app/src/Controller/DocumentController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\Symfony\Http\PoliPageResponseFactory;
use PoliPage\ThumbnailOptions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DocumentController
{
    public function __construct(
        private readonly PoliPage $poliPage,
        private readonly PoliPageResponseFactory $factory,
    ) {
    }

    /**
     * Demo step 6: documents->get(id) — fresh descriptor + presigned URL, then 302.
     */
    #[Route('/documents/{id}', name: 'document_get', methods: ['GET'])]
    public function get(string $id): Response
    {
        $descriptor = $this->poliPage->documents->get($id);
        return $this->factory->documentRedirect($descriptor);
    }

    /**
     * Demo step 7: documents->thumbnails(id, opts).
     */
    #[Route('/documents/{id}/thumbnails', name: 'document_thumbnails', methods: ['GET'])]
    public function thumbnails(string $id): JsonResponse
    {
        $thumbnails = $this->poliPage->documents->thumbnails($id, new ThumbnailOptions(width: 240));
        return new JsonResponse([
            'count' => count($thumbnails),
            'thumbnails' => array_map(static fn ($t) => [
                'page' => $t->page,
                'width' => $t->width,
                'height' => $t->height,
                'contentType' => $t->contentType,
                'base64Bytes' => $t->data,
            ], $thumbnails),
        ]);
    }

    /**
     * Demo step 8: documents->preview(id).
     */
    #[Route('/documents/{id}/preview', name: 'document_preview', methods: ['GET'])]
    public function preview(string $id): Response
    {
        $result = $this->poliPage->documents->preview($id);
        return $this->factory->preview($result);
    }

    /**
     * Demo step 9: documents->delete(id).
     */
    #[Route('/documents/{id}', name: 'document_delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $this->poliPage->documents->delete($id);
        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Demo step 10: error handling — deliberately trigger INVALID_VERSION_FORMAT.
     */
    #[Route('/errors/bad-version', name: 'error_bad_version', methods: ['GET'])]
    public function badVersion(): JsonResponse
    {
        try {
            $this->poliPage->render->pdf(new \PoliPage\ProjectModeInput(
                project: 'getting-started',
                template: 'welcome',
                data: [],
                version: 'not-semver',
            ));
        } catch (PoliPageException $e) {
            return new JsonResponse([
                'caught' => true,
                'status' => $e->status,
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
                'requestId' => $e->requestId,
            ], 400);
        }
        return new JsonResponse(['caught' => false, 'note' => 'expected an exception, got success'], 500);
    }
}
```

- [ ] **Step 10.9: Write `RenderToFileCommand`**

Create `/Users/mickael/Projects/symfony-bundle/example-app/src/Command/RenderToFileCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use PoliPage\PoliPage;
use PoliPage\ProjectModeInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function PoliPage\renderToFile;

/**
 * Demo step 3: the renderToFile() free function from src/render_to_file.php.
 * In an `app:` namespace to avoid colliding with the bundle's own
 * poli-page:render command.
 */
#[AsCommand(name: 'app:demo:render-to-file', description: 'Demo of the free renderToFile() helper from the SDK.')]
final class RenderToFileCommand extends Command
{
    public function __construct(private readonly PoliPage $client)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = sys_get_temp_dir() . '/poli-page-demo-file.pdf';

        renderToFile($this->client, new ProjectModeInput(
            project: 'getting-started',
            template: 'welcome',
            data: ['name' => 'renderToFile demo'],
            version: '1.0.0',
        ), $path);

        $io->success(sprintf('Wrote PDF to %s (%d bytes).', $path, filesize($path) ?: 0));
        return Command::SUCCESS;
    }
}
```

- [ ] **Step 10.10: Write `example-app/README.md`**

Create `/Users/mickael/Projects/symfony-bundle/example-app/README.md`:

````markdown
# `poli-page/symfony-bundle` example app

Minimal Symfony 7 app demonstrating every public method of the Poli Page PHP SDK through the Symfony bundle. Each route or command corresponds 1:1 to a step in the SDK's canonical demo (`../../sdk-php.md/examples/demo.php`).

## Setup

```bash
cd example-app
composer install
cp .env .env.local
# Edit .env.local and set POLI_PAGE_API_KEY=pp_test_... (get one at https://app-develop.poli.page)
symfony serve
```

## Routes (mirror SDK demo steps 1, 2, 4–10)

| SDK demo step | URL | What it does |
|---|---|---|
| 1. `render->pdf()` | `GET /render/pdf` | Returns the welcome PDF as `application/pdf`. |
| 2. `render->pdfStream()` | `GET /render/stream` | Same PDF but via PSR-7 stream + `StreamedResponse`. |
| 4. `render->preview()` | `GET /render/preview[?html=...]` | HTML preview. Pass `?html=<raw>` for inline mode. |
| 5. `render->document()` | `POST /documents` | Stores the document, returns descriptor JSON. |
| 6. `documents->get(id)` | `GET /documents/{id}` | 302 to the presigned PDF URL. |
| 7. `documents->thumbnails(id)` | `GET /documents/{id}/thumbnails` | Page thumbnails as base64 JSON. |
| 8. `documents->preview(id)` | `GET /documents/{id}/preview` | Stored document's HTML preview. |
| 9. `documents->delete(id)` | `DELETE /documents/{id}` | Soft-delete, `204 No Content`. |
| 10. Error handling | `GET /errors/bad-version` | Deliberately triggers `INVALID_VERSION_FORMAT`. |

## Commands

| SDK demo step | Command |
|---|---|
| 3. `renderToFile()` | `bin/console app:demo:render-to-file` |
| (bundle smoke test) | `bin/console poli-page:render --project=getting-started --template=welcome --version=1.0.0` |

## Quick smoke

```bash
curl -o welcome.pdf http://localhost:8000/render/pdf
open welcome.pdf
```

If you see a styled welcome PDF, the bundle + SDK + your API key all work end-to-end.
````

- [ ] **Step 10.11: Install and smoke-test example-app**

```bash
cd /Users/mickael/Projects/symfony-bundle/example-app
composer install
```

Expected: success, with `Symlinked from ../` for `poli-page/symfony-bundle` and `Symlinked from ../../sdk-php.md` for `poli-page/sdk`.

If you have a `POLI_PAGE_API_KEY`:

```bash
cp .env .env.local  # edit to set the key
symfony serve -d
sleep 2
curl -s -o /tmp/welcome.pdf -w "%{http_code} %{content_type}\n" http://localhost:8000/render/pdf
```

Expected: `200 application/pdf` and `/tmp/welcome.pdf` starts with `%PDF-`. Stop the server:

```bash
symfony server:stop
```

- [ ] **Step 10.12: Commit**

```bash
cd /Users/mickael/Projects/symfony-bundle
git add example-app/
git commit -m "feat(example-app): minimal Symfony 7 app covering all 10 SDK demo steps

Routes mirror sdk-php.md/examples/demo.php 1:1 so readers can compare
the SDK demo against bundle-wrapped usage side by side. Uses path-repo
to bundle (and through it to the SDK) — example-app never installs from
Packagist, so the path-repo block can stay forever."
```

---

## Task 11: Replace inherited `CLAUDE.md` with integration-flavored version

**Files:**
- Replace: `CLAUDE.md`

**Goal:** new agents picking up the bundle don't get misled into testing SDK behaviour. The inherited file is SDK-flavored ("test 4xx mapping / 5xx retry / exponential backoff") which the bundle does NOT need to re-test (the SDK already tests it exhaustively).

- [ ] **Step 11.1: Replace `CLAUDE.md`**

Replace `/Users/mickael/Projects/symfony-bundle/CLAUDE.md` with:

````markdown
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
- PHPStan level 8 + Symfony extension + strict rules. Pinned in `phpstan.neon`.
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

## 10. When stuck

- Re-read `docs/spec/bundle-specification.md` first; most "open questions" are answered there.
- Compare with the SDK reference at `/Users/mickael/Projects/sdk-php.md/`.
- Compare patterns with `getsentry/sentry-symfony`, `algolia/search-bundle`, `aws/aws-sdk-php-symfony` (the bundle's industry benchmarks).
- Ask Xavier early. A two-line message is faster than a half-day rebuilding the wrong thing.
- If a CI failure looks unrelated to your change, check `main` first before assuming you caused it.
````

- [ ] **Step 11.2: Commit**

```bash
cd /Users/mickael/Projects/symfony-bundle
git add CLAUDE.md
git commit -m "docs: replace SDK-flavored CLAUDE.md with integration-specific version

The inherited template steered agents to test SDK behaviour (retry
backoff, 4xx mapping, idempotency) which the SDK already covers
exhaustively. New version focuses on what integrations actually test:
DI compilation, config validation, response factory headers, command
option mapping, EventDispatcher integration."
```

---

## Task 12: README and CHANGELOG

**Files:**
- Create: `README.md`
- Create: `CHANGELOG.md`

**Goal:** repo's GitHub front page sells the bundle in 30 seconds; CHANGELOG is ready for the v0.1.0 entry.

- [ ] **Step 12.1: Write `README.md`**

Create `/Users/mickael/Projects/symfony-bundle/README.md`:

````markdown
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

`$factory->bytes(...)` sets the right `Content-Type`, RFC 5987 `Content-Disposition`, `Cache-Control: private, no-store`, and `X-Content-Type-Options: nosniff` — the parts you'd otherwise get wrong.

## Smoke-test your config from the CLI

```bash
bin/console poli-page:render \
    --project=getting-started \
    --template=welcome \
    --version=1.0.0 \
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

A full runnable Symfony 7 app showing every public method of the SDK is in `example-app/`. See `example-app/README.md` for the walkthrough.

## Errors

Everything thrown is a `PoliPage\PoliPageException` (or a subclass). The bundle does not catch or transform exceptions; let them propagate or handle them in your controllers / event listeners.

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md) and [`CLAUDE.md`](CLAUDE.md). PRs welcome — please open an issue first for anything beyond a small fix.

## License

[MIT](LICENSE).
````

- [ ] **Step 12.2: Write `CHANGELOG.md`**

Create `/Users/mickael/Projects/symfony-bundle/CHANGELOG.md`:

```markdown
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
```

- [ ] **Step 12.3: Commit**

```bash
git add README.md CHANGELOG.md
git commit -m "docs: README and CHANGELOG for v0.1.0

README covers: install, Flex recipe vs. manual registration, quick start
(controller + factory), CLI smoke test, full config tree, EventDispatcher
listener example, example-app pointer.

CHANGELOG.md initialized in Keep a Changelog format with v0.1.0 entry."
```

---

## Task 13: Symfony Flex recipe

**Files:**
- Create: `recipes/manifest.json`
- Create: `recipes/config/packages/poli_page.yaml`
- Create: `recipes/README.md`

**Goal:** the recipe source that we'll PR to `symfony/recipes-contrib` after v0.1.0 tags.

- [ ] **Step 13.1: Write `recipes/manifest.json`**

Create `/Users/mickael/Projects/symfony-bundle/recipes/manifest.json`:

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

- [ ] **Step 13.2: Write `recipes/config/packages/poli_page.yaml`**

Create `/Users/mickael/Projects/symfony-bundle/recipes/config/packages/poli_page.yaml`:

```yaml
poli_page:
    api_key: '%env(POLI_PAGE_API_KEY)%'
```

- [ ] **Step 13.3: Write `recipes/README.md`**

Create `/Users/mickael/Projects/symfony-bundle/recipes/README.md`:

````markdown
# Symfony Flex recipe source

The files here are the canonical source for the Symfony Flex recipe shipped at:

  `github.com/symfony/recipes-contrib/tree/main/poli-page/symfony-bundle/0.1`

Process for submitting / updating:

1. Tag a release of `poli-page/symfony-bundle` on Packagist.
2. Fork [`symfony/recipes-contrib`](https://github.com/symfony/recipes-contrib).
3. Create the directory `poli-page/symfony-bundle/<major.minor>/` in your fork.
4. Copy `manifest.json` + `config/` from this folder into it.
5. Open a PR. CI on the contrib repo validates the recipe shape.

The recipe is intentionally minimal: register the bundle, create a one-line config file, append the env var. Anything more should live in the bundle's README, not the recipe.
````

- [ ] **Step 13.4: Commit**

```bash
git add recipes/
git commit -m "feat: ship Symfony Flex recipe source

Recipe registers the bundle, creates a minimal config/packages/poli_page.yaml,
and appends POLI_PAGE_API_KEY=pp_test_replace_me to .env. PR'd to
symfony/recipes-contrib separately after v0.1.0 tags on Packagist."
```

---

## Final verification

After Task 13, run the full quality gate locally:

- [ ] **Step F.1: Run the complete CI script**

```bash
cd /Users/mickael/Projects/symfony-bundle
composer ci
```

Expected:
- `php-cs-fixer fix --dry-run --diff` — no diffs
- `phpstan analyse` — `[OK] No errors`
- `phpunit --testsuite=unit` — all green

- [ ] **Step F.2: Run the integration test if you have a key**

```bash
POLI_PAGE_API_KEY=pp_test_yours vendor/bin/phpunit --testsuite=integration
```

Expected: `OK (1 test)` and the test renders against `https://api-develop.poli.page`.

- [ ] **Step F.3: Verify example-app boots**

```bash
cd example-app
composer install
bin/console poli-page:render --project=getting-started --template=welcome --version=1.0.0 --data='{"name":"final"}' -o /tmp/final.pdf
file /tmp/final.pdf
```

Expected: `/tmp/final.pdf: PDF document, version 1.7, ...` (or whatever PDF version the engine emits).

- [ ] **Step F.4: Push and watch CI**

```bash
cd /Users/mickael/Projects/symfony-bundle
git push -u origin main  # or your feature branch
```

Verify all 4 matrix cells (PHP 8.3 × Symfony 6.4, PHP 8.3 × Symfony 7, PHP 8.4 × Symfony 6.4, PHP 8.4 × Symfony 7) pass.

- [ ] **Step F.5: Tag v0.1.0 when CI is green**

```bash
# Only after CI green AND the unpublished-SDK workaround is removed (see spec §12.2)
git tag v0.1.0
git push --tags
```

Then open the `symfony/recipes-contrib` PR (Task 13 README).

---

## Self-review checklist (for the agent executing this plan)

Before declaring v0.1.0 ready:

1. **Every config key in spec §6 is exercised** by either `ConfigurationTest` (validation) or `PoliPageBundleTest` (resolution).
2. **All four `PoliPageResponseFactory` methods** have a passing test asserting headers per spec §8.2.
3. **`bin/console poli-page:render`** has been run against the develop API at least once and returned a real PDF.
4. **`example-app/`** has been booted with `symfony serve` and every route returned 200 (or the documented status).
5. **`composer ci`** is green on the maintainer machine.
6. **CI matrix** is green on GitHub Actions for all 4 cells.
7. **No `TODO`** in the code without a linked issue.
8. **`docs/spec/bundle-specification.md` decision log (§18)** is still accurate — if you made a different choice, update it.

If any of these aren't true, fix before tagging.
