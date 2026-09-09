<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_family_purchase_notifications extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add idempotency key for family purchase in-app notifications';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD dedupe_key VARCHAR(100) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_recipient_dedupe ON notification (recipient_id, dedupe_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_notification_recipient_dedupe ON notification');
        $this->addSql('ALTER TABLE notification DROP dedupe_key');
    }
}
