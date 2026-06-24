<?php

declare(strict_types=1);

namespace App\Service\Assessment;

use App\ServiceInterface\Assessment\AtlasAssessmentServiceInterface;
use App\ServiceInterface\Assessment\AtlasPythonEngineServiceInterface;

final class AtlasAssessmentService implements AtlasAssessmentServiceInterface
{
    public function __construct(
        private readonly AtlasPythonEngineServiceInterface $pythonEngineService,
    ) {
    }

    /**
     * @param list<string> $components
     *
     * @return array{exitCode:int, successful:bool, output:string, errorOutput:string, command:list<string>, selectionPlan:string|null}
     */
    public function runAssessment(string $mode = 'dry-run', array $components = [], ?string $selectionPlan = null): array
    {
        $arguments = ['--mode', $mode];

        if ($selectionPlan !== null && $selectionPlan !== '') {
            $arguments[] = '--selection-plan';
            $arguments[] = $selectionPlan;
        }

        foreach ($components as $component) {
            if ($component === '') {
                continue;
            }

            $arguments[] = '--component';
            $arguments[] = $component;
        }

        $result = $this->pythonEngineService->runScript('run_quality_atlas_assessment.py', $arguments);
        $result['selectionPlan'] = $selectionPlan;

        return $result;
    }
}
