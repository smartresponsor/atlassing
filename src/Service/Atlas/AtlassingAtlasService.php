<?php

declare(strict_types=1);

namespace App\Service\Atlas;

final class AtlassingAtlasService
{
    public function __construct(
        private readonly AtlasSurfacePayloadService $surfacePayloadService,
    ) {
    }

    /**
     * Entry service resolved from `/atlassing/atlas` by CRUDing.
     *
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return $this->surfacePayloadService->buildPayload('atlas/index', [
            'mode' => 'atlas-dashboard',
        ]);
    }
}
