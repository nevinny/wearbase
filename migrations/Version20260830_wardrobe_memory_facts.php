<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260830_wardrobe_memory_facts extends AbstractMigration
{
    public function getDescription(): string { return 'Editable profile-scoped personal wardrobe memory facts'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE wardrobe_memory_fact (id INT AUTO_INCREMENT NOT NULL, profile_subject_id INT NOT NULL, actor_id INT NOT NULL, source_type VARCHAR(12) NOT NULL, source_id INT NOT NULL, signal_source VARCHAR(20) NOT NULL, fact VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', edited_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', deleted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', deleted_by_user TINYINT(1) DEFAULT 0 NOT NULL, INDEX IDX_WARDROBE_MEMORY_SUBJECT (profile_subject_id), INDEX IDX_WARDROBE_MEMORY_ACTOR (actor_id), INDEX idx_wardrobe_memory_subject_active (profile_subject_id, deleted_at), UNIQUE INDEX uniq_wardrobe_memory_source (profile_subject_id, source_type, source_id), CONSTRAINT FK_WARDROBE_MEMORY_SUBJECT FOREIGN KEY (profile_subject_id) REFERENCES client (id) ON DELETE CASCADE, CONSTRAINT FK_WARDROBE_MEMORY_ACTOR FOREIGN KEY (actor_id) REFERENCES client (id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void { $this->addSql('DROP TABLE wardrobe_memory_fact'); }
}
