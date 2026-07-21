<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * seo_gap_snapshot — недельный снимок position-gap листа (app:seo:gap-report),
 * одна строка на (captured_on, source, intent_group). Единственная цель — тренд
 * неделя-к-неделе («гео-дыр: 4, было 6»), сам gap-лист каждый раз считается заново
 * из yandex_query_stats/gsc_query_stats (docs/yandex_ai_visibility_monitoring.md).
 */
final class Version20260722_seo_gap_snapshot extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'seo_gap_snapshot — недельный снимок gap-листа по (source,intent_group) для тренда';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS seo_gap_snapshot (
                id INT AUTO_INCREMENT NOT NULL,
                captured_on DATE NOT NULL,
                source VARCHAR(20) NOT NULL,
                intent_group VARCHAR(30) NOT NULL,
                gap_count INT NOT NULL DEFAULT 0,
                top_query VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_seo_gap_snapshot (captured_on, source, intent_group)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS seo_gap_snapshot');
    }
}
