<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add locale field to Brand entity for multilingual support';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand ADD COLUMN locale VARCHAR(5) DEFAULT \'ru\' NOT NULL');
        $this->addSql('CREATE INDEX idx_brand_locale ON brand (locale)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand DROP COLUMN locale');
    }
}