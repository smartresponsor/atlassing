<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AtlasImportDocumentatingExportCommand;
use App\Command\AtlasStatusCommand;
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
