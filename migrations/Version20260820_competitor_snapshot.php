<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260820_competitor_snapshot extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ежедневные срезы публичных метрик конкурентов (app:competitor:watch)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS competitor_snapshot (id INT AUTO_INCREMENT NOT NULL, competitor VARCHAR(40) NOT NULL, captured_on DATE NOT NULL, captured_at DATETIME NOT NULL, companies_total INT DEFAULT NULL, products_total INT DEFAULT NULL, sitemap_urls INT DEFAULT NULL, fresh_24h INT DEFAULT NULL, news_latest_on DATE DEFAULT NULL, news_latest_title VARCHAR(255) DEFAULT NULL, errors VARCHAR(500) DEFAULT NULL, UNIQUE INDEX uq_competitor_snapshot_day (competitor, captured_on), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql("INSERT IGNORE INTO scheduled_command (environment, name, command, schedule, enabled, timeout_sec) VALUES ('dev', 'Слежка за конкурентами (ProVybor)', 'app:competitor:watch --notify --no-debug', '25 9 * * *', 1, 300)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE command = 'app:competitor:watch --notify --no-debug'");
        $this->addSql('DROP TABLE IF EXISTS competitor_snapshot');
    }
}
