<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260825_circles extends AbstractMigration
{
    public function getDescription(): string { return 'Add wardrobe circles (кружки подруг), memberships and invites; circle grant on wardrobe_outfit_share'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE wardrobe_circle (id INT AUTO_INCREMENT NOT NULL, owner_id INT NOT NULL, title VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', dissolved_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_circle_owner (owner_id), CONSTRAINT FK_CIRCLE_OWNER FOREIGN KEY (owner_id) REFERENCES client (id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE wardrobe_circle_member (id INT AUTO_INCREMENT NOT NULL, circle_id INT NOT NULL, user_id INT NOT NULL, role VARCHAR(16) DEFAULT \'member\' NOT NULL, status VARCHAR(16) DEFAULT \'active\' NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_circle_member_user (user_id), UNIQUE INDEX uniq_circle_member (circle_id, user_id), CONSTRAINT FK_CMEMBER_CIRCLE FOREIGN KEY (circle_id) REFERENCES wardrobe_circle (id) ON DELETE CASCADE, CONSTRAINT FK_CMEMBER_USER FOREIGN KEY (user_id) REFERENCES client (id) ON DELETE CASCADE, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE wardrobe_circle_invite (id INT AUTO_INCREMENT NOT NULL, circle_id INT NOT NULL, created_by_id INT NOT NULL, token CHAR(64) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', revoked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_circle_invite_token (token), INDEX idx_circle_invite_circle (circle_id), CONSTRAINT FK_CINVITE_CIRCLE FOREIGN KEY (circle_id) REFERENCES wardrobe_circle (id) ON DELETE CASCADE, CONSTRAINT FK_CINVITE_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES client (id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE wardrobe_outfit_share ADD circle_id INT DEFAULT NULL, ADD INDEX idx_share_circle (circle_id), ADD CONSTRAINT fk_share_circle FOREIGN KEY (circle_id) REFERENCES wardrobe_circle (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wardrobe_outfit_share DROP FOREIGN KEY fk_share_circle, DROP INDEX idx_share_circle, DROP COLUMN circle_id');
        $this->addSql('DROP TABLE wardrobe_circle_invite');
        $this->addSql('DROP TABLE wardrobe_circle_member');
        $this->addSql('DROP TABLE wardrobe_circle');
    }
}
