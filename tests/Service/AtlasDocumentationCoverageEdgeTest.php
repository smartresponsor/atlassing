<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Atlas\AtlasDocumentationCoverageService;
use PHPUnit\Framework\TestCase;

final class AtlasDocumentationCoverageEdgeTest extends TestCase
{
    public function testInvalidArticlesShapeProducesEmptyCoverage(): void
    {
        $path = sys_get_temp_dir() . '/atlassing-doc-index-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, json_encode(['articles' => 'invalid'], JSON_THROW_ON_ERROR));

        $summary = (new AtlasDocumentationCoverageService())->summarizeFromDocumentatingIndex($path);

        self::assertTrue($summary['available']);
        self::assertSame(0, $summary['articleCount']);
        self::assertSame(0, $summary['componentCount']);
        self::assertSame($path, $summary['source']);
    }
}
