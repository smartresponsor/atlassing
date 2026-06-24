<?php

declare(strict_types=1);

namespace App\ServiceInterface\Snapshot;

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
