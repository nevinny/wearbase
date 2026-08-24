<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_web_push_cleanup_schedule extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schedule daily cleanup of revoked Web Push subscriptions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT IGNORE INTO scheduled_command (environment, name, command, schedule, enabled) VALUES ('prod', 'Уведомления: очистка Web Push', 'app:notifications:cleanup-web-push --no-debug', '41 3 * * *', 1)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE command = 'app:notifications:cleanup-web-push --no-debug'");
    }
}
