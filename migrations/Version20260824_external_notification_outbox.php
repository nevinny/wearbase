<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_external_notification_outbox extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Transactional external notification outbox and scheduled delivery';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE external_notification_outbox (id INT AUTO_INCREMENT NOT NULL, recipient_id INT NOT NULL, channel VARCHAR(20) NOT NULL, notification_type VARCHAR(50) NOT NULL, dedupe_key VARCHAR(140) NOT NULL, payload JSON NOT NULL, status VARCHAR(20) NOT NULL, attempts INT NOT NULL, available_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', locked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', last_error LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_external_outbox_recipient (recipient_id), INDEX idx_external_outbox_due (status, available_at), UNIQUE INDEX uniq_external_notification_dedupe (recipient_id, dedupe_key), CONSTRAINT FK_external_outbox_recipient FOREIGN KEY (recipient_id) REFERENCES client (id) ON DELETE CASCADE, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("INSERT IGNORE INTO scheduled_command (environment, name, command, schedule, enabled) VALUES ('prod', 'Уведомления: внешняя доставка', 'app:notification:deliver-outbox --no-debug', '* * * * *', 1)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE command = 'app:notification:deliver-outbox --no-debug'");
        $this->addSql('DROP TABLE external_notification_outbox');
    }
}
