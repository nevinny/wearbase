<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_wardrobe_consent_retention extends AbstractMigration
{
    public function getDescription(): string { return 'Add separate wardrobe photo consent and draft storage accounting'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wardrobe_item_draft ADD file_size INT DEFAULT NULL');
        $this->addSql("CREATE TABLE wardrobe_consent (id INT AUTO_INCREMENT NOT NULL, subject_id INT NOT NULL, granted_by_id INT NOT NULL, policy_version VARCHAR(20) NOT NULL, photo_processing_granted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', personalization_granted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', shared_learning_granted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_WARDROBE_CONSENT_SUBJECT (subject_id), INDEX IDX_WARDROBE_CONSENT_GRANTOR (granted_by_id), CONSTRAINT FK_WARDROBE_CONSENT_SUBJECT FOREIGN KEY (subject_id) REFERENCES client (id) ON DELETE CASCADE, CONSTRAINT FK_WARDROBE_CONSENT_GRANTOR FOREIGN KEY (granted_by_id) REFERENCES client (id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("INSERT IGNORE INTO scheduled_command (environment, name, command, schedule, enabled) VALUES ('prod', 'Гардероб: распознавание фото', 'app:wardrobe:ingest-drafts --no-debug', '*/2 * * * *', 1), ('prod', 'Гардероб: очистка черновиков', 'app:wardrobe:cleanup-drafts --no-debug', '17 3 * * *', 1)");
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE wardrobe_consent');
        $this->addSql('ALTER TABLE wardrobe_item_draft DROP file_size');
        $this->addSql("DELETE FROM scheduled_command WHERE command IN ('app:wardrobe:ingest-drafts --no-debug', 'app:wardrobe:cleanup-drafts --no-debug')");
    }
}
