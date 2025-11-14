<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251112183810 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE brand_size (id INT AUTO_INCREMENT NOT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, description LONGTEXT DEFAULT NULL, slug VARCHAR(255) NOT NULL, title VARCHAR(255) DEFAULT NULL, parent INT DEFAULT NULL, ord INT DEFAULT 0 NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, status VARCHAR(255) DEFAULT \'active\' NOT NULL, INDEX IDX_D0A72ED5DE12AB56 (created_by), INDEX IDX_D0A72ED516FE72E1 (updated_by), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE brand_size_brand (brand_size_id INT NOT NULL, brand_id INT NOT NULL, INDEX IDX_F75D1DB1AEB60D5C (brand_size_id), INDEX IDX_F75D1DB144F5D008 (brand_id), PRIMARY KEY(brand_size_id, brand_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE brand_size ADD CONSTRAINT FK_D0A72ED5DE12AB56 FOREIGN KEY (created_by) REFERENCES user (id)');
        $this->addSql('ALTER TABLE brand_size ADD CONSTRAINT FK_D0A72ED516FE72E1 FOREIGN KEY (updated_by) REFERENCES user (id)');
        $this->addSql('ALTER TABLE brand_size_brand ADD CONSTRAINT FK_F75D1DB1AEB60D5C FOREIGN KEY (brand_size_id) REFERENCES brand_size (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE brand_size_brand ADD CONSTRAINT FK_F75D1DB144F5D008 FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE brand_size DROP FOREIGN KEY FK_D0A72ED5DE12AB56');
        $this->addSql('ALTER TABLE brand_size DROP FOREIGN KEY FK_D0A72ED516FE72E1');
        $this->addSql('ALTER TABLE brand_size_brand DROP FOREIGN KEY FK_F75D1DB1AEB60D5C');
        $this->addSql('ALTER TABLE brand_size_brand DROP FOREIGN KEY FK_F75D1DB144F5D008');
        $this->addSql('DROP TABLE brand_size');
        $this->addSql('DROP TABLE brand_size_brand');
    }
}
