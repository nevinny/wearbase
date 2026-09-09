<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_family_purchase_reminders extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schedule daily family purchase in-app reminders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT IGNORE INTO scheduled_command (environment, name, command, schedule, enabled) VALUES ('prod', 'Семья: напоминания о покупках', 'app:family:purchase-reminders --no-debug', '15 9 * * *', 1)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE command = 'app:family:purchase-reminders --no-debug'");
    }
}
