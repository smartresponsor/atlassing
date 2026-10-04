<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004091400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the canonical Atlassing assessment, component, and documentation coverage tables.';
    }

    public function up(Schema $schema): void
    {
        $assessment = $schema->createTable('atlas_assessment');
        $assessment->addColumn('id', Types::STRING, ['length' => 120]);
        $assessment->addColumn('component_slug', Types::STRING, ['length' => 120]);
        $assessment->addColumn('score', Types::INTEGER);
        $assessment->addColumn('readiness', Types::STRING, ['length' => 80]);
        $assessment->setPrimaryKey(['id']);

        $component = $schema->createTable('atlas_component');
        $component->addColumn('slug', Types::STRING, ['length' => 120]);
        $component->addColumn('title', Types::STRING, ['length' => 180]);
        $component->addColumn('status', Types::STRING, ['length' => 80]);
        $component->setPrimaryKey(['slug']);

        $documentationCoverage = $schema->createTable('atlas_documentation_coverage');
        $documentationCoverage->addColumn('id', Types::STRING, ['length' => 160]);
        $documentationCoverage->addColumn('component_slug', Types::STRING, ['length' => 120]);
        $documentationCoverage->addColumn('article_count', Types::INTEGER);
        $documentationCoverage->addColumn('missing_required_article_count', Types::INTEGER);
        $documentationCoverage->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('atlas_documentation_coverage');
        $schema->dropTable('atlas_component');
        $schema->dropTable('atlas_assessment');
    }
}
