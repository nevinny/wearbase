<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * social_post.script_key 48 → 96: ключи гардеробных рилсов «wardrobe-templates-v1.tNN-slug-vN»
 * бывают до ~60 символов, пачка enqueue падала на 1406 Data too long.
 */
final class Version20261007_social_post_script_key_96 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'social_post.script_key VARCHAR(96)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE social_post MODIFY script_key VARCHAR(96) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE social_post MODIFY script_key VARCHAR(48) DEFAULT NULL');
    }
}
