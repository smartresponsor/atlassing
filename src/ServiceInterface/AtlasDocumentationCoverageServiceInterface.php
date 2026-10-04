<?php

declare(strict_types=1);

namespace App\Atlassing\ServiceInterface;

interface AtlasDocumentationCoverageServiceInterface
{
    /**
     * @return array<string, mixed>
     */
    public function summarizeFromDocumentatingIndex(string $indexPath): array;
}
