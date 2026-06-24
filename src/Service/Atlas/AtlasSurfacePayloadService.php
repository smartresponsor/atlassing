<?php

declare(strict_types=1);

namespace App\Service\Atlas;

use App\ServiceInterface\Snapshot\AtlasSnapshotServiceInterface;
use App\ServiceInterface\Surface\AtlasSurfacePayloadServiceInterface;

final class AtlasSurfacePayloadService implements AtlasSurfacePayloadServiceInterface
{
    public function __construct(
        private readonly AtlasSnapshotServiceInterface $snapshotService,
    ) {
    }

    /**
     * Builds the canonical Atlas surface payload consumed by Viewing.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function buildPayload(string $surface, array $context = []): array
    {
        return [
            'component' => 'atlassing',
            'package' => 'atlassing/atlas',
            'surface' => $surface,
            'title' => 'Atlas',
            'locations' => [
                'top' => [],
                'body' => [],
                'right' => [],
                'bottom' => [],
            ],
            'data' => array_replace_recursive([
                'latestAssessmentSummary' => $this->snapshotService->loadLatestAssessmentSummary(),
            ], $context),
        ];
    }
}
