<?php

declare(strict_types=1);

namespace App\Atlassing\Entity\Atlas;

use Doctrine\ORM\Mapping as ORM;

/**
 * Canonical persistence identity and relationship-composition anchor for Atlassing.
 */
#[ORM\Entity]
#[ORM\Table(name: 'atlas')]
final class AtlasEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 120)]
    private string $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }

    public function getId(): string
    {
        return $this->id;
    }
}
