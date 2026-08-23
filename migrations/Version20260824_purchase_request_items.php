<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_purchase_request_items extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add item-level purchase decisions and backfill existing requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE purchase_request_item (id INT AUTO_INCREMENT NOT NULL, purchase_request_id INT NOT NULL, decided_by_id INT DEFAULT NULL, source_url VARCHAR(2048) NOT NULL, estimated_price NUMERIC(12, 2) DEFAULT NULL, status VARCHAR(12) NOT NULL, decision_comment LONGTEXT DEFAULT NULL, decided_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_PURCHASE_ITEM_REQUEST (purchase_request_id), INDEX IDX_PURCHASE_ITEM_DECIDED_BY (decided_by_id), INDEX idx_purchase_item_request_status (purchase_request_id, status), CONSTRAINT FK_PURCHASE_ITEM_REQUEST FOREIGN KEY (purchase_request_id) REFERENCES purchase_request (id) ON DELETE CASCADE, CONSTRAINT FK_PURCHASE_ITEM_DECIDED_BY FOREIGN KEY (decided_by_id) REFERENCES client (id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('INSERT INTO purchase_request_item (purchase_request_id, decided_by_id, source_url, estimated_price, status, decision_comment, decided_at, created_at) SELECT id, decided_by_id, product_url, estimated_price, status, decision_comment, decided_at, created_at FROM purchase_request');
        $this->addSql('ALTER TABLE purchase_request_event ADD item_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_request_event ADD CONSTRAINT FK_PURCHASE_EVENT_ITEM FOREIGN KEY (item_id) REFERENCES purchase_request_item (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_PURCHASE_EVENT_ITEM ON purchase_request_event (item_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_request_event DROP FOREIGN KEY FK_PURCHASE_EVENT_ITEM');
        $this->addSql('DROP INDEX IDX_PURCHASE_EVENT_ITEM ON purchase_request_event');
        $this->addSql('ALTER TABLE purchase_request_event DROP item_id');
        $this->addSql('DROP TABLE purchase_request_item');
    }
}
