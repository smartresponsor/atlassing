<?php

declare(strict_types=1);

namespace App\Atlassing\Tests\Service;

use App\Atlassing\Service\Snapshot\AtlasSnapshotService;
use PHPUnit\Framework\TestCase;

final class AtlasSnapshotShapeTest extends TestCase
{
    public function testArrayRootIsNotAcceptedAsLatestSummary(): void
    {
        $root = sys_get_temp_dir() . '/atlas-shape-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root . '/generated', 0777, true));
        file_put_contents($root . '/generated/latest-assessment-summary.json', '[{"status":"green"}]');

        $service = new AtlasSnapshotService($root);
        self::assertSame('invalid', $service->loadLatestAssessmentSummary()['status']);
    }

    public function testArrayRootIsNotAcceptedAsCurrentSnapshot(): void
    {
        $root = sys_get_temp_dir() . '/atlas-shape-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root . '/component/atlas', 0777, true));
        file_put_contents($root . '/component/atlas/current.yaml', "- status: green\n");

        $service = new AtlasSnapshotService($root);
        self::assertSame('invalid', $service->loadCurrentComponentSnapshot('atlas')['status']);
    }
}
