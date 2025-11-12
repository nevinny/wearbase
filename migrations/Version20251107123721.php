<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251107123721 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE brand ADD instagram_url VARCHAR(50) DEFAULT NULL, ADD telegram_url VARCHAR(50) DEFAULT NULL, ADD vkontakte_url VARCHAR(100) DEFAULT NULL, ADD youtube_url VARCHAR(100) DEFAULT NULL, DROP instagram, DROP telegram, DROP vkontakte, DROP youtube');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE brand ADD instagram VARCHAR(50) DEFAULT NULL, ADD telegram VARCHAR(50) DEFAULT NULL, ADD vkontakte VARCHAR(100) DEFAULT NULL, ADD youtube VARCHAR(100) DEFAULT NULL, DROP instagram_url, DROP telegram_url, DROP vkontakte_url, DROP youtube_url');
    }
}
