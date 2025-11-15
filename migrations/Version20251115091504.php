<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251115091504 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alphabet CHANGE status status VARCHAR(20) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE brand CHANGE status status VARCHAR(20) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE brand_audience CHANGE status status VARCHAR(20) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE brand_link CHANGE status status VARCHAR(20) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE brand_size CHANGE status status VARCHAR(20) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE brand_style CHANGE status status VARCHAR(20) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE brand_tier CHANGE status status VARCHAR(20) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE main CHANGE status status VARCHAR(20) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE product CHANGE status status VARCHAR(20) DEFAULT \'active\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alphabet CHANGE status status VARCHAR(255) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE brand CHANGE status status VARCHAR(255) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE brand_audience CHANGE status status VARCHAR(255) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE brand_link CHANGE status status VARCHAR(255) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE brand_size CHANGE status status VARCHAR(255) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE brand_style CHANGE status status VARCHAR(255) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE brand_tier CHANGE status status VARCHAR(255) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE main CHANGE status status VARCHAR(255) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE product CHANGE status status VARCHAR(255) DEFAULT \'active\' NOT NULL');
    }
}
