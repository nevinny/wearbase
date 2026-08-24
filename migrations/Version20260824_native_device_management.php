<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_native_device_management extends AbstractMigration
{
    public function getDescription(): string { return 'Opaque native device identity, safe label, activity timestamp and cleanup schedule'; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE native_device_session ADD public_id VARCHAR(32) DEFAULT NULL, ADD device_label VARCHAR(16) DEFAULT 'other' NOT NULL, ADD last_used_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql("UPDATE native_device_session SET public_id = REPLACE(UUID(), '-', '') WHERE public_id IS NULL");
        $this->addSql('ALTER TABLE native_device_session MODIFY public_id VARCHAR(32) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_NATIVE_DEVICE_PUBLIC_ID ON native_device_session (public_id)');
        $this->addSql("INSERT IGNORE INTO scheduled_command (environment, name, command, schedule, enabled) VALUES ('prod', 'Native auth: очистка устройств', 'app:native-auth:cleanup --no-debug', '43 3 * * *', 1)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE command = 'app:native-auth:cleanup --no-debug'");
        $this->addSql('DROP INDEX UNIQ_NATIVE_DEVICE_PUBLIC_ID ON native_device_session');
        $this->addSql('ALTER TABLE native_device_session DROP public_id, DROP device_label, DROP last_used_at');
    }
}
