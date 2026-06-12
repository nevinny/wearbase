<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Лог пингов Google Indexing API (docs/seo_adoption_plan.md, п.3) —
 * единственный Google-канал индексации (anti-trifecta: IndexNow закрывает Яндекс/Bing).
 * brand_id намеренно БЕЗ FK: бренд может быть удалён, история пингов — лог.
 * UNIQUE по url + pinged_at дают re-ping cooldown 14 дней и дневную квоту ≤200.
 */
final class Version20260612_google_index_ping extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create google_index_ping table — log of Google Indexing API pings (daily quota + 14d cooldown)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS google_index_ping (
                id INT AUTO_INCREMENT NOT NULL,
                url VARCHAR(512) NOT NULL,
                brand_id INT DEFAULT NULL,
                pinged_at DATETIME NOT NULL,
                response_code SMALLINT DEFAULT NULL,
                UNIQUE INDEX uniq_google_ping_url (url),
                INDEX idx_google_ping_pinged_at (pinged_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS google_index_ping');
    }
}
