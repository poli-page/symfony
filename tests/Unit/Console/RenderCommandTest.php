<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit\Console;

use Composer\Autoload\ClassLoader;
use DG\BypassFinals;
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

final class RenderCommandTest extends TestCase
{
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

        $tester = new CommandTester(new RenderCommand($client));
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

        $tester = new CommandTester(new RenderCommand($this->stubClient($render)));
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
        $command = new RenderCommand($this->stubClient($this->createMock(Render::class)));

        self::assertFalse($command->getDefinition()->hasOption($option));
    }

    public function testPoliPageExceptionExitsWithMappedCode(): void
    {
        $render = $this->createMock(Render::class);
        $render->method('pdf')->willThrowException(
            new PoliPageException('Template not found', 'TEMPLATE_NOT_FOUND', 404),
        );

        $client = $this->stubClient($render);
        $tester = new CommandTester(new RenderCommand($client));
        $exitCode = $tester->execute([
            '--project' => 'getting-started',
            '--template' => 'does-not-exist',
            '--output' => $this->outputPath,
        ]);

        self::assertSame(1, $exitCode); // 4xx -> 1
        self::assertStringContainsString('TEMPLATE_NOT_FOUND', $tester->getDisplay());
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
