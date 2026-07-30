<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * social_post.media_path: VARCHAR(255) → TEXT под карусель Instagram.
 * Слайды хранятся построчно (один путь на строку, см. SocialPost::getMediaPaths()),
 * а 10 путей вида /images/social/103-b48068c4.png в 255 символов не влезают.
 * Одиночная картинка остаётся строкой без переводов — данные совместимы в обе стороны.
 */
final class Version20260731_social_post_media_paths extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'social_post.media_path → TEXT (список слайдов карусели, по одному на строку)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE social_post CHANGE media_path media_path LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE social_post CHANGE media_path media_path VARCHAR(255) DEFAULT NULL');
    }
}
