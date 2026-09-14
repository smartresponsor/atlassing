<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Assessment\AtlasAssessmentService;
use App\Service\Assessment\AtlasPythonEngineService;
use App\Service\Assessment\AtlasTargetSelectionService;
use App\Service\Atlas\AtlasDocumentationCoverageService;
use App\Service\Atlas\AtlassingAtlasComponentService;
use App\Service\Atlas\AtlassingAtlasComponentSlugService;
use App\Service\Atlas\AtlassingAtlasMaturityService;
use App\Service\Atlas\AtlassingAtlasReadinessService;
use App\Service\Atlas\AtlassingAtlasService;
use App\Service\Atlas\AtlasSurfacePayloadService;
use App\Service\Config\AtlasConfigurationToolService;
use App\Service\Registry\AtlasRepositoryRegistryService;
use App\Service\Snapshot\AtlasSnapshotService;
use App\ServiceInterface\Assessment\AtlasPythonEngineServiceInterface;
use App\ServiceInterface\Snapshot\AtlasSnapshotServiceInterface;
use PHPUnit\Framework\TestCase;

final class AtlasRuntimeServiceTest extends TestCase
{
    public function testAssessmentAndSelectionBuildCanonicalArguments(): void
    {
        $engine = $this->createMock(AtlasPythonEngineServiceInterface::class);
        $engine->expects(self::exactly(2))
            ->method('runScript')
            ->willReturnCallback(static function (string $script, array $arguments): array {
                if ($script === 'run_quality_atlas_assessment.py') {
                    self::assertSame([
                        '--mode', 'chatgpt-cli',
                        '--selection-plan', 'plan.json',
                        '--component', 'atlassing',
                    ], $arguments);
                } else {
                    self::assertSame('select_quality_atlas_targets.py', $script);
                    self::assertSame([
                        '--event-name', 'schedule',
                        '--output', 'C:/atlas/generated/selection-plan.json',
                        '--component', 'atlassing',
                    ], $arguments);
                }

                return self::engineResult();
            });

        $assessment = (new AtlasAssessmentService($engine))->runAssessment('chatgpt-cli', ['atlassing', ''], 'plan.json');
        self::assertSame('plan.json', $assessment['selectionPlan']);

        $selection = (new AtlasTargetSelectionService($engine, 'C:/atlas'))->selectTargets('atlassing', 'schedule');
        self::assertSame('C:/atlas/generated/selection-plan.json', $selection['outputPath']);
    }

    public function testPythonEngineReportsMissingScript(): void
    {
        $result = (new AtlasPythonEngineService(__DIR__, __DIR__ . '/missing-engine'))->runScript('missing.py', ['--probe']);

        self::assertFalse($result['successful']);
        self::assertSame(127, $result['exitCode']);
        self::assertStringContainsString('missing.py', $result['errorOutput']);
    }

    public function testDocumentationCoverageHandlesMissingAndValidIndexes(): void
    {
        $service = new AtlasDocumentationCoverageService();
        self::assertFalse($service->summarizeFromDocumentatingIndex(__DIR__ . '/missing.json')['available']);

        $path = self::tempDirectory('docs') . '/index.json';
        file_put_contents($path, json_encode([
            'articles' => [
                ['component' => 'atlassing'],
                ['component' => 'atlassing'],
                ['component' => 'cruding'],
                ['title' => 'platform'],
            ],
        ], JSON_THROW_ON_ERROR));

        $summary = $service->summarizeFromDocumentatingIndex($path);
        self::assertSame(4, $summary['articleCount']);
        self::assertSame(2, $summary['componentCount']);
    }

    public function testRegistryAndSnapshotStateContracts(): void
    {
        $root = self::tempDirectory('state');
        mkdir($root . '/repository', 0777, true);
        mkdir($root . '/generated', 0777, true);
        mkdir($root . '/component/atlassing', 0777, true);
        file_put_contents($root . '/repository/ecosystem-repositories.yaml', "repositories:\n  - component_id: atlassing\n    enabled: true\n  - component_id: disabled\n    enabled: false\n  - component_id: implicit\n");
        file_put_contents($root . '/generated/latest-assessment-summary.json', '{"status":"ok","components":[],"failures":[]}');
        file_put_contents($root . '/component/atlassing/current.yaml', "component: atlassing\nreport_status: current\n");

        $registry = new AtlasRepositoryRegistryService($root);
        self::assertCount(3, $registry->loadRegistry()['repositories']);
        self::assertSame(['atlassing', 'implicit'], array_column($registry->listEnabledRepository(), 'component_id'));

        $snapshot = new AtlasSnapshotService($root);
        self::assertSame('ok', $snapshot->loadLatestAssessmentSummary()['status']);
        self::assertSame('atlassing', $snapshot->loadCurrentComponentSnapshot('atlassing')['component']);
        self::assertSame('missing', $snapshot->loadCurrentComponentSnapshot('missing')['status']);

        $missing = new AtlasRepositoryRegistryService(self::tempDirectory('missing-registry'));
        self::assertTrue($missing->loadRegistry()['missing']);
    }

    public function testSurfaceEntryServicesAndConfiguration(): void
    {
        $snapshot = $this->createStub(AtlasSnapshotServiceInterface::class);
        $snapshot->method('loadLatestAssessmentSummary')->willReturn(['status' => 'ok']);
        $surface = new AtlasSurfacePayloadService($snapshot);

        self::assertSame('atlas-dashboard', (new AtlassingAtlasService($surface))()['data']['mode']);
        self::assertSame('component-list', (new AtlassingAtlasComponentService($surface))()['data']['mode']);
        self::assertSame('cruding', (new AtlassingAtlasComponentSlugService($surface))('cruding')['data']['slug']);
        self::assertSame('atlas/maturity', (new AtlassingAtlasMaturityService($surface))()['surface']);
        self::assertSame('atlas/readiness', (new AtlassingAtlasReadinessService($surface))()['surface']);

        $custom = $surface->buildPayload('atlas/custom', ['latestAssessmentSummary' => ['status' => 'override']]);
        self::assertSame('override', $custom['data']['latestAssessmentSummary']['status']);

        self::assertSame(
            ['ATLAS_DOCUMENTATING_INDEX_PATH', 'ATLAS_IMPORT_PATH', 'ATLAS_SURFACE_OWNER_ROOT'],
            array_column((new AtlasConfigurationToolService())->managedVariables(), 'key'),
        );
    }

    /** @return array{exitCode:int, successful:bool, output:string, errorOutput:string, command:list<string>} */
    private static function engineResult(): array
    {
        return [
            'exitCode' => 0,
            'successful' => true,
            'output' => '{}',
            'errorOutput' => '',
            'command' => ['py', '-3', 'engine.py'],
        ];
    }

    private static function tempDirectory(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/atlassing-' . $suffix . '-' . bin2hex(random_bytes(6));
        mkdir($path, 0777, true);

        return str_replace('\\', '/', $path);
    }
}
