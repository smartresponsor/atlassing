<?php

declare(strict_types=1);

namespace App\Service\Assessment;

use App\ServiceInterface\Assessment\AtlasPythonEngineServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

final class AtlasPythonEngineService implements AtlasPythonEngineServiceInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%atlassing.python_engine_dir%')]
        private readonly string $pythonEngineDir,
    ) {
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     *
     * @return array{exitCode:int, successful:bool, output:string, errorOutput:string, command:list<string>}
     */
    public function runScript(string $scriptName, array $arguments = [], array $environment = []): array
    {
        $scriptPath = $this->pythonEngineDir . '/' . $scriptName;

        if (!is_file($scriptPath)) {
            return [
                'exitCode' => 127,
                'successful' => false,
                'output' => '',
                'errorOutput' => sprintf('Atlas Python engine script was not found: %s', $scriptPath),
                'command' => ['python3', $scriptPath, ...$arguments],
            ];
        }

        $command = ['python3', $scriptPath, ...$arguments];
        $process = new Process($command, $this->projectDir, $environment, null, 3600);
        $process->run();

        return [
            'exitCode' => $process->getExitCode() ?? 1,
            'successful' => $process->isSuccessful(),
            'output' => $process->getOutput(),
            'errorOutput' => $process->getErrorOutput(),
            'command' => $command,
        ];
    }
}
