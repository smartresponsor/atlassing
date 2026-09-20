<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Registry\AtlasRepositoryRegistryService;
use App\Service\Snapshot\AtlasSnapshotService;
use PHPUnit\Framework\TestCase;

final class AtlasStateEdgeCaseTest extends TestCase
{
    public function testRegistryHandlesNonListShapes(): void
    {
        $root = self::tempDirectory('registry-edge');
        mkdir($root . '/repository', 0777, true);
        file_put_contents($root . '/repository/ecosystem-repositories.yaml', "repositories: invalid\n");

        $registry = new AtlasRepositoryRegistryService($root);
        self::assertSame([], $registry->listEnabledRepository());

        file_put_contents(
            $root . '/repository/ecosystem-repositories.yaml',
            "repositories:\n  - string-entry\n  - component_id: enabled\n",
        );

        self::assertSame(
            ['enabled'],
            array_column($registry->listEnabledRepository(), 'component_id'),
        );

        file_put_contents($root . '/repository/ecosystem-repositories.yaml', "repositories:\n  - [invalid\n");
        $invalid = $registry->loadRegistry();
        self::assertTrue($invalid['invalid']);
        self::assertSame([], $invalid['repositories']);
    }

    public function testSnapshotReportsMissingAndInvalidPayloads(): void
    {
        $root = self::tempDirectory('snapshot-edge');
        $snapshot = new AtlasSnapshotService($root);

        self::assertSame('missing', $snapshot->loadLatestAssessmentSummary()['status']);

        mkdir($root . '/generated', 0777, true);
        file_put_contents($root . '/generated/latest-assessment-summary.json', '{invalid');
        self::assertSame('invalid', $snapshot->loadLatestAssessmentSummary()['status']);

        mkdir($root . '/component/atlassing', 0777, true);
        file_put_contents($root . '/component/atlassing/current.yaml', "scalar\n");
        self::assertSame('invalid', $snapshot->loadCurrentComponentSnapshot('atlassing')['status']);

        file_put_contents($root . '/component/atlassing/current.yaml', "component: [invalid\n");
        self::assertSame('invalid', $snapshot->loadCurrentComponentSnapshot('atlassing')['status']);

        self::assertSame(
            'invalid-component',
            $snapshot->loadCurrentComponentSnapshot('../generated')['status'],
        );
        self::assertSame(
            'invalid-component',
            $snapshot->loadCurrentComponentSnapshot('atlassing/../../generated')['status'],
        );
    }

    private static function tempDirectory(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/atlassing-' . $suffix . '-' . bin2hex(random_bytes(6));
        mkdir($path, 0777, true);

        return str_replace('\\', '/', $path);
    }
}
