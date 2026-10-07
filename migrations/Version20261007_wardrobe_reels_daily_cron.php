<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Скользящее окно гардеробных рилсов (до 7 дней вперёд, слот 21:00 МСК). environment='dev' = Mac:
 * рендер и медиа живут только там, прод эту строку не исполняет (CRON_ENV=prod).
 */
final class Version20261007_wardrobe_reels_daily_cron extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'scheduled_command: app:social:wardrobe-reels-daily (Mac, 10:30 ежедневно)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT IGNORE INTO scheduled_command (environment, name, command, schedule, enabled, timeout_sec) SELECT 'dev', 'Соцсети: гардеробные рилсы на неделю вперёд', 'app:social:wardrobe-reels-daily --no-debug', '30 10 * * *', 1, 3600 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM scheduled_command WHERE command = 'app:social:wardrobe-reels-daily --no-debug')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE command = 'app:social:wardrobe-reels-daily --no-debug'");
    }
}
