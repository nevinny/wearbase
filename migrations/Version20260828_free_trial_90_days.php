<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Free-триал 30 → 90 дней + продление уже выданных.
 *
 * Первые самрег-бренды сожгли месячный триал целиком в очереди премодерации
 * (никто не разбирал очередь, владельцу ничего не сообщали) — то есть заплатили
 * временем за нашу поломку. 3 месяца — реалистичный срок, чтобы бренд успел
 * заполнить карточку, пройти модерацию и увидеть первый трафик.
 */
final class Version20260828_free_trial_90_days extends AbstractMigration
{
    public function getDescription(): string { return 'Free trial 30 → 90 дней + продление активных триалов'; }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE tariff SET trial_days = 90 WHERE code = 'free' AND trial_days < 90");

        // Уже выданные триалы пересчитываем от даты выдачи; условие делает миграцию
        // идемпотентной и не трогает тех, кому уже дали 90+ дней вручную.
        $this->addSql(
            "UPDATE subscription SET trial_ends_at = DATE_ADD(created_at, INTERVAL 90 DAY), "
            . "current_period_end = DATE_ADD(created_at, INTERVAL 90 DAY) "
            . "WHERE status = 'trial' AND trial_ends_at < DATE_ADD(created_at, INTERVAL 90 DAY)"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE tariff SET trial_days = 30 WHERE code = 'free'");
    }
}
