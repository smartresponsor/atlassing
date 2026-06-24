<?php

declare(strict_types=1);

namespace App\ServiceInterface\Assessment;

interface AtlasPythonEngineServiceInterface
{
    /**
     * Runs an imported Atlas Python engine script and returns the process result.
     *
     * @param list<string> $arguments
     * @param array<string, string> $environment
     *
     * @return array{exitCode:int, successful:bool, output:string, errorOutput:string, command:list<string>}
     */
    public function runScript(string $scriptName, array $arguments = [], array $environment = []): array;
}
