<?php

declare(strict_types=1);

namespace App\Atlassing\Tests\Command;

use App\Atlassing\Command\AtlasImportDocumentatingExportCommand;
use App\Atlassing\Command\AtlasStatusCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AtlasOperationalCommandTest extends TestCase
{
    public function testStatusFailsWhenAtlasRootDoesNotExist(): void
    {
        $missing = sys_get_temp_dir() . '/atlassing-status-missing-' . bin2hex(random_bytes(6));
        $tester = new CommandTester(new AtlasStatusCommand());

        $exit = $tester->execute(['--atlas-root' => $missing]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('atlas_root_exists: no', $tester->getDisplay());
    }

    public function testStatusReportsAbsentGeneratedAndComponentState(): void
    {
        $root = self::tempDirectory('empty-status');
        $tester = new CommandTester(new AtlasStatusCommand());

        $exit = $tester->execute(['--atlas-root' => $root]);

        $display = $tester->getDisplay();
        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('latest_summary', $display);
        self::assertStringContainsString('exists: no', $display);
        self::assertStringContainsString('latest_run', $display);
        self::assertStringContainsString('components', $display);
    }

    public function testStatusReportsSummaryRunAndComponentSnapshots(): void
    {
        $root = self::tempDirectory('status');
        mkdir($root . '/generated/assessment-runs/20260916-120000', 0777, true);
        mkdir($root . '/generated/assessment-runs/20260916-130000', 0777, true);
        mkdir($root . '/component/atlassing/probes', 0777, true);

        file_put_contents(
            $root . '/generated/latest-assessment-summary.json',
            json_encode([
                'status' => 'ready',
                'mode' => 'chatgpt-cli',
                'components' => [['component' => 'atlassing']],
                'failures' => [],
                'selected_components' => ['atlassing'],
            ], JSON_THROW_ON_ERROR),
        );
        file_put_contents($root . '/component/atlassing/current.yaml', "title: Atlassing\nstatus: ready\n");
        file_put_contents($root . '/component/atlassing/probes/current.yaml', "status: ready\n");

        $tester = new CommandTester(new AtlasStatusCommand());
        $exit = $tester->execute([
            '--atlas-root' => $root,
            '--component' => 'atlassing',
        ]);

        $display = $tester->getDisplay();
        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('status: ready', $display);
        self::assertStringContainsString('mode: chatgpt-cli', $display);
        self::assertStringContainsString('latest: 20260916-130000', $display);
        self::assertStringContainsString('current_snapshot: yes', $display);
        self::assertStringContainsString('probe_snapshot: yes', $display);
        self::assertStringContainsString('title: Atlassing', $display);
    }

    public function testStatusReportsMalformedSelectedComponentsWithoutWarning(): void
    {
        $root = self::tempDirectory('malformed-selected-components');
        mkdir($root . '/generated', 0777, true);
        file_put_contents(
            $root . '/generated/latest-assessment-summary.json',
            json_encode([
                'status' => 'ready',
                'selected_components' => ['atlassing', ['nested']],
            ], JSON_THROW_ON_ERROR),
        );

        $tester = new CommandTester(new AtlasStatusCommand());

        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $exit = $tester->execute(['--atlas-root' => $root]);
        } finally {
            restore_error_handler();
        }

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('selected_components: invalid', $tester->getDisplay());
    }

    public function testStatusHandlesInvalidAndSparseState(): void
    {
        $root = self::tempDirectory('sparse');
        mkdir($root . '/generated/assessment-runs', 0777, true);
        mkdir($root . '/component/invalid', 0777, true);
        file_put_contents($root . '/generated/latest-assessment-summary.json', '{invalid');
        file_put_contents($root . '/component/invalid/current.yaml', "- list\n- only\n");

        $tester = new CommandTester(new AtlasStatusCommand());
        $exit = $tester->execute(['--atlas-root' => $root]);

        $display = $tester->getDisplay();
        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('readable_json: no', $display);
        self::assertStringContainsString('latest: none', $display);
        self::assertStringContainsString('component: invalid', $display);
    }

    public function testStatusReportsRequestedComponentWithoutSnapshot(): void
    {
        $root = self::tempDirectory('missing-component-snapshot');
        mkdir($root . '/component', 0777, true);

        $tester = new CommandTester(new AtlasStatusCommand());
        $exit = $tester->execute([
            '--atlas-root' => $root,
            '--component' => 'atlassing',
        ]);

        $display = $tester->getDisplay();
        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('component_id_valid: yes', $display);
        self::assertStringContainsString('current_snapshot: no', $display);
        self::assertStringContainsString('probe_snapshot: no', $display);
    }

    public function testStatusRejectsPathLikeComponentIdentifier(): void
    {
        $root = self::tempDirectory('invalid-component-id');
        mkdir($root . '/component/escape', 0777, true);
        file_put_contents($root . '/component/escape/current.yaml', "title: Escaped\nstatus: ready\n");

        $tester = new CommandTester(new AtlasStatusCommand());
        $exit = $tester->execute([
            '--atlas-root' => $root,
            '--component' => '../escape',
        ]);

        $display = $tester->getDisplay();
        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('component: ../escape', $display);
        self::assertStringContainsString('component_id_valid: no', $display);
        self::assertStringNotContainsString('title: Escaped', $display);
    }

    public function testStatusReportsMalformedComponentSnapshotWithoutCrashing(): void
    {
        $root = self::tempDirectory('malformed-component');
        mkdir($root . '/component/atlassing', 0777, true);
        file_put_contents($root . '/component/atlassing/current.yaml', "title: [unterminated\n");

        $tester = new CommandTester(new AtlasStatusCommand());
        $exit = $tester->execute([
            '--atlas-root' => $root,
            '--component' => 'atlassing',
        ]);

        $display = $tester->getDisplay();
        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('component_id_valid: yes', $display);
        self::assertStringContainsString('current_snapshot: yes', $display);
        self::assertStringContainsString('current_snapshot_valid: no', $display);
    }

    public function testImportCommandValidatesSourceDirectory(): void
    {
        $missing = new CommandTester(new AtlasImportDocumentatingExportCommand());
        self::assertSame(Command::FAILURE, $missing->execute(['path' => __DIR__ . '/missing-export']));
        self::assertStringContainsString('Import path was not found', $missing->getDisplay());

        $path = self::tempDirectory('import');
        $valid = new CommandTester(new AtlasImportDocumentatingExportCommand());
        self::assertSame(Command::SUCCESS, $valid->execute(['path' => $path]));
        self::assertStringContainsString('Atlas import source detected', $valid->getDisplay());
    }

    private static function tempDirectory(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/atlassing-' . $suffix . '-' . bin2hex(random_bytes(6));
        mkdir($path, 0777, true);

        return str_replace('\\', '/', $path);
    }
}
