<?php

declare(strict_types=1);

namespace App\Service\Atlas;

final class AtlassingAtlasComponentSlugService
{
    public function __construct(
        private readonly AtlasSurfacePayloadService $surfacePayloadService,
    ) {
    }

    /**
     * Entry service resolved from `/atlassing/atlas/component/{slug}` by CRUDing.
     *
     * @return array<string, mixed>
     */
    public function __invoke(string $slug): array
    {
        return $this->surfacePayloadService->buildPayload('atlas/component/detail', [
            'slug' => $slug,
        ]);
    }
}
