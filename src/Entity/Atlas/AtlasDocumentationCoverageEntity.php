<?php

declare(strict_types=1);

namespace App\Entity\Atlas;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'atlas_documentation_coverage')]
class AtlasDocumentationCoverageEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 160)]
    private string $id;

    #[ORM\Column(type: 'string', length: 120)]
    private string $componentSlug;

    #[ORM\Column(type: 'integer')]
    private int $articleCount;

    #[ORM\Column(type: 'integer')]
    private int $missingRequiredArticleCount;

    public function __construct(string $id, string $componentSlug, int $articleCount, int $missingRequiredArticleCount)
    {
        $this->id = $id;
        $this->componentSlug = $componentSlug;
        $this->articleCount = $articleCount;
        $this->missingRequiredArticleCount = $missingRequiredArticleCount;
    }
}

