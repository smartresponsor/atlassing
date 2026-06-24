<?php

declare(strict_types=1);

namespace App\Service\Assessment;

use App\ServiceInterface\Assessment\AtlasPythonEngineServiceInterface;
use App\ServiceInterface\Assessment\AtlasTargetSelectionServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AtlasTargetSelectionService implements AtlasTargetSelectionServiceInterface
{
    public function __construct(
        private readonly AtlasPythonEngineServiceInterface $pythonEngineService,
        #[Autowire('%atlassing.atlas_root%')]
        private readonly string $atlasRoot,
    ) {
    }

    /**
     * @return array{exitCode:int, successful:bool, output:string, errorOutput:string, command:list<string>, outputPath:string}
     */
    public function selectTargets(?string $component = null, string $eventName = 'workflow_dispatch', ?string $outputPath = null): array
    {
        $outputPath ??= $this->atlasRoot . '/generated/selection-plan.json';
        $arguments = ['--event-name', $eventName, '--output', $outputPath];

        if ($component !== null && $component !== '') {
            $arguments[] = '--component';
            $arguments[] = $component;
        }

        $result = $this->pythonEngineService->runScript('select_quality_atlas_targets.py', $arguments);
        $result['outputPath'] = $outputPath;

        return $result;
    }
}
