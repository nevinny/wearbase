<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260608_contact_refresh_index extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand: index(contact_status, contact_enriched_at) — для app:contacts:refresh';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_contact_refresh ON brand (contact_status, contact_enriched_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_contact_refresh ON brand');
    }
}
