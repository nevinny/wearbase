<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_wardrobe_onboarding extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resumable onboarding state for personal and managed family wardrobes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wardrobe_item_draft ADD actor_id INT DEFAULT NULL, ADD accepted_item_id INT DEFAULT NULL, ADD accepted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE wardrobe_item_draft SET actor_id = user_id WHERE actor_id IS NULL');
        $this->addSql('ALTER TABLE wardrobe_item_draft MODIFY actor_id INT NOT NULL');
        $this->addSql('ALTER TABLE wardrobe_item_draft ADD CONSTRAINT FK_wardrobe_draft_actor FOREIGN KEY (actor_id) REFERENCES client (id), ADD CONSTRAINT FK_wardrobe_draft_accepted_item FOREIGN KEY (accepted_item_id) REFERENCES wardrobe_item (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_wardrobe_draft_actor_batch ON wardrobe_item_draft (actor_id, batch_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_wardrobe_draft_accepted_item ON wardrobe_item_draft (accepted_item_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE wardrobe_onboarding (
                id INT AUTO_INCREMENT NOT NULL,
                subject_id INT NOT NULL,
                stage VARCHAR(16) NOT NULL,
                active_batch_id VARCHAR(36) DEFAULT NULL,
                skipped_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                completed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_wardrobe_onboarding_subject (subject_id),
                INDEX idx_wardrobe_onboarding_batch (active_batch_id),
                CONSTRAINT fk_wardrobe_onboarding_subject FOREIGN KEY (subject_id) REFERENCES client (id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE wardrobe_onboarding');
        $this->addSql('ALTER TABLE wardrobe_item_draft DROP FOREIGN KEY FK_wardrobe_draft_actor, DROP FOREIGN KEY FK_wardrobe_draft_accepted_item');
        $this->addSql('DROP INDEX idx_wardrobe_draft_actor_batch ON wardrobe_item_draft');
        $this->addSql('DROP INDEX uniq_wardrobe_draft_accepted_item ON wardrobe_item_draft');
        $this->addSql('ALTER TABLE wardrobe_item_draft DROP actor_id, DROP accepted_item_id, DROP accepted_at');
    }
}
