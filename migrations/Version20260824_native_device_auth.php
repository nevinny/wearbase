<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_native_device_auth extends AbstractMigration
{
    public function getDescription(): string { return 'Opaque rotating native device credentials for wardrobe API'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE native_device_session (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, device_hash VARCHAR(64) NOT NULL, access_hash VARCHAR(64) NOT NULL, access_expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', revoked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_NATIVE_ACCESS_HASH (access_hash), INDEX idx_native_session_user_device (user_id, device_hash), INDEX IDX_NATIVE_SESSION_USER (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE native_refresh_token (id INT AUTO_INCREMENT NOT NULL, session_id INT NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_NATIVE_REFRESH_HASH (token_hash), INDEX IDX_NATIVE_REFRESH_SESSION (session_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE native_device_session ADD CONSTRAINT FK_NATIVE_SESSION_USER FOREIGN KEY (user_id) REFERENCES client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE native_refresh_token ADD CONSTRAINT FK_NATIVE_REFRESH_SESSION FOREIGN KEY (session_id) REFERENCES native_device_session (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE native_refresh_token');
        $this->addSql('DROP TABLE native_device_session');
    }
}
