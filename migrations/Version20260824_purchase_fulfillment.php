<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_purchase_fulfillment extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add purchase ordering, delivery and fitting feedback lifecycle';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE purchase_request_item ADD actual_price NUMERIC(12, 2) DEFAULT NULL, ADD ordered_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD delivered_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql("CREATE TABLE fitting_feedback (id INT AUTO_INCREMENT NOT NULL, item_id INT NOT NULL, actor_id INT NOT NULL, outcome VARCHAR(20) NOT NULL, tried_size VARCHAR(50) DEFAULT NULL, sizing VARCHAR(20) DEFAULT NULL, fit_issues JSON NOT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_FITTING_ITEM (item_id), INDEX IDX_FITTING_ACTOR (actor_id), CONSTRAINT FK_FITTING_ITEM FOREIGN KEY (item_id) REFERENCES purchase_request_item (id) ON DELETE CASCADE, CONSTRAINT FK_FITTING_ACTOR FOREIGN KEY (actor_id) REFERENCES client (id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE fitting_feedback');
        $this->addSql('ALTER TABLE purchase_request_item DROP actual_price, DROP ordered_at, DROP delivered_at');
    }
}
