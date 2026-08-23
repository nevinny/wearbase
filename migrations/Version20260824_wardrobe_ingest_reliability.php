<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_wardrobe_ingest_reliability extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Idempotent wardrobe photo upload and database worker leases';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE wardrobe_item_draft ADD content_hash VARCHAR(64) DEFAULT NULL, ADD lease_until DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD worker_id VARCHAR(64) DEFAULT NULL, ADD attempts INT DEFAULT 0 NOT NULL");
        $this->addSql('CREATE UNIQUE INDEX uniq_wardrobe_draft_subject_hash ON wardrobe_item_draft (user_id, content_hash)');
        $this->addSql('CREATE INDEX idx_wardrobe_draft_status_lease ON wardrobe_item_draft (status, lease_until)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_wardrobe_draft_subject_hash ON wardrobe_item_draft');
        $this->addSql('DROP INDEX idx_wardrobe_draft_status_lease ON wardrobe_item_draft');
        $this->addSql('ALTER TABLE wardrobe_item_draft DROP content_hash, DROP lease_until, DROP worker_id, DROP attempts');
    }
}
