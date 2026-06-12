<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260609_brand_source_document_soft_delete extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deleted_at to brand_source_document (soft-delete нерелевантных источников из админ-панели)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_source_document ADD deleted_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_source_document DROP deleted_at');
    }
}
