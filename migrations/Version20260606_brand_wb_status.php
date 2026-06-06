<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260606_brand_wb_status extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_rag_pipeline: wb_status + wb_checked_at (стадия ингеста Wildberries)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE brand_rag_pipeline
                ADD wb_status     VARCHAR(12) DEFAULT NULL COMMENT 'NULL=не обрабатывали|done|no_products|error',
                ADD wb_checked_at DATETIME DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_rag_pipeline DROP wb_status, DROP wb_checked_at');
    }
}
