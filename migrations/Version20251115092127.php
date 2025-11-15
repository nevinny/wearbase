<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251115092127 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE brand_image (
    id INT AUTO_INCREMENT NOT NULL,
    parent INT DEFAULT NULL,
    ord INT DEFAULT 0 NOT NULL,
    slug VARCHAR(255) NOT NULL,
    title VARCHAR(255) DEFAULT NULL,
    brand_id INT NOT NULL,
    preview VARCHAR(255) DEFAULT NULL,
    image VARCHAR(255) DEFAULT NULL,
    status VARCHAR(20) DEFAULT \'active\' NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_by INT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    INDEX IDX_9EC4CD4844F5D008 (brand_id),
    INDEX IDX_9EC4CD48DE12AB56 (created_by),
    INDEX IDX_9EC4CD4816FE72E1 (updated_by),
    PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE brand_image ADD CONSTRAINT FK_9EC4CD4844F5D008 FOREIGN KEY (brand_id) REFERENCES brand (id)');
        $this->addSql('ALTER TABLE brand_image ADD CONSTRAINT FK_9EC4CD48DE12AB56 FOREIGN KEY (created_by) REFERENCES user (id)');
        $this->addSql('ALTER TABLE brand_image ADD CONSTRAINT FK_9EC4CD4816FE72E1 FOREIGN KEY (updated_by) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE brand_image DROP FOREIGN KEY FK_9EC4CD4844F5D008');
        $this->addSql('ALTER TABLE brand_image DROP FOREIGN KEY FK_9EC4CD48DE12AB56');
        $this->addSql('ALTER TABLE brand_image DROP FOREIGN KEY FK_9EC4CD4816FE72E1');
        $this->addSql('DROP TABLE brand_image');
    }
}
