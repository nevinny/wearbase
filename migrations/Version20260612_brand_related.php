<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Жёсткий граф внутренней перелинковки брендов (docs/seo_adoption_plan.md, п.2).
 * Рёбра строятся офлайн (app:brand:build-link-graph) и не пересчитываются на запрос —
 * стабильный ссылочный граф для Google + гарантия in-degree >= 2 (нет сирот).
 */
final class Version20260612_brand_related extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create brand_related table — hard internal link graph between brands';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS brand_related (
                id INT AUTO_INCREMENT NOT NULL,
                brand_id INT NOT NULL,
                related_brand_id INT NOT NULL,
                position SMALLINT NOT NULL,
                source VARCHAR(20) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                UNIQUE INDEX uniq_brand_position (brand_id, position),
                UNIQUE INDEX uniq_brand_pair (brand_id, related_brand_id),
                INDEX idx_related_brand (related_brand_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_brand_related_brand FOREIGN KEY (brand_id)
                    REFERENCES brand (id) ON DELETE CASCADE,
                CONSTRAINT FK_brand_related_target FOREIGN KEY (related_brand_id)
                    REFERENCES brand (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS brand_related');
    }
}
