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
        $path = sys_get_temp_dir().'/poli-page-demo-file.pdf';

        renderToFile($this->client, new ProjectModeInput(
            project: 'getting-started',
            template: 'welcome',
            data: ['name' => 'renderToFile demo'],
            version: '1.0.0',
        ), $path);

        $io->success(\sprintf('Wrote PDF to %s (%d bytes).', $path, filesize($path) ?: 0));

        return Command::SUCCESS;
    }
}
