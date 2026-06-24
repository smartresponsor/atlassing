<?php

declare(strict_types=1);

namespace App\Service\Atlas;

final class AtlassingAtlasReadinessService
{
    public function __construct(
        private readonly AtlasSurfacePayloadService $surfacePayloadService,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return $this->surfacePayloadService->buildPayload('atlas/readiness');
    }
}
