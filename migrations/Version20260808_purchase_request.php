<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808_purchase_request extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Provider-agnostic family purchase requests and append-only audit events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE purchase_request (
                id INT AUTO_INCREMENT NOT NULL,
                family_id INT NOT NULL,
                subject_id INT NOT NULL,
                created_by_id INT NOT NULL,
                decided_by_id INT DEFAULT NULL,
                product_url VARCHAR(2048) NOT NULL,
                comment LONGTEXT DEFAULT NULL,
                decision_comment LONGTEXT DEFAULT NULL,
                status VARCHAR(12) NOT NULL,
                decided_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_purchase_family (family_id),
                INDEX IDX_purchase_subject (subject_id),
                INDEX IDX_purchase_created_by (created_by_id),
                INDEX IDX_purchase_decided_by (decided_by_id),
                INDEX idx_purchase_request_family_status (family_id, status),
                CONSTRAINT FK_purchase_family FOREIGN KEY (family_id) REFERENCES family (id),
                CONSTRAINT FK_purchase_subject FOREIGN KEY (subject_id) REFERENCES client (id),
                CONSTRAINT FK_purchase_created_by FOREIGN KEY (created_by_id) REFERENCES client (id),
                CONSTRAINT FK_purchase_decided_by FOREIGN KEY (decided_by_id) REFERENCES client (id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE purchase_request_event (
                id INT AUTO_INCREMENT NOT NULL,
                purchase_request_id INT NOT NULL,
                actor_id INT NOT NULL,
                type VARCHAR(20) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_purchase_event_request (purchase_request_id),
                INDEX IDX_purchase_event_actor (actor_id),
                CONSTRAINT FK_purchase_event_request FOREIGN KEY (purchase_request_id) REFERENCES purchase_request (id) ON DELETE CASCADE,
                CONSTRAINT FK_purchase_event_actor FOREIGN KEY (actor_id) REFERENCES client (id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE purchase_request_event');
        $this->addSql('DROP TABLE purchase_request');
    }
}
