<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Крон автопилота position-gap листа: app:seo:gap-report --notify (пн 08:00, Mac,
 * до app:report:weekly 08:00→10:00 и app:report:daily 09:17 — та же логика
 * упорядочивания, что у Version20260719_warm_refresh_cron). Изолированная
 * команда со своим --notify (паттерн app:seo:aio-remediate) — не трогаем
 * существующие дайджест-команды.
 */
final class Version20260722_seo_gap_report_cron extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'scheduled_command: крон app:seo:gap-report --notify (пн 08:00, Mac)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO scheduled_command (environment, name, command, schedule, enabled)
            VALUES ('dev', 'SEO: gap-лист (position>10) — автопилот', 'app:seo:gap-report --notify --no-debug', '0 8 * * 1', 1)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE name = 'SEO: gap-лист (position>10) — автопилот'");
    }
}
