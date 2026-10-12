<?php

declare(strict_types=1);

namespace App\Atlassing\Tests\Service;

use App\Atlassing\Service\AtlasDocumentationCoverageService;
use PHPUnit\Framework\TestCase;

final class AtlasDocumentationCoverageEdgeTest extends TestCase
{
    public function testMalformedAndScalarJsonAreNotReportedAsAvailable(): void
    {
        $path = sys_get_temp_dir() . '/atlassing-doc-invalid-' . bin2hex(random_bytes(6)) . '.json';

        try {
            foreach (['{invalid', 'null', '42'] as $payload) {
                file_put_contents($path, $payload);
                $summary = (new AtlasDocumentationCoverageService())->summarizeFromDocumentatingIndex($path);

                self::assertFalse($summary['available']);
                self::assertSame(0, $summary['articleCount']);
                self::assertSame(0, $summary['componentCount']);
                self::assertSame($path, $summary['source']);
            }
        } finally {
            @unlink($path);
        }
    }

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

    public function testOnlyNonEmptyStringComponentIdentifiersContributeToCoverage(): void
    {
        $path = sys_get_temp_dir() . '/atlassing-doc-components-' . bin2hex(random_bytes(6)) . '.json';

        try {
            file_put_contents($path, json_encode(['articles' => [
                ['component' => 'atlassing'],
                ['component' => 'atlassing'],
                ['component' => 'viewing'],
                ['component' => ''],
                ['component' => '  '],
                ['component' => 42],
                ['component' => ['invalid']],
                ['component' => null],
                'invalid article',
            ]], JSON_THROW_ON_ERROR));

            $summary = (new AtlasDocumentationCoverageService())->summarizeFromDocumentatingIndex($path);
            self::assertTrue($summary['available']);
            self::assertSame(9, $summary['articleCount']);
            self::assertSame(2, $summary['componentCount']);
        } finally {
            @unlink($path);
        }
    }
}
