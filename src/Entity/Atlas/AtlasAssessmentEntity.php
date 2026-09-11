<?php

declare(strict_types=1);

namespace App\Entity\Atlas;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'atlas_assessment')]
class AtlasAssessmentEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 120)]
    private string $id;

    #[ORM\Column(type: 'string', length: 120)]
    private string $componentSlug;

    #[ORM\Column(type: 'integer')]
    private int $score;

    #[ORM\Column(type: 'string', length: 80)]
    private string $readiness;

    public function __construct(string $id, string $componentSlug, int $score, string $readiness)
    {
        $this->id = $id;
        $this->componentSlug = $componentSlug;
        $this->score = $score;
        $this->readiness = $readiness;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getComponentSlug(): string
    {
        return $this->componentSlug;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function getReadiness(): string
    {
        return $this->readiness;
    }
}

