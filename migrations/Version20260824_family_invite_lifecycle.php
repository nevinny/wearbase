<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_family_invite_lifecycle extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add expiry, intended email and revocation to family invitations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE family_invite ADD expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD intended_email VARCHAR(180) DEFAULT NULL, ADD revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD revoked_by_id INT DEFAULT NULL");
        $this->addSql('UPDATE family_invite SET expires_at = DATE_ADD(created_at, INTERVAL 7 DAY) WHERE expires_at IS NULL');
        $this->addSql("ALTER TABLE family_invite MODIFY expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql('ALTER TABLE family_invite ADD CONSTRAINT FK_FAMILY_INVITE_REVOKED_BY FOREIGN KEY (revoked_by_id) REFERENCES client (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_FAMILY_INVITE_REVOKED_BY ON family_invite (revoked_by_id)');
        $this->addSql('CREATE INDEX IDX_FAMILY_INVITE_PENDING ON family_invite (family_id, accepted_at, revoked_at, expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE family_invite DROP FOREIGN KEY FK_FAMILY_INVITE_REVOKED_BY');
        $this->addSql('DROP INDEX IDX_FAMILY_INVITE_REVOKED_BY ON family_invite');
        $this->addSql('DROP INDEX IDX_FAMILY_INVITE_PENDING ON family_invite');
        $this->addSql('ALTER TABLE family_invite DROP expires_at, DROP intended_email, DROP revoked_at, DROP revoked_by_id');
    }
}
