<?php

declare(strict_types=1);

namespace App\Atlassing\Service\Atlas;

use App\Atlassing\ServiceInterface\Atlas\AtlasSurfacePayloadServiceInterface;
use App\Atlassing\ServiceInterface\Snapshot\AtlasSnapshotServiceInterface;

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
