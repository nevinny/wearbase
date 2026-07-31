<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * brand_image.frame_kind/frame_checked_at — классификация кадра для отбора/порядка слайдов
 * галерей и Reels (app:social:classify-frames, vision-модель ollama). frame_kind:
 * product_person|product_flat|scene|other, NULL = ещё не классифицирован.
 */
final class Version20260731_brand_image_frame_kind extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_image.frame_kind + frame_checked_at (классификация кадра для галерей/Reels)';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('frame_kind')) {
            $this->addSql('ALTER TABLE brand_image ADD frame_kind VARCHAR(20) DEFAULT NULL');
        }
        if (!$this->columnExists('frame_checked_at')) {
            $this->addSql('ALTER TABLE brand_image ADD frame_checked_at DATETIME DEFAULT NULL');
        }
        if (!$this->indexExists('idx_brand_image_frame_checked_at')) {
            $this->addSql('CREATE INDEX idx_brand_image_frame_checked_at ON brand_image (frame_checked_at)');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->indexExists('idx_brand_image_frame_checked_at')) {
            $this->addSql('DROP INDEX idx_brand_image_frame_checked_at ON brand_image');
        }
        if ($this->columnExists('frame_checked_at')) {
            $this->addSql('ALTER TABLE brand_image DROP frame_checked_at');
        }
        if ($this->columnExists('frame_kind')) {
            $this->addSql('ALTER TABLE brand_image DROP frame_kind');
        }
    }

    private function columnExists(string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['brand_image', $column],
        );
    }

    private function indexExists(string $index): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            ['brand_image', $index],
        );
    }
}
