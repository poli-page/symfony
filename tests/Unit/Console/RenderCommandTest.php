<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit\Console;

use DG\BypassFinals;
use PHPUnit\Framework\TestCase;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\PreviewResult;
use PoliPage\Render;
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
        // so createMock(Render::class) works. Whitelisted to SDK files only
        // — stripping final from PHPUnit's own classes breaks readonly
        // class inheritance constraints.
        BypassFinals::enable();
        BypassFinals::setWhitelist(['*/sdk-php.md/src/*']);
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

    public function testProjectModeWritesPdfBytes(): void
    {
        $render = $this->createMock(Render::class);
        $render->expects($this->once())
            ->method('pdf')
            ->willReturnCallback(static function ($input): string {
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
            '--template-version' => '1.0.0',
            '--data' => '{"name":"Ada"}',
            '--output' => $this->outputPath,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($this->outputPath);
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($this->outputPath));
        self::assertStringContainsString('Rendered', $tester->getDisplay());
    }

    public function testInlineHtmlModeForPreview(): void
    {
        $render = $this->createMock(Render::class);
        $render->expects($this->once())
            ->method('preview')
            ->willReturnCallback(static function ($input): PreviewResult {
                self::assertSame('<h1>Hi</h1>', $input->template);

                return new PreviewResult('<html><h1>Hi</h1></html>', 1, 'sandbox');
            });

        $client = $this->stubClient($render);

        $outputHtml = (string) preg_replace('/\.pdf$/', '.html', $this->outputPath);
        $tester = new CommandTester(new RenderCommand($client));
        $exitCode = $tester->execute([
            '--html' => $this->writeTempHtml('<h1>Hi</h1>'),
            '--template' => 'inline',
            '--preview' => true,
            '--output' => $outputHtml,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($outputHtml);
        self::assertStringContainsString('<h1>Hi</h1>', (string) file_get_contents($outputHtml));
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
            '--project' => 'p',
            '--template' => 't',
            '--template-version' => 'bad',
            '--data' => '{}',
            '--output' => $this->outputPath,
        ]);

        self::assertSame(1, $exitCode); // 4xx -> 1
        self::assertStringContainsString('INVALID_VERSION_FORMAT', $tester->getDisplay());
    }

    private function stubClient(Render $render): PoliPage
    {
        $reflection = new ReflectionClass(PoliPage::class);
        /** @var PoliPage $client */
        $client = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('render')->setValue($client, $render);

        return $client;
    }

    private function writeTempHtml(string $contents): string
    {
        $path = sys_get_temp_dir().'/poli-page-render-test-'.uniqid().'.html';
        file_put_contents($path, $contents);

        return $path;
    }
}
