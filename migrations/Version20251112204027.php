<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251112204027 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE brand_audience ADD created_by INT DEFAULT NULL, ADD updated_by INT DEFAULT NULL, ADD slug VARCHAR(255) NOT NULL, ADD title VARCHAR(255) DEFAULT NULL, ADD parent INT DEFAULT NULL, ADD ord INT DEFAULT 0 NOT NULL, ADD created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, ADD updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, ADD status VARCHAR(255) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE brand_audience ADD CONSTRAINT FK_3AFD0709DE12AB56 FOREIGN KEY (created_by) REFERENCES user (id)');
        $this->addSql('ALTER TABLE brand_audience ADD CONSTRAINT FK_3AFD070916FE72E1 FOREIGN KEY (updated_by) REFERENCES user (id)');
        $this->addSql('CREATE INDEX IDX_3AFD0709DE12AB56 ON brand_audience (created_by)');
        $this->addSql('CREATE INDEX IDX_3AFD070916FE72E1 ON brand_audience (updated_by)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE brand_audience DROP FOREIGN KEY FK_3AFD0709DE12AB56');
        $this->addSql('ALTER TABLE brand_audience DROP FOREIGN KEY FK_3AFD070916FE72E1');
        $this->addSql('DROP INDEX IDX_3AFD0709DE12AB56 ON brand_audience');
        $this->addSql('DROP INDEX IDX_3AFD070916FE72E1 ON brand_audience');
        $this->addSql('ALTER TABLE brand_audience DROP created_by, DROP updated_by, DROP slug, DROP title, DROP parent, DROP ord, DROP created_at, DROP updated_at, DROP status');
    }
}
