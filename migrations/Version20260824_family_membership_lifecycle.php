<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_family_membership_lifecycle extends AbstractMigration
{
    public function getDescription(): string { return 'Add adulthood and auditable family membership lifecycle'; }
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE client ADD adulthood_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql("CREATE TABLE family_membership_event (id INT AUTO_INCREMENT NOT NULL, family_id INT NOT NULL, actor_id INT NOT NULL, subject_id INT NOT NULL, type VARCHAR(24) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_FAMILY_EVENT_FAMILY (family_id), INDEX IDX_FAMILY_EVENT_ACTOR (actor_id), INDEX IDX_FAMILY_EVENT_SUBJECT (subject_id), CONSTRAINT FK_FAMILY_EVENT_FAMILY FOREIGN KEY (family_id) REFERENCES family (id) ON DELETE CASCADE, CONSTRAINT FK_FAMILY_EVENT_ACTOR FOREIGN KEY (actor_id) REFERENCES client (id), CONSTRAINT FK_FAMILY_EVENT_SUBJECT FOREIGN KEY (subject_id) REFERENCES client (id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE family_membership_event');
        $this->addSql('ALTER TABLE client DROP adulthood_at');
    }
}
