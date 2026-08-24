<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_wardrobe_wear_events extends AbstractMigration
{
    public function getDescription(): string { return 'Add confirmed wardrobe wear events and item usage history'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE wardrobe_wear_event (id INT AUTO_INCREMENT NOT NULL, profile_subject_id INT NOT NULL, actor_id INT NOT NULL, source_outfit_id INT DEFAULT NULL, type VARCHAR(12) NOT NULL, status VARCHAR(12) NOT NULL, worn_on DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', signal_source VARCHAR(20) NOT NULL, occasion VARCHAR(255) DEFAULT NULL, comment VARCHAR(1000) DEFAULT NULL, photo VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', confirmed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', comfort VARCHAR(16) DEFAULT NULL, wants_repeat TINYINT(1) DEFAULT NULL, feedback_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_WEAR_SUBJECT (profile_subject_id), INDEX IDX_WEAR_ACTOR (actor_id), INDEX IDX_WEAR_OUTFIT (source_outfit_id), INDEX idx_wear_subject_date (profile_subject_id, worn_on), UNIQUE INDEX uniq_wear_outfit_day (profile_subject_id, source_outfit_id, worn_on, type), CONSTRAINT FK_WEAR_SUBJECT FOREIGN KEY (profile_subject_id) REFERENCES client (id), CONSTRAINT FK_WEAR_ACTOR FOREIGN KEY (actor_id) REFERENCES client (id), CONSTRAINT FK_WEAR_OUTFIT FOREIGN KEY (source_outfit_id) REFERENCES wardrobe_outfit (id) ON DELETE SET NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('CREATE TABLE wardrobe_wear_event_item (id INT AUTO_INCREMENT NOT NULL, event_id INT NOT NULL, item_id INT NOT NULL, selection_source VARCHAR(12) NOT NULL, confidence VARCHAR(8) DEFAULT NULL, confirmed TINYINT(1) NOT NULL, INDEX IDX_WEAR_EVENT_ITEM_EVENT (event_id), INDEX IDX_WEAR_EVENT_ITEM_ITEM (item_id), UNIQUE INDEX uniq_wear_event_item (event_id, item_id), CONSTRAINT FK_WEAR_EVENT_ITEM_EVENT FOREIGN KEY (event_id) REFERENCES wardrobe_wear_event (id) ON DELETE CASCADE, CONSTRAINT FK_WEAR_EVENT_ITEM_ITEM FOREIGN KEY (item_id) REFERENCES wardrobe_item (id) ON DELETE CASCADE, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE wardrobe_wear_event_item');
        $this->addSql('DROP TABLE wardrobe_wear_event');
    }
}
