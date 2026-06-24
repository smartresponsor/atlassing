<?php

declare(strict_types=1);

namespace App\ServiceInterface\Surface;

interface AtlasSurfacePayloadServiceInterface
{
    /**
     * Builds the canonical Atlas surface payload consumed by Viewing.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function buildPayload(string $surface, array $context = []): array;
}
