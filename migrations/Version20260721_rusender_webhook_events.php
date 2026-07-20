<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Дедуп событий вебхука RuSender (POST /rusender/webhook): доставка at-least-once,
 * события могут дублироваться при ретраях — храним eventId уже обработанных.
 */
final class Version20260721_rusender_webhook_events extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'rusender_webhook_event — дедуп событий вебхука RuSender по eventId';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS rusender_webhook_event (
                id INT AUTO_INCREMENT NOT NULL,
                event_id VARCHAR(64) NOT NULL,
                trigger_name VARCHAR(64) NOT NULL,
                processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_rusender_webhook_event_id (event_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS rusender_webhook_event');
    }
}
