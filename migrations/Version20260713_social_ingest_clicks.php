<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Крон UTM-клик-инжестера (Ф0 closed-loop соцсетей, docs/social_value_plan.md):
 * app:social:ingest-clicks тянет ssh+zgrep nginx-логи прода, атрибуирует клики к постам
 * и наполняет social_post_metric.link_taps.
 */
final class Version20260713_social_ingest_clicks extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'scheduled_command: крон app:social:ingest-clicks (ежедневно 07:30, Mac)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO scheduled_command (name, command, schedule, enabled)
            VALUES ('Соцсети: сбор кликов из логов', 'app:social:ingest-clicks --no-debug', '30 7 * * *', 1)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE name = 'Соцсети: сбор кликов из логов'");
    }
}
