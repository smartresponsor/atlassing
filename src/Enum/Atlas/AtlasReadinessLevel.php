<?php

declare(strict_types=1);

namespace App\Enum\Atlas;

enum AtlasReadinessLevel: string
{
    case Missing = 'missing';
    case Weak = 'weak';
    case Partial = 'partial';
    case Solid = 'solid';
    case Strong = 'strong';
}
