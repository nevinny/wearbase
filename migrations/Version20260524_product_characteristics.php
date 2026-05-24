<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260524_product_characteristics extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product characteristics fields: material, composition, care_instructions, country_of_origin, manufacturer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD material VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD composition VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD care_instructions LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD country_of_origin VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD manufacturer VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP material');
        $this->addSql('ALTER TABLE product DROP composition');
        $this->addSql('ALTER TABLE product DROP care_instructions');
        $this->addSql('ALTER TABLE product DROP country_of_origin');
        $this->addSql('ALTER TABLE product DROP manufacturer');
    }
}
