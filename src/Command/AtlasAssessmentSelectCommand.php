<?php

declare(strict_types=1);

namespace App\Command;

use App\ServiceInterface\Assessment\AtlasTargetSelectionServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'atlas:assessment:select')]
final class AtlasAssessmentSelectCommand extends Command
{
    public function __construct(
        private readonly AtlasTargetSelectionServiceInterface $targetSelectionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('component', null, InputOption::VALUE_REQUIRED, 'Optional component_id to select.')
            ->addOption('event-name', null, InputOption::VALUE_REQUIRED, 'GitHub event name or local event name.', 'workflow_dispatch')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Selection plan output path.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->targetSelectionService->selectTargets(
            $this->nullableString($input->getOption('component')),
            (string) $input->getOption('event-name'),
            $this->nullableString($input->getOption('output')),
        );

        if ($result['output'] !== '') {
            $output->write($result['output']);
        }

        if ($result['errorOutput'] !== '') {
            $output->writeln('<error>' . trim($result['errorOutput']) . '</error>');
        }

        return $result['successful'] ? Command::SUCCESS : Command::FAILURE;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}

