<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251112204726 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE brand_tier (id INT AUTO_INCREMENT NOT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, description LONGTEXT DEFAULT NULL, slug VARCHAR(255) NOT NULL, title VARCHAR(255) DEFAULT NULL, parent INT DEFAULT NULL, ord INT DEFAULT 0 NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, status VARCHAR(255) DEFAULT \'active\' NOT NULL, INDEX IDX_3F99D35DE12AB56 (created_by), INDEX IDX_3F99D3516FE72E1 (updated_by), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE brand_tier_brand (brand_tier_id INT NOT NULL, brand_id INT NOT NULL, INDEX IDX_1263870F446F5CA7 (brand_tier_id), INDEX IDX_1263870F44F5D008 (brand_id), PRIMARY KEY(brand_tier_id, brand_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE brand_tier ADD CONSTRAINT FK_3F99D35DE12AB56 FOREIGN KEY (created_by) REFERENCES user (id)');
        $this->addSql('ALTER TABLE brand_tier ADD CONSTRAINT FK_3F99D3516FE72E1 FOREIGN KEY (updated_by) REFERENCES user (id)');
        $this->addSql('ALTER TABLE brand_tier_brand ADD CONSTRAINT FK_1263870F446F5CA7 FOREIGN KEY (brand_tier_id) REFERENCES brand_tier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE brand_tier_brand ADD CONSTRAINT FK_1263870F44F5D008 FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE brand_tier DROP FOREIGN KEY FK_3F99D35DE12AB56');
        $this->addSql('ALTER TABLE brand_tier DROP FOREIGN KEY FK_3F99D3516FE72E1');
        $this->addSql('ALTER TABLE brand_tier_brand DROP FOREIGN KEY FK_1263870F446F5CA7');
        $this->addSql('ALTER TABLE brand_tier_brand DROP FOREIGN KEY FK_1263870F44F5D008');
        $this->addSql('DROP TABLE brand_tier');
        $this->addSql('DROP TABLE brand_tier_brand');
    }
}
