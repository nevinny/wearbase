<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * brand_rag_pipeline.priority — ручной приоритет очереди генерации.
 * Выборки этапов сортируются priority DESC (затем существующий порядок).
 * Поднять бренд в очередь: UPDATE brand_rag_pipeline SET priority=N WHERE brand_id=…
 */
final class Version20260614_rag_priority extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_rag_pipeline.priority — ручной приоритет очереди генерации';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_rag_pipeline ADD COLUMN priority INT NOT NULL DEFAULT 0');
        $this->addSql('CREATE INDEX idx_brp_priority ON brand_rag_pipeline (priority)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_brp_priority ON brand_rag_pipeline');
        $this->addSql('ALTER TABLE brand_rag_pipeline DROP COLUMN priority');
    }
}
