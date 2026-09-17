<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ночной батч образов «на утро» не был зарегистрирован в планировщике: docs/commands.md
 * обещал `0 5 * * *` на Mac, но строки в scheduled_command не появилось ни миграцией,
 * ни в crontab — команда ни разу не запускалась автоматически.
 *
 * environment='dev' — это Mac (CRON_ENV по умолчанию): прод не достаёт до домашнего рига,
 * инициировать может только Mac. Таймаут щедрый: 4 повода × N гардеробов × большая
 * локальная модель на PCIe gen1 x1.
 */
final class Version20260917_wardrobe_daily_outfits_cron extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'scheduled_command: app:wardrobe:daily-outfits (Mac, 0 5 * * *)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT IGNORE INTO scheduled_command (environment, name, command, schedule, enabled, timeout_sec) VALUES ('dev', 'Гардероб: образы на утро', 'app:wardrobe:daily-outfits --no-debug', '0 5 * * *', 1, 7200)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE command = 'app:wardrobe:daily-outfits --no-debug'");
    }
}
