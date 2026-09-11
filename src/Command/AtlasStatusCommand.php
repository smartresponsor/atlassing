<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'atlas:status')]
final class AtlasStatusCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('component', null, InputOption::VALUE_REQUIRED, 'Optional component id to inspect.')
            ->addOption('atlas-root', null, InputOption::VALUE_REQUIRED, 'Atlas state root path.', 'var/atlas');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $atlasRoot = $this->absolutePath((string) $input->getOption('atlas-root'));
        $component = $this->nullableString($input->getOption('component'));

        $output->writeln('<info>Atlas status</info>');
        $output->writeln(sprintf('atlas_root: %s', $atlasRoot));

        if (!is_dir($atlasRoot)) {
            $output->writeln('<error>atlas_root_exists: no</error>');
            return Command::FAILURE;
        }

        $this->writeLatestSummary($output, $atlasRoot);
        $this->writeLatestRun($output, $atlasRoot);
        $this->writeComponentStatus($output, $atlasRoot, $component);

        return Command::SUCCESS;
    }

    private function writeLatestSummary(OutputInterface $output, string $atlasRoot): void
    {
        $summaryPath = $atlasRoot . '/generated/latest-assessment-summary.json';

        $output->writeln('');
        $output->writeln('<comment>latest_summary</comment>');
        $output->writeln(sprintf('path: %s', $summaryPath));

        if (!is_file($summaryPath)) {
            $output->writeln('exists: no');
            return;
        }

        $output->writeln('exists: yes');

        $payload = $this->readJsonFile($summaryPath);

        if ($payload === null) {
            $output->writeln('readable_json: no');
            return;
        }

        $output->writeln('readable_json: yes');
        $output->writeln(sprintf('status: %s', $this->scalarText($payload['status'] ?? null, 'unknown')));
        $output->writeln(sprintf('mode: %s', $this->scalarText($payload['mode'] ?? null, 'unknown')));
        $output->writeln(sprintf('components: %d', $this->arrayCount($payload['components'] ?? null)));
        $output->writeln(sprintf('failures: %d', $this->arrayCount($payload['failures'] ?? null)));

        $selected = $payload['selected_components'] ?? null;
        if (is_array($selected)) {
            $output->writeln(sprintf('selected_components: %s', implode(', ', array_map('strval', $selected))));
        }
    }

    private function writeLatestRun(OutputInterface $output, string $atlasRoot): void
    {
        $runRoot = $atlasRoot . '/generated/assessment-runs';

        $output->writeln('');
        $output->writeln('<comment>latest_run</comment>');
        $output->writeln(sprintf('run_root: %s', $runRoot));

        if (!is_dir($runRoot)) {
            $output->writeln('exists: no');
            return;
        }

        $runs = array_values(array_filter(glob($runRoot . '/*') ?: [], 'is_dir'));
        sort($runs);

        if ($runs === []) {
            $output->writeln('exists: yes');
            $output->writeln('latest: none');
            return;
        }

        $latest = (string) end($runs);

        $output->writeln('exists: yes');
        $output->writeln(sprintf('latest: %s', basename($latest)));
    }

    private function writeComponentStatus(OutputInterface $output, string $atlasRoot, ?string $requestedComponent): void
    {
        $componentRoot = $atlasRoot . '/component';

        $output->writeln('');
        $output->writeln('<comment>components</comment>');
        $output->writeln(sprintf('component_root: %s', $componentRoot));

        if (!is_dir($componentRoot)) {
            $output->writeln('exists: no');
            return;
        }

        $components = $requestedComponent === null
            ? array_map('basename', array_values(array_filter(glob($componentRoot . '/*') ?: [], 'is_dir')))
            : [$requestedComponent];

        sort($components);

        if ($components === []) {
            $output->writeln('exists: yes');
            $output->writeln('count: 0');
            return;
        }

        $output->writeln('exists: yes');
        $output->writeln(sprintf('count: %d', count($components)));

        foreach ($components as $component) {
            $this->writeSingleComponentStatus($output, $componentRoot, $component);
        }
    }

    private function writeSingleComponentStatus(OutputInterface $output, string $componentRoot, string $component): void
    {
        $currentPath = $componentRoot . '/' . $component . '/current.yaml';
        $probePath = $componentRoot . '/' . $component . '/probes/current.yaml';

        $output->writeln('');
        $output->writeln(sprintf('component: %s', $component));
        $output->writeln(sprintf('current_snapshot: %s', is_file($currentPath) ? 'yes' : 'no'));
        $output->writeln(sprintf('probe_snapshot: %s', is_file($probePath) ? 'yes' : 'no'));

        if (!is_file($currentPath)) {
            return;
        }

        $payload = Yaml::parseFile($currentPath);
        if (!is_array($payload)) {
            return;
        }

        $output->writeln(sprintf('title: %s', $this->scalarText($payload['title'] ?? null, 'unknown')));
        $output->writeln(sprintf('status: %s', $this->scalarText($payload['status'] ?? null, 'unknown')));
    }

    /** @return array<string, mixed>|null */
    private function readJsonFile(string $path): ?array
    {
        $payload = json_decode((string) file_get_contents($path), true);

        return is_array($payload) ? $payload : null;
    }

    private function absolutePath(string $path): string
    {
        if ($path === '') {
            return dirname(__DIR__, 2) . '/var/atlas';
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return str_replace('\\', '/', $path);
        }

        return dirname(__DIR__, 2) . '/' . $path;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function scalarText(mixed $value, string $fallback): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        return $fallback;
    }

    private function arrayCount(mixed $value): int
    {
        return is_array($value) ? count($value) : 0;
    }
}
