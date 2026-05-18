<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260513191159 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_brand_locale ON brand');
        $this->addSql('ALTER TABLE brand ADD meta_title VARCHAR(255) DEFAULT NULL, ADD meta_description VARCHAR(255) DEFAULT NULL, ADD meta_keywords VARCHAR(255) DEFAULT NULL');
        $this->addSql('DROP INDEX `primary` ON brand_size_brand');
        $this->addSql('ALTER TABLE brand_size_brand ADD PRIMARY KEY (brand_id, brand_size_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE brand DROP meta_title, DROP meta_description, DROP meta_keywords');
        $this->addSql('CREATE INDEX idx_brand_locale ON brand (locale)');
        $this->addSql('DROP INDEX `PRIMARY` ON brand_size_brand');
        $this->addSql('ALTER TABLE brand_size_brand ADD PRIMARY KEY (brand_size_id, brand_id)');
    }
}
