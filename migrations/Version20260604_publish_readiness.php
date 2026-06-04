<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Готовность к публикации + дрип-публикация (дизайн «агент-сервер», tasktracker 2026-06-04):
 *  - brand_rag_pipeline.faq_status:     NULL=не генерили | done | skipped (нет ключевиков) | failed
 *  - brand_rag_pipeline.pushed_at:      когда бренд доставлен на прод агентом-пушем
 *  - brand_rag_pipeline.push_attempts/push_error: ретраи доставки
 *  - brand.publish_pending:  в очереди на дрип-публикацию (прод)
 *  - brand.published_at:     когда опубликован дрип-кроном (история для ramp-up)
 */
final class Version20260604_publish_readiness extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pipeline: faq_status/pushed_at/push_attempts/push_error; brand: publish_pending/published_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE brand_rag_pipeline
                ADD faq_status    VARCHAR(12) DEFAULT NULL COMMENT 'NULL=не генерили|done|skipped|failed',
                ADD pushed_at     DATETIME DEFAULT NULL,
                ADD push_attempts INT NOT NULL DEFAULT 0,
                ADD push_error    LONGTEXT DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE brand
                ADD publish_pending TINYINT(1) NOT NULL DEFAULT 0,
                ADD published_at    DATETIME DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_rag_pipeline DROP faq_status, DROP pushed_at, DROP push_attempts, DROP push_error');
        $this->addSql('ALTER TABLE brand DROP publish_pending, DROP published_at');
    }
}
