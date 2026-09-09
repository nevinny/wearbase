<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260825_referral_rewards extends AbstractMigration
{
    public function getDescription(): string { return 'Add referral reward ledger (welcome/inviter AI-quota bumps and badges)'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE referral_reward_ledger (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, referral_event_id INT DEFAULT NULL, role VARCHAR(16) NOT NULL, kind VARCHAR(32) NOT NULL, amount INT DEFAULT 0 NOT NULL, idempotency_key VARCHAR(80) NOT NULL, reason VARCHAR(64) NOT NULL, status VARCHAR(16) DEFAULT \'active\' NOT NULL, granted_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_reward_user (user_id), UNIQUE INDEX uniq_reward_idem (idempotency_key), CONSTRAINT FK_REWARD_USER FOREIGN KEY (user_id) REFERENCES client (id), CONSTRAINT FK_REWARD_EVENT FOREIGN KEY (referral_event_id) REFERENCES referral_event (id) ON DELETE SET NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE referral_reward_ledger');
    }
}
