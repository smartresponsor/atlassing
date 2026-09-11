<?php

declare(strict_types=1);

namespace App\Tests\Entity\Atlas;

use App\Entity\Atlas\AtlasAssessmentEntity;
use App\Entity\Atlas\AtlasDocumentationCoverageEntity;
use PHPUnit\Framework\TestCase;

final class AtlasEntityTest extends TestCase
{
    public function testAssessmentExposesPersistedValues(): void
    {
        $assessment = new AtlasAssessmentEntity('assessment-1', 'atlassing', 92, 'ready');

        self::assertSame('assessment-1', $assessment->getId());
        self::assertSame('atlassing', $assessment->getComponentSlug());
        self::assertSame(92, $assessment->getScore());
        self::assertSame('ready', $assessment->getReadiness());
    }

    public function testDocumentationCoverageExposesPersistedValues(): void
    {
        $coverage = new AtlasDocumentationCoverageEntity('coverage-1', 'atlassing', 12, 2);

        self::assertSame('coverage-1', $coverage->getId());
        self::assertSame('atlassing', $coverage->getComponentSlug());
        self::assertSame(12, $coverage->getArticleCount());
        self::assertSame(2, $coverage->getMissingRequiredArticleCount());
    }

}
