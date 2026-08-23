<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_family_claim_lifecycle extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add expiry and revocation lifecycle to managed child access links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE client ADD family_claim_expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD family_claim_revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql('UPDATE client SET family_claim_expires_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 7 DAY) WHERE family_claim_token IS NOT NULL AND claimed_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client DROP family_claim_expires_at, DROP family_claim_revoked_at');
    }
}
