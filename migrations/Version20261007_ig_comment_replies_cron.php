<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Опрос комментариев IG каждые 5 минут. environment='dev' = Mac: Meta доступна только оттуда (VPN),
 * прод эту строку не исполняет (CRON_ENV=prod).
 */
final class Version20261007_ig_comment_replies_cron extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'scheduled_command: app:social:ig-comment-replies (Mac, каждые 5 минут)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT IGNORE INTO scheduled_command (environment, name, command, schedule, enabled, timeout_sec) SELECT 'dev', 'Соцсети: ответы в директ на кодовое слово в комментариях IG', 'app:social:ig-comment-replies --no-debug', '*/5 * * * *', 1, 280 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM scheduled_command WHERE command = 'app:social:ig-comment-replies --no-debug')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE command = 'app:social:ig-comment-replies --no-debug'");
    }
}
