<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260524_landing extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create landing_lead table for capturing emails from landing pages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE landing_lead (
            id INT AUTO_INCREMENT NOT NULL,
            email VARCHAR(255) NOT NULL,
            source VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_landing_lead_email (email),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS landing_lead');
    }
}
