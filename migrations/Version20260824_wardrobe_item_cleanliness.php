<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_wardrobe_item_cleanliness extends AbstractMigration
{
    public function getDescription(): string { return 'Add explicit cleanliness availability to wardrobe items'; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE wardrobe_item ADD cleanliness_status VARCHAR(12) DEFAULT 'clean' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wardrobe_item DROP cleanliness_status');
    }
}
