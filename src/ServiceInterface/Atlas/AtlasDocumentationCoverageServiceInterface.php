<?php

declare(strict_types=1);

namespace App\ServiceInterface\Atlas;

interface AtlasDocumentationCoverageServiceInterface
{
    /**
     * @return array<string, mixed>
     */
    public function summarizeFromDocumentatingIndex(string $indexPath): array;
}
