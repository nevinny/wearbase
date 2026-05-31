<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531_telegram_link_token extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add telegram_link_token column to client table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client ADD telegram_link_token VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client DROP telegram_link_token');
    }
}
