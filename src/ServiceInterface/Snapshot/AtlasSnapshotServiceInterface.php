<?php

declare(strict_types=1);

namespace App\Atlassing\ServiceInterface\Snapshot;

interface AtlasSnapshotServiceInterface
{
    /**
     * @return array<string, mixed>
     */
    public function loadLatestAssessmentSummary(): array;

    /**
     * @return array<string, mixed>
     */
    public function loadCurrentComponentSnapshot(string $componentId): array;
}
