<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * brand_content_revision.loss_streak — счётчик окон подряд с вердиктом loss.
 * Антифлаппинг closed-loop: перегенерация только после подтверждения (≥2 окна подряд),
 * один шумовой провал на низкочастотных брендовых запросах контент не трогает.
 */
final class Version20260616_revision_loss_streak extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_content_revision.loss_streak — антифлаппинг (реген после 2 окон loss подряд)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_content_revision ADD COLUMN loss_streak SMALLINT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_content_revision DROP COLUMN loss_streak');
    }
}
