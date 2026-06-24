<?php

declare(strict_types=1);

namespace App\ServiceInterface\Assessment;

interface AtlasTargetSelectionServiceInterface
{
    /**
     * Selects Atlas assessment targets through the current Python engine backend.
     *
     * @return array{exitCode:int, successful:bool, output:string, errorOutput:string, command:list<string>, outputPath:string}
     */
    public function selectTargets(?string $component = null, string $eventName = 'workflow_dispatch', ?string $outputPath = null): array;
}
