<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_wardrobe_ingest_health_gate extends AbstractMigration
{
    public function getDescription(): string { return 'Schedule production wardrobe ingest health monitoring'; }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT IGNORE INTO scheduled_command (environment, name, command, schedule, enabled) VALUES ('prod', 'Гардероб: здоровье распознавания', 'app:wardrobe:ingest-health --check --json --no-debug', '*/5 * * * *', 1)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE command = 'app:wardrobe:ingest-health --check --json --no-debug'");
    }
}
