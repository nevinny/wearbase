<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260608_brand_seo_fields extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add founding_year, tagline, country to brand; create category + brand_category tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE category (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(100) NOT NULL, title VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_64C19C1989D9B62 (slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE brand_category (brand_id INT NOT NULL, category_id INT NOT NULL, INDEX IDX_C17C8AD044F5D008 (brand_id), INDEX IDX_C17C8AD012469DE2 (category_id), PRIMARY KEY(brand_id, category_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE brand_category ADD CONSTRAINT FK_C17C8AD044F5D008 FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE brand_category ADD CONSTRAINT FK_C17C8AD012469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE brand ADD founding_year VARCHAR(4) DEFAULT NULL, ADD tagline VARCHAR(100) DEFAULT NULL, ADD country VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_category DROP FOREIGN KEY FK_C17C8AD044F5D008');
        $this->addSql('ALTER TABLE brand_category DROP FOREIGN KEY FK_C17C8AD012469DE2');
        $this->addSql('DROP TABLE brand_category');
        $this->addSql('DROP TABLE category');
        $this->addSql('ALTER TABLE brand DROP founding_year, DROP tagline, DROP country');
    }
}
