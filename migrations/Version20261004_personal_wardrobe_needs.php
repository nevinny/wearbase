<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004_personal_wardrobe_needs extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow private wardrobe needs without a family';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wardrobe_need MODIFY family_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM wardrobe_need WHERE family_id IS NULL') > 0,
            'Personal wardrobe needs must be removed before reverting their schema.');
        $this->addSql('ALTER TABLE wardrobe_need MODIFY family_id INT NOT NULL');
    }
}
