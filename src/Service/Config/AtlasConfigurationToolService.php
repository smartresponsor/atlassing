<?php

declare(strict_types=1);

namespace App\Service\Config;

final class AtlasConfigurationToolService
{
    /**
     * Explicitly declares keys managed by the Atlas configuration surface.
     *
     * @return array<int, array<string, string>>
     */
    public function managedVariables(): array
    {
        return [
            ['key' => 'ATLAS_DOCUMENTATING_INDEX_PATH', 'target' => 'env', 'type' => 'path'],
            ['key' => 'ATLAS_IMPORT_PATH', 'target' => 'env', 'type' => 'path'],
            ['key' => 'ATLAS_SURFACE_OWNER_ROOT', 'target' => 'config', 'type' => 'string'],
        ];
    }
}
