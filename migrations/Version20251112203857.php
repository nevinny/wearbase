<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251112203857 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE brand_audience (id INT AUTO_INCREMENT NOT NULL, description LONGTEXT DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE brand_audience_brand (brand_audience_id INT NOT NULL, brand_id INT NOT NULL, INDEX IDX_5EB53620E5DDE6E3 (brand_audience_id), INDEX IDX_5EB5362044F5D008 (brand_id), PRIMARY KEY(brand_audience_id, brand_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE brand_audience_brand ADD CONSTRAINT FK_5EB53620E5DDE6E3 FOREIGN KEY (brand_audience_id) REFERENCES brand_audience (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE brand_audience_brand ADD CONSTRAINT FK_5EB5362044F5D008 FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE brand_audience_brand DROP FOREIGN KEY FK_5EB53620E5DDE6E3');
        $this->addSql('ALTER TABLE brand_audience_brand DROP FOREIGN KEY FK_5EB5362044F5D008');
        $this->addSql('DROP TABLE brand_audience');
        $this->addSql('DROP TABLE brand_audience_brand');
    }
}
