<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * social_post.image_prompt — адаптивный промпт генерации изображения (из caption через LLM).
 * Храним промежуточный результат для разбора и последующего улучшения качества картинок.
 */
final class Version20260621_social_image_prompt extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'social_post.image_prompt — адаптивный image-промпт (из caption через LLM)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE social_post ADD COLUMN image_prompt LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE social_post DROP COLUMN image_prompt');
    }
}
