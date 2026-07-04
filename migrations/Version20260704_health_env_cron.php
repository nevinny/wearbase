<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Крон health-check'а инвариантов среды (app:health:env): раз в час, Mac.
 * Ловит «тихие» сбои (потерян ADMIN_TELEGRAM_CHAT_ID, протух WORDSTAT_API_KEY,
 * упал ollama/Qdrant/SearXNG, устарели синки GSC/Яндекс) → TG-алерт.
 */
final class Version20260704_health_env_cron extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'scheduled_command: крон app:health:env (раз в час)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO scheduled_command (name, command, schedule, enabled)
            VALUES ('Health-check среды', 'app:health:env --no-debug', '5 * * * *', 1)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE name = 'Health-check среды'");
    }
}
