<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260825_look_share extends AbstractMigration
{
    public function getDescription(): string { return 'Add wardrobe outfit share links (guest /l/{token}) and referral events'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE wardrobe_outfit_share (id INT AUTO_INCREMENT NOT NULL, outfit_id INT NOT NULL, created_by_id INT NOT NULL, token CHAR(64) NOT NULL, status VARCHAR(16) DEFAULT 'pending_parent' NOT NULL, ttl VARCHAR(8) DEFAULT NULL, granted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', view_count INT DEFAULT 0 NOT NULL, last_viewed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_share_token (token), INDEX idx_share_outfit (outfit_id), CONSTRAINT fk_share_outfit FOREIGN KEY (outfit_id) REFERENCES wardrobe_outfit (id) ON DELETE CASCADE, CONSTRAINT fk_share_created_by FOREIGN KEY (created_by_id) REFERENCES client (id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('CREATE TABLE referral_event (id INT AUTO_INCREMENT NOT NULL, inviter_id INT NOT NULL, invitee_id INT NOT NULL, source VARCHAR(32) NOT NULL, share_id INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_ref_inviter (inviter_id), INDEX idx_ref_invitee (invitee_id), UNIQUE INDEX uniq_referral_once (invitee_id, source, share_id), CONSTRAINT FK_REF_INVITER FOREIGN KEY (inviter_id) REFERENCES client (id), CONSTRAINT FK_REF_INVITEE FOREIGN KEY (invitee_id) REFERENCES client (id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE referral_event');
        $this->addSql('DROP TABLE wardrobe_outfit_share');
    }
}
