<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Console;

use InvalidArgumentException;
use PoliPage\InlineModeInput;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\ProjectModeInput;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

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
            ->addOption('template-version', null, InputOption::VALUE_REQUIRED, 'Template version (required unless --html)')
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
            if (true === $input->getOption('preview')) {
                return $this->doPreview($input, $io, $data);
            }

            return $this->doPdf($input, $io, $data);
        } catch (PoliPageException $e) {
            $io->error(\sprintf(
                '%s (status=%s, code=%s, requestId=%s)',
                $e->getMessage(),
                $e->status ?? 'n/a',
                $e->errorCode,
                $e->requestId ?? 'n/a',
            ));

            return $this->exitCodeFor($e);
        } catch (Throwable $e) {
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
        $version = $input->getOption('template-version');

        if ('' === $project || '' === $template || null === $version) {
            $io->error('--project, --template and --template-version are required for PDF rendering.');

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

        $io->success(\sprintf(
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
            $version = $input->getOption('template-version');
            if ('' === $project || '' === $template || null === $version) {
                $io->error('Either --html, or all of --project --template --template-version, are required for preview.');

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
            $outputPath = substr($outputPath, 0, -4).'.html';
        }
        $this->writeFile($outputPath, $result->html);

        $io->success(\sprintf(
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
            $contents = '-' === $dataFile ? stream_get_contents(\STDIN) : $this->readFile((string) $dataFile);
        } else {
            $contents = (string) $input->getOption('data');
        }
        if ('' === $contents || false === $contents) {
            return [];
        }
        $decoded = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new InvalidArgumentException('--data / --data-file must decode to a JSON object.');
        }

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function readFile(string $path): string
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw new RuntimeException(\sprintf('Could not read file: %s', $path));
        }

        return $contents;
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException(\sprintf('Could not create output directory: %s', $dir));
        }
        if (false === @file_put_contents($path, $contents)) {
            throw new RuntimeException(\sprintf('Could not write to: %s', $path));
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
