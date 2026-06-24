<?php

declare(strict_types=1);

namespace App\Command;

use App\ServiceInterface\Assessment\AtlasAssessmentServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'atlas:assessment:run')]
final class AtlasAssessmentRunCommand extends Command
{
    public function __construct(
        private readonly AtlasAssessmentServiceInterface $assessmentService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Assessment mode: dry-run or responses.', 'dry-run')
            ->addOption('component', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Component id to assess. Can be passed multiple times.')
            ->addOption('selection-plan', null, InputOption::VALUE_REQUIRED, 'Path to a selection-plan JSON file.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->assessmentService->runAssessment(
            (string) $input->getOption('mode'),
            array_values(array_filter((array) $input->getOption('component'), static fn (mixed $value): bool => is_string($value) && $value !== '')),
            $this->nullableString($input->getOption('selection-plan')),
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

