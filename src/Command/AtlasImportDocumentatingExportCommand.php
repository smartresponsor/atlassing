<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'atlas:import:documentating-export')]
final class AtlasImportDocumentatingExportCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to the unpacked Documentating Atlas export.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getArgument('path');

        if (!is_dir($path)) {
            $output->writeln(sprintf('<error>Import path was not found: %s</error>', $path));
            return Command::FAILURE;
        }

        $output->writeln(sprintf('Atlas import source detected: %s', $path));
        $output->writeln('This skeleton command validates the source path only. Transformation is planned for the next wave.');

        return Command::SUCCESS;
    }
}

