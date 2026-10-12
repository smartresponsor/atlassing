<?php

declare(strict_types=1);

namespace App\Atlassing\Service;

use App\Atlassing\ServiceInterface\AtlasDocumentationCoverageServiceInterface;

final class AtlasDocumentationCoverageService implements AtlasDocumentationCoverageServiceInterface
{
    /**
     * Reads a Documentating article index and returns a coverage summary.
     *
     * @return array<string, mixed>
     */
    public function summarizeFromDocumentatingIndex(string $indexPath): array
    {
        if (!is_file($indexPath)) {
            return [
                'available' => false,
                'articleCount' => 0,
                'componentCount' => 0,
                'source' => $indexPath,
            ];
        }

        $contents = file_get_contents($indexPath);
        if ($contents === false) {
            return [
                'available' => false,
                'articleCount' => 0,
                'componentCount' => 0,
                'source' => $indexPath,
            ];
        }

        $decoded = json_decode($contents, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [
                'available' => false,
                'articleCount' => 0,
                'componentCount' => 0,
                'source' => $indexPath,
            ];
        }
        $articles = is_array($decoded['articles'] ?? null) ? $decoded['articles'] : [];
        $components = [];

        foreach ($articles as $article) {
            if (is_array($article) && isset($article['component'])) {
                $components[(string) $article['component']] = true;
            }
        }

        return [
            'available' => true,
            'articleCount' => count($articles),
            'componentCount' => count($components),
            'source' => $indexPath,
        ];
    }
}
