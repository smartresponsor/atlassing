<?php

declare(strict_types=1);

namespace App\ServiceInterface\Registry;

interface AtlasRepositoryRegistryServiceInterface
{
    /**
     * @return array<string, mixed>
     */
    public function loadRegistry(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function listEnabledRepository(): array;
}
