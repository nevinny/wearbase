<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_wardrobe_activation_events extends AbstractMigration
{
    public function getDescription(): string { return 'Privacy-safe idempotent wardrobe activation milestones'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE wardrobe_activation_event (id INT AUTO_INCREMENT NOT NULL, profile_subject_id INT NOT NULL, event_type VARCHAR(32) NOT NULL, metadata JSON NOT NULL, occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_WARDROBE_ACTIVATION_SUBJECT (profile_subject_id), UNIQUE INDEX uniq_wardrobe_activation_milestone (profile_subject_id, event_type), CONSTRAINT FK_WARDROBE_ACTIVATION_SUBJECT FOREIGN KEY (profile_subject_id) REFERENCES client (id) ON DELETE CASCADE, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE wardrobe_activation_event');
    }
}
