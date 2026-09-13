<?php

declare(strict_types=1);

namespace App\Command;

use App\ServiceInterface\Assessment\AtlasAssessmentServiceInterface;
use App\ServiceInterface\Assessment\AtlasTargetSelectionServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'atlas:assessment:cycle')]
final class AtlasAssessmentCycleCommand extends Command
{
    public function __construct(
        private readonly AtlasTargetSelectionServiceInterface $targetSelectionService,
        private readonly AtlasAssessmentServiceInterface $assessmentService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('component', null, InputOption::VALUE_REQUIRED, 'Optional component_id to select and assess.')
            ->addOption('event-name', null, InputOption::VALUE_REQUIRED, 'GitHub event name or local event name.', 'workflow_dispatch')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Assessment mode: dry-run, chatgpt-cli, or responses.', 'dry-run')
            ->addOption('selection-plan', null, InputOption::VALUE_REQUIRED, 'Selection plan path.', 'var/atlas/generated/selection-plan.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $component = $this->nullableString($input->getOption('component'));
        $eventName = (string) $input->getOption('event-name');
        $mode = (string) $input->getOption('mode');
        $selectionPlan = (string) $input->getOption('selection-plan');

        $output->writeln('<info>Atlas assessment cycle started.</info>');

        $selection = $this->targetSelectionService->selectTargets(
            $component,
            $eventName,
            $selectionPlan,
        );

        if ($selection['output'] !== '') {
            $output->write($selection['output']);
        }

        if ($selection['errorOutput'] !== '') {
            $output->writeln('<error>' . trim($selection['errorOutput']) . '</error>');
        }

        if (!$selection['successful']) {
            $output->writeln('<error>Atlas assessment cycle stopped during target selection.</error>');
            return Command::FAILURE;
        }

        $components = $component === null ? [] : [$component];

        $assessment = $this->assessmentService->runAssessment(
            $mode,
            $components,
            $selection['outputPath'],
        );

        if ($assessment['output'] !== '') {
            $output->write($assessment['output']);
        }

        if ($assessment['errorOutput'] !== '') {
            $output->writeln('<error>' . trim($assessment['errorOutput']) . '</error>');
        }

        if (!$assessment['successful']) {
            $output->writeln('<error>Atlas assessment cycle stopped during assessment execution.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Atlas assessment cycle completed.</info>');

        return Command::SUCCESS;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
