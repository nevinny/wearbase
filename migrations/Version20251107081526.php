<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251107081526 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE brand ADD vkontakte VARCHAR(100) DEFAULT NULL, ADD youtube VARCHAR(100) DEFAULT NULL, ADD email VARCHAR(100) DEFAULT NULL, ADD phone VARCHAR(20) DEFAULT NULL, ADD address VARCHAR(255) DEFAULT NULL, ADD description LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX slug_unique_idx ON brand (slug)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX slug_unique_idx ON brand');
        $this->addSql('ALTER TABLE brand DROP vkontakte, DROP youtube, DROP email, DROP phone, DROP address, DROP description');
    }
}
