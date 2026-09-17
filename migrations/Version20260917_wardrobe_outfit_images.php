<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260917_wardrobe_outfit_images extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the prepared image and its source version for wardrobe outfits';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wardrobe_item ADD outfit_image_path VARCHAR(255) DEFAULT NULL, ADD outfit_image_source_hash VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Prepared wardrobe images must not be deleted.');
    }
}
