<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Cron\CronExpression;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Крон app:brand:payment-reminders (прод, ежедневно в 10:30 МСК).
 *
 * next_run_at задаём явно (не NULL): строка с next_run_at = NULL, чья минута попала
 * под занятый диспетчер (app:cron:run-scheduled держит глобальный лок на время
 * выполнения), не запустится НИКОГДА — только точное совпадение cron-выражения
 * запускает такую строку, догон по next_run_at не работает без стартового значения.
 */
final class Version20260831_payment_reminders_cron extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'scheduled_command: app:brand:payment-reminders, ежедневно в 10:30 МСК';
    }

    public function up(Schema $schema): void
    {
        $nextRunAt = (new CronExpression('30 10 * * *'))
            ->getNextRunDate(new \DateTime('now', new \DateTimeZone('Europe/Moscow')))
            ->format('Y-m-d H:i:s');

        $this->addSql(
            "INSERT IGNORE INTO scheduled_command (environment, name, command, schedule, enabled, next_run_at) VALUES ('prod', 'Бренды: напоминания о реквизитах', 'app:brand:payment-reminders --no-debug', '30 10 * * *', 1, ?)",
            [$nextRunAt],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE command = 'app:brand:payment-reminders --no-debug'");
    }
}
