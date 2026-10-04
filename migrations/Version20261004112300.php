<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004112300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the canonical Atlassing root identity table.';
    }

    public function up(Schema $schema): void
    {
        $atlas = $schema->createTable('atlas');
        $atlas->addColumn('id', Types::STRING, ['length' => 120]);
        $atlas->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('atlas');
    }
}
