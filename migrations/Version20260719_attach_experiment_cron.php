<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Крон петли экспериментов над механиками (docs/mechanic_experiments.md), Mac:
 *  - propose:  понедельник 10:20 — предложить ОДНУ гипотезу по ICE в TG (человек-гейт).
 *  - evaluate: ежедневно 10:20 — замерить running-эксперименты с истёкшим окном (дёшево).
 * Идемпотентно: INSERT IGNORE (uniq по command).
 */
final class Version20260719_attach_experiment_cron extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'scheduled_command: крон app:experiment:propose (пн) + app:experiment:evaluate (ежедневно)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO scheduled_command (name, command, schedule, enabled, environment)
            VALUES ('Эксперименты механик: предложить', 'app:experiment:propose --no-debug', '20 10 * * 1', 1, 'dev')
        SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO scheduled_command (name, command, schedule, enabled, environment)
            VALUES ('Эксперименты механик: замер', 'app:experiment:evaluate --no-debug', '20 10 * * *', 1, 'dev')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE command IN ('app:experiment:propose --no-debug', 'app:experiment:evaluate --no-debug')");
    }
}
