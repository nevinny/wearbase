<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004_wardrobe_photo_rotation extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store wardrobe photo rotation without modifying original image bytes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wardrobe_item_photo ADD rotation INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM wardrobe_item_photo WHERE rotation <> 0') > 0,
            'Rotated photo metadata must be retained.');
        $this->addSql('ALTER TABLE wardrobe_item_photo DROP rotation');
    }
}
