<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_family_web_push extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Opt-in Web Push subscriptions and per-event push preference';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE web_push_subscription (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, endpoint LONGTEXT NOT NULL, endpoint_hash VARCHAR(64) NOT NULL, public_key LONGTEXT NOT NULL, auth_token LONGTEXT NOT NULL, content_encoding VARCHAR(20) DEFAULT NULL, revoked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_web_push_endpoint (endpoint_hash), INDEX IDX_WEB_PUSH_USER (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE web_push_subscription ADD CONSTRAINT FK_WEB_PUSH_USER FOREIGN KEY (user_id) REFERENCES client (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE web_push_subscription');
    }
}
