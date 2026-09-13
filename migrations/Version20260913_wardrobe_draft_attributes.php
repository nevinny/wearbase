<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913_wardrobe_draft_attributes extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve structured wardrobe photo attributes for review and promotion';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wardrobe_item_draft ADD attributes JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wardrobe_item_draft DROP attributes');
    }
}
