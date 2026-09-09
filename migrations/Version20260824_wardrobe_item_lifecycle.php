<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_wardrobe_item_lifecycle extends AbstractMigration
{
    public function getDescription(): string { return 'Add care, repair and external transfer history for wardrobe items'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE wardrobe_item_lifecycle_event (id INT AUTO_INCREMENT NOT NULL, item_id INT NOT NULL, profile_subject_id INT NOT NULL, actor_id INT NOT NULL, type VARCHAR(24) NOT NULL, status VARCHAR(12) NOT NULL, provider VARCHAR(255) DEFAULT NULL, cost NUMERIC(12, 2) DEFAULT NULL, note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', completed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_WARDROBE_LIFECYCLE_ITEM (item_id), INDEX IDX_WARDROBE_LIFECYCLE_SUBJECT (profile_subject_id), INDEX IDX_WARDROBE_LIFECYCLE_ACTOR (actor_id), INDEX idx_wardrobe_lifecycle_item_status (item_id, status), CONSTRAINT FK_WARDROBE_LIFECYCLE_ITEM FOREIGN KEY (item_id) REFERENCES wardrobe_item (id) ON DELETE CASCADE, CONSTRAINT FK_WARDROBE_LIFECYCLE_SUBJECT FOREIGN KEY (profile_subject_id) REFERENCES client (id), CONSTRAINT FK_WARDROBE_LIFECYCLE_ACTOR FOREIGN KEY (actor_id) REFERENCES client (id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void { $this->addSql('DROP TABLE wardrobe_item_lifecycle_event'); }
}
