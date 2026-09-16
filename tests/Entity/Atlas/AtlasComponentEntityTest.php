<?php

declare(strict_types=1);

namespace App\Tests\Entity\Atlas;

use App\Entity\Atlas\AtlasComponentEntity;
use PHPUnit\Framework\TestCase;

final class AtlasComponentEntityTest extends TestCase
{
    public function testComponentExposesCanonicalIdentityAndDefaultStatus(): void
    {
        $component = new AtlasComponentEntity('atlassing', 'Atlassing');

        self::assertSame('atlassing', $component->getSlug());
        self::assertSame('Atlassing', $component->getTitle());
        self::assertSame('draft', $component->getStatus());
    }
}
