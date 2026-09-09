<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Соцсети: ежедневный сбор Instagram-insights (closed-loop для A/B E1×E4 и evaluate).
 *
 * До сих пор collect-metrics снимался вручную — по факту запущен один раз 31.07: 13 снапшотов
 * на 13 постов, дальше 3 недели тишины, эксперимент без измерений. Два прогона в день
 * (08:40 до пн-evaluate 09:00 и вечером 20:40 для накопления watch time) на host=mac,
 * как publish-tick. Пара к нему ingest-clicks уже сидит в кроне (id=26).
 */
final class Version20260822_social_metrics_cron extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'scheduled_command: app:social:collect-metrics 2×/день (host=mac)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO scheduled_command (environment, name, command, schedule, enabled) VALUES
              ('dev', 'Соцсети: сбор метрик IG', 'app:social:collect-metrics --host=mac --limit=100 --no-debug', '40 8,20 * * *', 1)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE command LIKE 'app:social:collect-metrics%'");
    }
}
