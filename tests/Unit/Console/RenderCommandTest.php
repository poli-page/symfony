<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit\Console;

use Composer\Autoload\ClassLoader;
use DG\BypassFinals;
use GuzzleHttp\Psr7\HttpFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\Render;
use PoliPage\RenderInput;
use PoliPage\Symfony\Console\RenderCommand;
use ReflectionClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Psr18Client;

final class RenderCommandTest extends TestCase
{
    private const API_KEY = 'pp_sandbox_0123456789abcdef0123456789abcdef';

    private string $outputPath;

    public static function setUpBeforeClass(): void
    {
        // Why: PoliPage\Render is final; PHPUnit can't mock it directly.
        // BypassFinals strips the final modifier from classes at load time
        // so createMock(Render::class) works. The whitelist must stay
        // scoped to SDK files — stripping final from PHPUnit's own classes
        // breaks readonly inheritance constraints. We resolve the SDK's
        // actual source directory at runtime via Composer's autoloader so
        // the whitelist works regardless of install layout (sibling-dir
        // symlink for local dev, plain vendor/ install in CI/Docker).
        // ClassLoader::findFile() returns the path without triggering
        // autoload, which is required: loading Render before BypassFinals
        // is enabled would freeze `final` in memory.
        BypassFinals::enable();
        BypassFinals::setWhitelist(self::resolveSdkWhitelist());
    }

    /**
     * @return list<string>
     */
    private static function resolveSdkWhitelist(): array
    {
        $patterns = [];
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $file = $loader->findFile(Render::class);
            if (false === $file) {
                continue;
            }
            $patterns[] = \dirname($file).'/*';
            $real = realpath($file);
            if (false !== $real) {
                $patterns[] = \dirname($real).'/*';
            }
            break;
        }

        return array_values(array_unique($patterns));
    }

    protected function setUp(): void
    {
        $this->outputPath = sys_get_temp_dir().'/poli-page-render-test-'.uniqid().'.pdf';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->outputPath)) {
            unlink($this->outputPath);
        }
    }

    public function testRendersPdfBytesFromProjectAndTemplate(): void
    {
        $render = $this->createMock(Render::class);
        $render->expects($this->once())
            ->method('pdf')
            ->willReturnCallback(static function (RenderInput $input): string {
                self::assertSame('invoices', $input->project);
                self::assertSame('default', $input->template);
                self::assertSame(['name' => 'Ada'], $input->data);

                return "%PDF-1.7\nstub\n%%EOF\n";
            });

        $client = $this->stubClient($render);

        $tester = new CommandTester(new RenderCommand($client, self::API_KEY));
        $exitCode = $tester->execute([
            '--project' => 'invoices',
            '--template' => 'default',
            '--data' => '{"name":"Ada"}',
            '--output' => $this->outputPath,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($this->outputPath);
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($this->outputPath));
        self::assertStringContainsString('Rendered', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{0: array<string, string>}>
     */
    public static function missingSlugProvider(): iterable
    {
        yield 'no project' => [['--template' => 'welcome']];
        yield 'no template' => [['--project' => 'getting-started']];
    }

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('missingSlugProvider')]
    public function testProjectAndTemplateAreRequired(array $options): void
    {
        $render = $this->createMock(Render::class);
        $render->expects($this->never())->method('pdf');

        $tester = new CommandTester(new RenderCommand($this->stubClient($render), self::API_KEY));
        $exitCode = $tester->execute($options + ['--output' => $this->outputPath]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('--project and --template are required', $tester->getDisplay());
        self::assertFileDoesNotExist($this->outputPath);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function removedOptionProvider(): iterable
    {
        yield 'template version' => ['template-version'];
        yield 'preview' => ['preview'];
        yield 'inline html' => ['html'];
    }

    #[DataProvider('removedOptionProvider')]
    public function testRemovedOptionsNoLongerExist(string $option): void
    {
        $command = new RenderCommand($this->stubClient($this->createMock(Render::class)), self::API_KEY);

        self::assertFalse($command->getDefinition()->hasOption($option));
    }

    public function testPoliPageExceptionExitsWithMappedCode(): void
    {
        $render = $this->createMock(Render::class);
        $render->method('pdf')->willThrowException(
            new PoliPageException('Template not found', 'TEMPLATE_NOT_FOUND', 404),
        );

        $client = $this->stubClient($render);
        $tester = new CommandTester(new RenderCommand($client, self::API_KEY));
        $exitCode = $tester->execute([
            '--project' => 'getting-started',
            '--template' => 'does-not-exist',
            '--output' => $this->outputPath,
        ]);

        self::assertSame(1, $exitCode); // 4xx -> 1
        self::assertStringContainsString('TEMPLATE_NOT_FOUND', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function headerBreakingSuffixProvider(): iterable
    {
        yield 'newline' => ["\n"];
        // Guzzle trims " \t" before it validates, so a leak would hold the trimmed key.
        yield 'newline then space' => ["\n "];
        yield 'newline then tab' => ["\n\t"];
    }

    /**
     * The `request_factory` option accepts a Guzzle PSR-17 factory, whose
     * header check throws with the whole "Bearer <key>" value in its message.
     * poli-page/sdk 0.11.0 refuses such a key with a fixed invalid_options
     * message before the factory sees it.
     */
    #[DataProvider('headerBreakingSuffixProvider')]
    public function testErrorOutputNeverContainsTheApiKeyWithGuzzlePsr7(string $suffix): void
    {
        $key = self::API_KEY.$suffix;
        $factory = new HttpFactory();
        $client = new PoliPage(
            apiKey: $key,
            baseUrl: 'http://127.0.0.1:9',
            maxRetries: 0,
            httpClient: new Psr18Client(),
            requestFactory: $factory,
            streamFactory: $factory,
        );

        $tester = new CommandTester(new RenderCommand($client, $key));
        $exitCode = $tester->execute([
            '--project' => 'getting-started',
            '--template' => 'welcome',
            '--output' => $this->outputPath,
        ]);
        $display = (string) preg_replace('/\s+/', '', $tester->getDisplay());

        self::assertSame(3, $exitCode);
        self::assertStringContainsString('apiKeycontainscharactersthatarenotallowedinanHTTPheader', $display);
        self::assertStringContainsString('code=invalid_options', $display);
        self::assertStringNotContainsString(substr(self::API_KEY, -32), $display);
        self::assertStringNotContainsString('pp_sandbox_', $display);
    }

    /**
     * The error Guzzle's PSR-7 raised for such a key with older poli-page/sdk releases.
     */
    #[DataProvider('headerBreakingSuffixProvider')]
    public function testOtherErrorOutputNeverContainsTheTrimmedApiKey(string $suffix): void
    {
        $key = self::API_KEY.$suffix;
        $render = $this->createMock(Render::class);
        $render->method('pdf')->willThrowException(new InvalidArgumentException(
            '"Bearer '.rtrim($key, " \t").'" is not valid header value',
        ));

        $tester = new CommandTester(new RenderCommand($this->stubClient($render), $key));
        $exitCode = $tester->execute([
            '--project' => 'getting-started',
            '--template' => 'welcome',
            '--output' => $this->outputPath,
        ]);
        $display = (string) preg_replace('/\s+/', '', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('[redactedAPIkey]', $display);
        self::assertStringNotContainsString(substr(self::API_KEY, -32), $display);
        self::assertStringNotContainsString('pp_sandbox_', $display);
    }

    public function testPoliPageExceptionOutputNeverContainsTheApiKey(): void
    {
        $render = $this->createMock(Render::class);
        $render->method('pdf')->willThrowException(new PoliPageException(
            'Invalid header: CR/LF/NUL found in "Authorization: Bearer '.self::API_KEY."\n\".",
            PoliPageException::NETWORK_ERROR,
        ));

        $tester = new CommandTester(new RenderCommand($this->stubClient($render), self::API_KEY."\n"));
        $exitCode = $tester->execute([
            '--project' => 'getting-started',
            '--template' => 'welcome',
            '--output' => $this->outputPath,
        ]);
        $display = (string) preg_replace('/\s+/', '', $tester->getDisplay());

        self::assertSame(3, $exitCode);
        self::assertStringContainsString('code=network_error', $display);
        self::assertStringNotContainsString(substr(self::API_KEY, -32), $display);
        self::assertStringNotContainsString('pp_sandbox_', $display);
    }

    private function stubClient(Render $render): PoliPage
    {
        $reflection = new ReflectionClass(PoliPage::class);
        /** @var PoliPage $client */
        $client = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('render')->setValue($client, $render);

        return $client;
    }
}
