<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * brand_source_url.priority — денормализация ручного приоритета бренда на его URL-очередь.
 *
 * Этапы конвейера embed/extract/generate/… уже сортируются по brand_rag_pipeline.priority
 * (PipelineQueueRepository::finishStageQuery). Единственный этап, минующий pipeline, — fetch:
 * он клеймит URL через BrandSourceUrlRepository::claimPending (сырой SQL по brand_source_url),
 * поэтому priority нужно «перекинуть» сюда, чтобы высокоприоритетный бренд проходил fetch первым.
 *
 * priority проставляется при enqueue (discover, из priority бренда) и пропагируется
 * (BrandSourceUrlRepository::propagatePriority) при изменении приоритета уже отдискаверенного бренда.
 * Бэкфилл ниже синхронизирует существующие URL с текущим pipeline.priority.
 */
final class Version20260623_brand_source_url_priority extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_source_url.priority — приоритет fetch-очереди (перекидывание из brand_rag_pipeline)';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('brand_source_url');

        if (!$table->hasColumn('priority')) {
            $this->addSql('ALTER TABLE brand_source_url ADD priority INT DEFAULT 0 NOT NULL');
        }
        if (!$table->hasIndex('idx_bsu_status_priority')) {
            $this->addSql('CREATE INDEX idx_bsu_status_priority ON brand_source_url (status, priority, tier)');
        }

        // Бэкфилл: подтянуть приоритет уже лежащих URL из pipeline бренда (идемпотентно).
        $this->addSql(<<<'SQL'
            UPDATE brand_source_url u
              JOIN brand_rag_pipeline p ON p.brand_id = u.brand_id
               SET u.priority = p.priority
             WHERE p.priority <> 0 AND u.priority <> p.priority
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_bsu_status_priority ON brand_source_url');
        $this->addSql('ALTER TABLE brand_source_url DROP priority');
    }
}
