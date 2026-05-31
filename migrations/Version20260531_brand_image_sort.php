<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531_brand_image_sort extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sort_order column to brand_image table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_image ADD sort_order INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_image DROP sort_order');
    }
}
