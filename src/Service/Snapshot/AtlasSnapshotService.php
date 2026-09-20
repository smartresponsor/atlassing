<?php

declare(strict_types=1);

namespace App\Service\Snapshot;

use App\ServiceInterface\Snapshot\AtlasSnapshotServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class AtlasSnapshotService implements AtlasSnapshotServiceInterface
{
    public function __construct(
        #[Autowire('%atlassing.atlas_root%')]
        private readonly string $atlasRoot,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function loadLatestAssessmentSummary(): array
    {
        $path = $this->atlasRoot . '/generated/latest-assessment-summary.json';

        if (!is_file($path)) {
            return [
                'status' => 'missing',
                'path' => $path,
                'components' => [],
                'failures' => [],
            ];
        }

        $payload = json_decode((string) file_get_contents($path), true);

        return is_array($payload) ? $payload : [
            'status' => 'invalid',
            'path' => $path,
            'components' => [],
            'failures' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function loadCurrentComponentSnapshot(string $componentId): array
    {
        if (preg_match('/\\A[a-z0-9]+(?:[a-z0-9_-]*[a-z0-9])?\\z/', $componentId) !== 1) {
            return [
                'status' => 'invalid-component',
                'component' => $componentId,
            ];
        }

        $path = $this->atlasRoot . '/component/' . $componentId . '/current.yaml';

        if (!is_file($path)) {
            return [
                'status' => 'missing',
                'component' => $componentId,
                'path' => $path,
            ];
        }

        try {
            $payload = Yaml::parseFile($path);
        } catch (ParseException) {
            return [
                'status' => 'invalid',
                'component' => $componentId,
                'path' => $path,
            ];
        }

        return is_array($payload) ? $payload : [
            'status' => 'invalid',
            'component' => $componentId,
            'path' => $path,
        ];
    }
}
