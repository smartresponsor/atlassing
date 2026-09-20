<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AtlasAssessmentCycleCommand;
use App\Command\AtlasAssessmentRunCommand;
use App\Command\AtlasAssessmentSelectCommand;
use App\ServiceInterface\Assessment\AtlasAssessmentServiceInterface;
use App\ServiceInterface\Assessment\AtlasTargetSelectionServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AtlasAssessmentCommandTest extends TestCase
{
    public function testRunCommandPassesOptionsAndReturnsSuccess(): void
    {
        $service = $this->createMock(AtlasAssessmentServiceInterface::class);
        $service->expects(self::once())
            ->method('runAssessment')
            ->with('chatgpt-cli', ['atlassing'], 'plan.json')
            ->willReturn(self::assessmentResult(true, 'done'));

        $tester = new CommandTester(new AtlasAssessmentRunCommand($service));
        $exit = $tester->execute([
            '--mode' => 'chatgpt-cli',
            '--component' => ['atlassing'],
            '--selection-plan' => 'plan.json',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('done', $tester->getDisplay());
    }

    public function testRunCommandSurfacesAssessmentFailure(): void
    {
        $service = $this->createStub(AtlasAssessmentServiceInterface::class);
        $service->method('runAssessment')->willReturn(self::assessmentResult(false, '', 'scoring failed'));

        $tester = new CommandTester(new AtlasAssessmentRunCommand($service));
        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('scoring failed', $tester->getDisplay());
    }

    public function testSelectCommandPassesOptionsAndSurfacesOutput(): void
    {
        $service = $this->createMock(AtlasTargetSelectionServiceInterface::class);
        $service->expects(self::once())
            ->method('selectTargets')
            ->with('atlassing', 'schedule', 'selection.json')
            ->willReturn(self::selectionResult(true, 'selected', '', 'selection.json'));

        $tester = new CommandTester(new AtlasAssessmentSelectCommand($service));
        $exit = $tester->execute([
            '--component' => 'atlassing',
            '--event-name' => 'schedule',
            '--output' => 'selection.json',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('selected', $tester->getDisplay());
    }

    public function testSelectCommandNormalizesEmptyOptionsAndSurfacesFailure(): void
    {
        $service = $this->createMock(AtlasTargetSelectionServiceInterface::class);
        $service->expects(self::once())
            ->method('selectTargets')
            ->with(null, 'workflow_dispatch', null)
            ->willReturn(self::selectionResult(false, '', 'selection failed', 'selection.json'));

        $tester = new CommandTester(new AtlasAssessmentSelectCommand($service));
        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('selection failed', $tester->getDisplay());
    }

    public function testCycleStopsOnSelectionFailure(): void
    {
        $selection = $this->createStub(AtlasTargetSelectionServiceInterface::class);
        $selection->method('selectTargets')->willReturn(self::selectionResult(false, '', 'selection failed', 'plan.json'));

        $assessment = $this->createMock(AtlasAssessmentServiceInterface::class);
        $assessment->expects(self::never())->method('runAssessment');

        $tester = new CommandTester(new AtlasAssessmentCycleCommand($selection, $assessment));
        $exit = $tester->execute(['--component' => 'atlassing']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('stopped during target selection', $tester->getDisplay());
    }

    public function testCycleExecutesAssessmentAndReturnsItsFailure(): void
    {
        $selection = $this->createMock(AtlasTargetSelectionServiceInterface::class);
        $selection->expects(self::once())
            ->method('selectTargets')
            ->with('atlassing', 'schedule', 'plan.json')
            ->willReturn(self::selectionResult(true, 'selected', '', 'plan.json'));

        $assessment = $this->createMock(AtlasAssessmentServiceInterface::class);
        $assessment->expects(self::once())
            ->method('runAssessment')
            ->with('responses', ['atlassing'], 'plan.json')
            ->willReturn(self::assessmentResult(false, '', 'assessment failed', 'plan.json'));

        $tester = new CommandTester(new AtlasAssessmentCycleCommand($selection, $assessment));
        $exit = $tester->execute([
            '--component' => 'atlassing',
            '--event-name' => 'schedule',
            '--mode' => 'responses',
            '--selection-plan' => 'plan.json',
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('stopped during assessment execution', $tester->getDisplay());
    }

    public function testCycleCompletesSuccessfulLifecycle(): void
    {
        $selection = $this->createStub(AtlasTargetSelectionServiceInterface::class);
        $selection->method('selectTargets')->willReturn(self::selectionResult(true, '', '', 'plan.json'));

        $assessment = $this->createStub(AtlasAssessmentServiceInterface::class);
        $assessment->method('runAssessment')->willReturn(self::assessmentResult(true, 'assessed', '', 'plan.json'));

        $tester = new CommandTester(new AtlasAssessmentCycleCommand($selection, $assessment));
        $exit = $tester->execute(['--selection-plan' => 'plan.json']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('cycle completed', $tester->getDisplay());
    }

    /** @return array{exitCode:int, successful:bool, output:string, errorOutput:string, command:list<string>, selectionPlan:string|null} */
    private static function assessmentResult(bool $successful, string $output = '', string $error = '', ?string $plan = null): array
    {
        return [
            'exitCode' => $successful ? 0 : 1,
            'successful' => $successful,
            'output' => $output,
            'errorOutput' => $error,
            'command' => ['py', '-3', 'run_quality_atlas_assessment.py'],
            'selectionPlan' => $plan,
        ];
    }

    /** @return array{exitCode:int, successful:bool, output:string, errorOutput:string, command:list<string>, outputPath:string} */
    private static function selectionResult(bool $successful, string $output, string $error, string $path): array
    {
        return [
            'exitCode' => $successful ? 0 : 1,
            'successful' => $successful,
            'output' => $output,
            'errorOutput' => $error,
            'command' => ['py', '-3', 'select_quality_atlas_targets.py'],
            'outputPath' => $path,
        ];
    }
}
