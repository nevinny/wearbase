<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * social_post.cta_url / cta_label — CTA вынесен из подписи, чтобы публикаторы оформляли
 * ссылку по-своему (TG — кликабельный текст без видимых UTM; VK — текст+URL; IG — без URL).
 */
final class Version20260617_social_cta extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'social_post.cta_url / cta_label — CTA отдельно от подписи (per-platform рендер ссылки)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE social_post ADD COLUMN cta_url VARCHAR(255) DEFAULT NULL, ADD COLUMN cta_label VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE social_post DROP COLUMN cta_url, DROP COLUMN cta_label');
    }
}
