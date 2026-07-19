<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Крон SALES-LOOP: еженедельный warm-refresh (тёплые лиды из поисковых кликов →
 * драфты писем + сводка в TG). Понедельник 08:30 — до дневного дайджеста 09:17
 * (app:report:daily), чтобы владелец увидел сводку раньше.
 */
final class Version20260719_warm_refresh_cron extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'scheduled_command: крон app:outreach:warm-refresh (пн 08:30, Mac)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO scheduled_command (environment, name, command, schedule, enabled)
            VALUES ('dev', 'Sales-loop: тёплые лиды (warm-refresh)', 'app:outreach:warm-refresh --no-debug', '30 8 * * 1', 1)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE name = 'Sales-loop: тёплые лиды (warm-refresh)'");
    }
}
