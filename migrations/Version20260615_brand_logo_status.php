<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * brand_rag_pipeline.logo_status / logo_checked_at — стадия app:brand:logo
 * (поиск и извлечение логотипа бренда из HTML own_site/маркетплейс-страниц).
 */
final class Version20260615_brand_logo_status extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_rag_pipeline.logo_status / logo_checked_at — стадия поиска логотипа';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_rag_pipeline ADD COLUMN logo_status VARCHAR(12) DEFAULT NULL, ADD COLUMN logo_checked_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_brp_logo ON brand_rag_pipeline (logo_status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_brp_logo ON brand_rag_pipeline');
        $this->addSql('ALTER TABLE brand_rag_pipeline DROP COLUMN logo_status, DROP COLUMN logo_checked_at');
    }
}
