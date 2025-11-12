<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251105182318 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE brand (id INT AUTO_INCREMENT NOT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, slug VARCHAR(255) NOT NULL, title VARCHAR(255) DEFAULT NULL, parent INT DEFAULT NULL, ord INT DEFAULT 0 NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, status VARCHAR(255) DEFAULT \'active\' NOT NULL, INDEX IDX_1C52F958DE12AB56 (created_by), INDEX IDX_1C52F95816FE72E1 (updated_by), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE main (id INT AUTO_INCREMENT NOT NULL, parent_id INT DEFAULT NULL, entity_type_id INT NOT NULL, title VARCHAR(255) DEFAULT NULL, slug VARCHAR(255) NOT NULL, full_path VARCHAR(255) NOT NULL, template VARCHAR(255) DEFAULT NULL, ord INT DEFAULT NULL, is_node TINYINT(1) DEFAULT NULL, entity_id INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, status VARCHAR(255) DEFAULT \'active\' NOT NULL, INDEX IDX_BF28CD64727ACA70 (parent_id), INDEX IDX_BF28CD645681BEB0 (entity_type_id), UNIQUE INDEX path_unique_idx (full_path), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE section_link (id INT AUTO_INCREMENT NOT NULL, parent_type_id INT NOT NULL, child_type_id INT NOT NULL, INDEX IDX_B31275FAB704F8D5 (parent_type_id), INDEX IDX_B31275FAA7F8C488 (child_type_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE section_type (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(50) NOT NULL, name VARCHAR(255) NOT NULL, template VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, is_node TINYINT(1) DEFAULT 1 NOT NULL, entity_class VARCHAR(255) DEFAULT NULL, crud_controller_class VARCHAR(255) DEFAULT NULL, controller_class VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, is_verified TINYINT(1) NOT NULL, first_name VARCHAR(255) DEFAULT NULL, last_name VARCHAR(255) DEFAULT NULL, phone VARCHAR(20) DEFAULT NULL, address LONGTEXT DEFAULT NULL, UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE brand ADD CONSTRAINT FK_1C52F958DE12AB56 FOREIGN KEY (created_by) REFERENCES user (id)');
        $this->addSql('ALTER TABLE brand ADD CONSTRAINT FK_1C52F95816FE72E1 FOREIGN KEY (updated_by) REFERENCES user (id)');
        $this->addSql('ALTER TABLE main ADD CONSTRAINT FK_BF28CD64727ACA70 FOREIGN KEY (parent_id) REFERENCES main (id)');
        $this->addSql('ALTER TABLE main ADD CONSTRAINT FK_BF28CD645681BEB0 FOREIGN KEY (entity_type_id) REFERENCES section_type (id)');
        $this->addSql('ALTER TABLE section_link ADD CONSTRAINT FK_B31275FAB704F8D5 FOREIGN KEY (parent_type_id) REFERENCES section_type (id)');
        $this->addSql('ALTER TABLE section_link ADD CONSTRAINT FK_B31275FAA7F8C488 FOREIGN KEY (child_type_id) REFERENCES section_type (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE brand DROP FOREIGN KEY FK_1C52F958DE12AB56');
        $this->addSql('ALTER TABLE brand DROP FOREIGN KEY FK_1C52F95816FE72E1');
        $this->addSql('ALTER TABLE main DROP FOREIGN KEY FK_BF28CD64727ACA70');
        $this->addSql('ALTER TABLE main DROP FOREIGN KEY FK_BF28CD645681BEB0');
        $this->addSql('ALTER TABLE section_link DROP FOREIGN KEY FK_B31275FAB704F8D5');
        $this->addSql('ALTER TABLE section_link DROP FOREIGN KEY FK_B31275FAA7F8C488');
        $this->addSql('DROP TABLE brand');
        $this->addSql('DROP TABLE main');
        $this->addSql('DROP TABLE section_link');
        $this->addSql('DROP TABLE section_type');
        $this->addSql('DROP TABLE user');
    }
}
