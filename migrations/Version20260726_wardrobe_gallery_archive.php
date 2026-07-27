<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260726_wardrobe_gallery_archive extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Wardrobe photo gallery with cover metadata and recoverable archive';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS wardrobe_item_photo (
                id INT AUTO_INCREMENT NOT NULL,
                item_id INT NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                photo_type VARCHAR(20) NOT NULL DEFAULT 'product',
                sort_order INT NOT NULL DEFAULT 0,
                source VARCHAR(20) NOT NULL DEFAULT 'user_upload',
                original_filename VARCHAR(255) DEFAULT NULL,
                mime_type VARCHAR(100) DEFAULT NULL,
                file_size INT DEFAULT NULL,
                is_cover TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                deleted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_wardrobe_photo_item_deleted (item_id, deleted_at),
                CONSTRAINT fk_wardrobe_photo_item FOREIGN KEY (item_id) REFERENCES wardrobe_item (id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        // Старые обложки становятся первой записью галереи, сами файлы не перемещаются.
        // Признак происхождения фото: 'import' — как и раньше (пакетный JSON/CSV импорт);
        // 'web' с заполненным product_url — фото почти всегда подтянуто автозагрузчиком
        // по ссылке Wildberries (WardrobeRemotePhotoFetcher), иначе это своя фотография
        // пользователя (в т.ч. весь Telegram-канал, где WB-автозагрузки нет).
        $this->addSql(<<<'SQL'
            INSERT INTO wardrobe_item_photo
                (item_id, file_path, photo_type, sort_order, source, is_cover, created_at)
            SELECT wi.id, wi.photo, 'cover', 0,
                CASE
                    WHEN wi.source = 'import' THEN 'import'
                    WHEN wi.source = 'web' AND wi.product_url IS NOT NULL AND wi.product_url <> '' THEN 'marketplace'
                    ELSE 'user_upload'
                END,
                1, wi.created_at
            FROM wardrobe_item wi
            WHERE wi.photo IS NOT NULL AND wi.photo <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM wardrobe_item_photo p
                  WHERE p.item_id = wi.id AND p.file_path = wi.photo
              )
            SQL);
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Gallery metadata may contain user photos and must not be deleted.');
    }
}
