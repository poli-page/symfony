<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Contract;

use App\Kernel;
use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoliPage\Symfony\Tests\RestoresGlobalHandlers;
use RuntimeException;
use stdClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Runs the `impl` cases of the API contract (poli-page/api-contract, file at
 * env POLI_CONTRACT) against the example app in example-app/: its Kernel,
 * config/ and controllers. Per case, a local mock API answers the case's
 * `responses`, the app's SDK points at it through its POLI_PAGE_BASE_URL
 * (the app's `poli_page.base_url`), the request goes through the
 * framework's KernelBrowser, and the app's answer and the SDK's requests are
 * compared with `expect`. The SDK keeps its default retry settings.
 */
final class ImplContractTest extends TestCase
{
    use RestoresGlobalHandlers;

    private const EXAMPLE_APP = __DIR__.'/../../example-app';

    private const SKIP_LIST = __DIR__.'/../contract-skip.json';

    private const FORMAT_VERSION = 1;

    private const API_KEY = 'pp_sandbox_0123456789abcdef0123456789abcdef';

    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    private const REQUEST_FIELDS = ['method', 'path'];

    private const EXPECT_FIELDS = ['status', 'location', 'contentType', 'json', 'requests'];

    private const EXPECTED_REQUEST_FIELDS = ['method', 'path', 'auth', 'idempotencyKey'];

    /** @var array{cases: array<string, array{assoc: array<string, mixed>, responses: list<mixed>}>, skip: array<string, string>}|null */
    private static ?array $contract = null;

    /** The kernel's cache and logs, shared by the cases: the env vars are read at runtime. */
    private static ?string $varDir = null;

    /** @var array<string, array{server: mixed, env: mixed, getenv: string|false}> */
    private array $savedEnv = [];

    /**
     * @return iterable<string, array{string}>
     */
    public static function implCases(): iterable
    {
        try {
            $ids = array_keys(self::contract()['cases']);
        } catch (Throwable) {
            // The test reports why: a missing POLI_CONTRACT, or an invalid contract.
            yield 'contract.json' => [''];

            return;
        }
        foreach ($ids as $id) {
            yield $id => [$id];
        }
    }

    #[DataProvider('implCases')]
    public function testImplCase(string $id): void
    {
        if (false === getenv('POLI_CONTRACT') || '' === getenv('POLI_CONTRACT')) {
            $message = 'POLI_CONTRACT is not set: point it at the contract.json of poli-page/api-contract';
            if (false !== getenv('CI') && '' !== getenv('CI')) {
                self::fail($message.' (required in CI).');
            }
            self::markTestSkipped($message.'.');
        }
        $contract = self::contract();
        $case = $contract['cases'][$id] ?? throw new RuntimeException('No impl case '.$id);
        $skipReason = $contract['skip'][$id] ?? null;

        try {
            $this->runCase($case['assoc'], $case['responses']);
        } catch (Throwable $e) {
            if (null !== $skipReason) {
                self::markTestSkipped(\sprintf('contract-skip.json: %s (fails with: %s)', $skipReason, $e->getMessage()));
            }
            throw $e;
        }
        if (null !== $skipReason) {
            self::fail(\sprintf('%s passes: remove it from contract-skip.json', $id));
        }
    }

    /**
     * @param array<string, mixed> $case
     * @param list<mixed>          $responses
     */
    private function runCase(array $case, array $responses): void
    {
        $mock = new MockServer($responses);
        try {
            $this->setEnv('POLI_PAGE_API_KEY', self::API_KEY);
            $this->setEnv('POLI_PAGE_BASE_URL', $mock->origin);
            $kernel = self::bootExampleApp();
            try {
                $request = self::object(self::substitute($case['request'] ?? null, $mock->origin), 'request');
                self::onlyFields($request, self::REQUEST_FIELDS, 'request');
                $client = new KernelBrowser($kernel);
                $client->request(self::string($request['method'] ?? null, 'request.method'), self::string($request['path'] ?? null, 'request.path'));
                $response = $client->getResponse();
                $body = self::body($response);
            } finally {
                $kernel->shutdown();
            }
        } finally {
            $recording = $mock->stop();
        }

        self::assertSame([], $recording['problems'], 'The mock server could not answer as the case says');
        $expect = self::object(self::substitute($case['expect'] ?? null, $mock->origin), 'expect');
        self::onlyFields($expect, self::EXPECT_FIELDS, 'expect');
        $context = \sprintf("\nAnswer: %d %s\n%s", $response->getStatusCode(), (string) $response->headers->get('Content-Type'), substr($body, 0, 2000));

        self::assertSame($expect['status'] ?? null, $response->getStatusCode(), 'status'.$context);
        if (\array_key_exists('location', $expect)) {
            self::assertSame($expect['location'], $response->headers->get('Location'), 'location'.$context);
        }
        if (\array_key_exists('contentType', $expect)) {
            $mediaType = strtolower(trim(explode(';', (string) $response->headers->get('Content-Type'))[0]));
            self::assertSame($expect['contentType'], $mediaType, 'contentType'.$context);
        }
        if (\array_key_exists('json', $expect)) {
            $json = json_decode($body, true);
            self::assertSame(\JSON_ERROR_NONE, json_last_error(), 'json: the body is not JSON'.$context);
            self::assertSame(self::sortKeys($expect['json']), self::sortKeys($json), 'json'.$context);
        }
        if (\array_key_exists('requests', $expect)) {
            self::assertRequests(self::list($expect['requests'], 'expect.requests'), $recording['requests']);
        }
    }

    /**
     * @param list<mixed>                                                                                  $expected
     * @param list<array{method: string, target: string, authorization: ?string, idempotencyKey: ?string}> $actual
     */
    private static function assertRequests(array $expected, array $actual): void
    {
        $seen = array_map(static fn (array $r): string => $r['method'].' '.$r['target'], $actual);
        self::assertCount(\count($expected), $actual, 'requests the SDK sent: '.implode(', ', $seen));

        /** @var array<string, string> $stableKeys method and path => the key of the call's first attempt */
        $stableKeys = [];
        foreach ($expected as $index => $item) {
            $want = self::object($item, 'expect.requests['.$index.']');
            self::onlyFields($want, self::EXPECTED_REQUEST_FIELDS, 'expect.requests['.$index.']');
            $got = $actual[$index];
            $label = \sprintf('request %d (%s %s)', $index + 1, $got['method'], $got['target']);

            self::assertSame($want['method'] ?? null, $got['method'], $label.': method');
            self::assertSame($want['path'] ?? null, $got['target'], $label.': path');

            match ($want['auth'] ?? null) {
                'bearer' => self::assertSame('Bearer '.self::API_KEY, $got['authorization'], $label.': Authorization'),
                'none' => self::assertNull($got['authorization'], $label.': no Authorization header'),
                default => self::fail($label.': unknown auth '.json_encode($want['auth'] ?? null)),
            };

            $key = $got['idempotencyKey'];
            switch ($want['idempotencyKey'] ?? null) {
                case 'stable':
                    self::assertIsString($key, $label.': Idempotency-Key is missing');
                    self::assertMatchesRegularExpression(self::UUID_V4, $key, $label.': Idempotency-Key is not a lowercase UUID v4');
                    $call = $got['method'].' '.$got['target'];
                    $stableKeys[$call] ??= $key;
                    self::assertSame($stableKeys[$call], $key, $label.': Idempotency-Key changed between attempts');
                    break;
                case 'absent':
                    self::assertNull($key, $label.': Idempotency-Key must be absent');
                    break;
                default:
                    self::fail($label.': unknown idempotencyKey '.json_encode($want['idempotencyKey'] ?? null));
            }
        }
    }

    private static function bootExampleApp(): Kernel
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->addPsr4('App\\', self::EXAMPLE_APP.'/src/');
            break;
        }
        if (null === self::$varDir) {
            self::$varDir = sys_get_temp_dir().'/poli-contract-symfony-'.bin2hex(random_bytes(6));
            register_shutdown_function(static fn () => self::removeDir((string) self::$varDir));
        }
        // The cache and logs of the run stay out of example-app/var.
        foreach (['APP_CACHE_DIR' => '/cache', 'APP_BUILD_DIR' => '/build', 'APP_SHARE_DIR' => '/share', 'APP_LOG_DIR' => '/log'] as $name => $sub) {
            $_SERVER[$name] = self::$varDir.$sub;
        }
        // example-app/.env sets APP_SECRET; nothing here loads .env files.
        $_SERVER['APP_SECRET'] ??= 'contract-test';

        $kernel = new Kernel('test', false);
        $kernel->boot();

        return $kernel;
    }

    private function setEnv(string $name, string $value): void
    {
        $this->savedEnv[$name] ??= ['server' => $_SERVER[$name] ?? null, 'env' => $_ENV[$name] ?? null, 'getenv' => getenv($name)];
        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
        putenv($name.'='.$value);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $saved) {
            if (null === $saved['server']) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $saved['server'];
            }
            if (null === $saved['env']) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $saved['env'];
            }
            putenv(false === $saved['getenv'] ? $name : $name.'='.$saved['getenv']);
        }
        $this->savedEnv = [];
        parent::tearDown();
    }

    /**
     * @return array{cases: array<string, array{assoc: array<string, mixed>, responses: list<mixed>}>, skip: array<string, string>}
     */
    private static function contract(): array
    {
        return self::$contract ??= self::readContract();
    }

    /**
     * @return array{cases: array<string, array{assoc: array<string, mixed>, responses: list<mixed>}>, skip: array<string, string>}
     */
    private static function readContract(): array
    {
        $path = getenv('POLI_CONTRACT');
        if (false === $path || '' === $path) {
            throw new RuntimeException('POLI_CONTRACT is not set');
        }
        $text = @file_get_contents($path);
        if (false === $text) {
            throw new RuntimeException(\sprintf('POLI_CONTRACT=%s cannot be read', $path));
        }
        $assoc = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        // Decoded again with objects, so the mock answers a JSON `{}` as `{}`.
        $objects = json_decode($text, false, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($assoc) || !$objects instanceof stdClass) {
            throw new RuntimeException('contract.json is not an object');
        }
        if (self::FORMAT_VERSION !== ($assoc['formatVersion'] ?? null)) {
            throw new RuntimeException(\sprintf('contract.json formatVersion %s is not supported (this test reads format %d)', json_encode($assoc['formatVersion'] ?? null), self::FORMAT_VERSION));
        }
        $cases = self::list($assoc['cases'] ?? null, 'cases');
        $caseObjects = self::list($objects->cases ?? null, 'cases');

        $impl = [];
        foreach ($cases as $index => $raw) {
            $case = self::object($raw, \sprintf('cases[%d]', $index));
            $kind = $case['kind'] ?? null;
            if ('sdk' === $kind) {
                continue;
            }
            if ('impl' !== $kind) {
                throw new RuntimeException(\sprintf('cases[%d] has kind %s: only "sdk" and "impl" are known', $index, json_encode($kind)));
            }
            $id = self::string($case['id'] ?? null, \sprintf('cases[%d].id', $index));
            if (isset($impl[$id])) {
                throw new RuntimeException('Duplicate case id '.$id);
            }
            $object = $caseObjects[$index] ?? null;
            $responses = $object instanceof stdClass ? ($object->responses ?? null) : null;
            $impl[$id] = ['assoc' => $case, 'responses' => self::list($responses, $id.'.responses')];
        }
        if ([] === $impl) {
            throw new RuntimeException('contract.json has no "impl" case');
        }

        $skipText = @file_get_contents(self::SKIP_LIST);
        $skipList = false === $skipText ? null : json_decode($skipText, true);
        if (!\is_array($skipList)) {
            throw new RuntimeException('tests/contract-skip.json must be an object of case id to reason');
        }
        $skip = [];
        foreach ($skipList as $id => $reason) {
            if (!isset($impl[(string) $id])) {
                throw new RuntimeException(\sprintf('tests/contract-skip.json lists "%s", which is not an impl case of the contract', $id));
            }
            if (!\is_string($reason) || '' === trim($reason)) {
                throw new RuntimeException(\sprintf('tests/contract-skip.json: "%s" needs a reason', $id));
            }
            $skip[(string) $id] = $reason;
        }

        return ['cases' => $impl, 'skip' => $skip];
    }

    private static function body(Response $response): string
    {
        if ($response instanceof StreamedResponse) {
            ob_start();
            $response->sendContent();

            return (string) ob_get_clean();
        }

        return (string) $response->getContent();
    }

    private static function substitute(mixed $value, string $origin): mixed
    {
        if (\is_string($value)) {
            return (string) preg_replace_callback('/\{\{([^}]*)\}\}/', static function (array $match) use ($origin): string {
                if ('mock' === $match[1]) {
                    return $origin;
                }
                if (1 === preg_match('/^httpDate\+(\d+)$/', $match[1], $date)) {
                    return gmdate('D, d M Y H:i:s', (int) round(microtime(true) + (int) $date[1])).' GMT';
                }
                throw new RuntimeException(\sprintf('Unknown contract placeholder {{%s}}', $match[1]));
            }, $value);
        }
        if (\is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::substitute($item, $origin), $value);
        }

        return $value;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        $sorted = array_map(self::sortKeys(...), $value);
        if (!array_is_list($sorted)) {
            ksort($sorted);
        }

        return $sorted;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string>         $known
     */
    private static function onlyFields(array $value, array $known, string $what): void
    {
        $unknown = array_diff(array_keys($value), $known);
        if ([] !== $unknown) {
            throw new RuntimeException(\sprintf('%s has fields this test does not check: %s', $what, implode(', ', $unknown)));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(mixed $value, string $what): array
    {
        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            throw new RuntimeException($what.' is not an object');
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value, string $what): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new RuntimeException($what.' is not a list');
        }

        return $value;
    }

    private static function string(mixed $value, string $what): string
    {
        return \is_string($value) ? $value : throw new RuntimeException($what.' is not a string');
    }

    private static function removeDir(string $dir): void
    {
        $items = is_dir($dir) ? scandir($dir) : false;
        foreach (false === $items ? [] : array_diff($items, ['.', '..']) as $item) {
            $path = $dir.'/'.$item;
            is_dir($path) && !is_link($path) ? self::removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
