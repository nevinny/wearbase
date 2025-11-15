<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251115085057 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE brand_link (
    id INT AUTO_INCREMENT NOT NULL,
    parent INT DEFAULT NULL,
    ord INT DEFAULT 0 NOT NULL,
    slug VARCHAR(255) NOT NULL,
    title VARCHAR(255) DEFAULT NULL,
    brand_id INT NOT NULL,
    link_url VARCHAR(255) DEFAULT NULL,
    status VARCHAR(255) DEFAULT \'active\' NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_by INT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    INDEX IDX_11CB934E44F5D008 (brand_id),
    INDEX IDX_11CB934EDE12AB56 (created_by),
    INDEX IDX_11CB934E16FE72E1 (updated_by),
    PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE brand_link ADD CONSTRAINT FK_11CB934E44F5D008 FOREIGN KEY (brand_id) REFERENCES brand (id)');
        $this->addSql('ALTER TABLE brand_link ADD CONSTRAINT FK_11CB934EDE12AB56 FOREIGN KEY (created_by) REFERENCES user (id)');
        $this->addSql('ALTER TABLE brand_link ADD CONSTRAINT FK_11CB934E16FE72E1 FOREIGN KEY (updated_by) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE brand_link DROP FOREIGN KEY FK_11CB934E44F5D008');
        $this->addSql('ALTER TABLE brand_link DROP FOREIGN KEY FK_11CB934EDE12AB56');
        $this->addSql('ALTER TABLE brand_link DROP FOREIGN KEY FK_11CB934E16FE72E1');
        $this->addSql('DROP TABLE brand_link');
    }
}
