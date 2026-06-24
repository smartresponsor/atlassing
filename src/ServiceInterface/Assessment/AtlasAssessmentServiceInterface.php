<?php

declare(strict_types=1);

namespace App\ServiceInterface\Assessment;

interface AtlasAssessmentServiceInterface
{
    /**
     * Runs the Atlas assessment through the current Python engine backend.
     *
     * @param list<string> $components
     *
     * @return array{exitCode:int, successful:bool, output:string, errorOutput:string, command:list<string>, selectionPlan:string|null}
     */
    public function runAssessment(string $mode = 'dry-run', array $components = [], ?string $selectionPlan = null): array;
}
