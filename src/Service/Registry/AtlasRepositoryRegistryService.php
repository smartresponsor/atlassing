<?php

declare(strict_types=1);

namespace App\Service\Registry;

use App\ServiceInterface\Registry\AtlasRepositoryRegistryServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class AtlasRepositoryRegistryService implements AtlasRepositoryRegistryServiceInterface
{
    public function __construct(
        #[Autowire('%atlassing.atlas_root%')]
        private readonly string $atlasRoot,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function loadRegistry(): array
    {
        $path = $this->atlasRoot . '/repository/ecosystem-repositories.yaml';

        if (!is_file($path)) {
            return [
                'repositories' => [],
                'missing' => true,
                'path' => $path,
            ];
        }

        try {
            $payload = Yaml::parseFile($path);
        } catch (ParseException) {
            return [
                'repositories' => [],
                'invalid' => true,
                'path' => $path,
            ];
        }

        return is_array($payload) ? $payload : ['repositories' => []];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEnabledRepository(): array
    {
        $registry = $this->loadRegistry();
        $repositories = $registry['repositories'] ?? [];

        if (!is_array($repositories)) {
            return [];
        }

        $enabled = [];
        foreach ($repositories as $repository) {
            if (!is_array($repository)) {
                continue;
            }

            if (($repository['enabled'] ?? true) === false) {
                continue;
            }

            $enabled[] = $repository;
        }

        return $enabled;
    }
}
