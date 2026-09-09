<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260825_ai_daily_usage extends AbstractMigration
{
    public function getDescription(): string { return 'Add per-user daily AI allowance counter (referral reward bumps on top of base 30/day)'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_daily_usage (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, usage_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', requests INT DEFAULT 0 NOT NULL, UNIQUE INDEX uniq_ai_user_day (user_id, usage_date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_daily_usage');
    }
}
