<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * brand_rag_pipeline.regen_requested_at — флаг «перегенерировать» из loss-ветки closed-loop.
 * Ставит app:seo:evaluate-experiments; потребляет generate-content --regen-flagged (форс-реген).
 */
final class Version20260614_regen_requested extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_rag_pipeline.regen_requested_at — флаг форс-регенерации (closed-loop loss)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_rag_pipeline ADD COLUMN regen_requested_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_brp_regen ON brand_rag_pipeline (regen_requested_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_brp_regen ON brand_rag_pipeline');
        $this->addSql('ALTER TABLE brand_rag_pipeline DROP COLUMN regen_requested_at');
    }
}
