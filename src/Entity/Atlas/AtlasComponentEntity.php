<?php

declare(strict_types=1);

namespace App\Entity\Atlas;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'atlas_component')]
class AtlasComponentEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 120)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 180)]
    private string $title;

    #[ORM\Column(type: 'string', length: 80)]
    private string $status = 'draft';

    public function __construct(string $slug, string $title)
    {
        $this->slug = $slug;
        $this->title = $title;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getStatus(): string
    {
        return $this->status;
    }
}

