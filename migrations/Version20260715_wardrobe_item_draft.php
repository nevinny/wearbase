<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * wardrobe_item_draft — стейджинг распознанных, но не подтверждённых карточек
 * гардероба (авто-инжест фото). НЕ WardrobeItem, физический DELETE допустим —
 * это эфемерный черновик распознавания, не пользовательские данные о вещи.
 * FK на client (таблица фронтового App\Entity\User), НЕ на админский user.
 */
final class Version20260715_wardrobe_item_draft extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'wardrobe_item_draft — стейджинг распознанных карточек гардероба (авто-инжест фото)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS wardrobe_item_draft (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                batch_id VARCHAR(36) NOT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'pending',
                confidence VARCHAR(4) DEFAULT NULL,
                category VARCHAR(100) DEFAULT NULL,
                name VARCHAR(255) DEFAULT NULL,
                size VARCHAR(50) DEFAULT NULL,
                notes LONGTEXT DEFAULT NULL,
                ai_raw JSON DEFAULT NULL,
                error VARCHAR(255) DEFAULT NULL,
                photo VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME DEFAULT NULL,
                INDEX idx_wardrobe_draft_user_batch (user_id, batch_id),
                INDEX idx_wardrobe_draft_status (status),
                PRIMARY KEY(id),
                CONSTRAINT FK_wardrobe_item_draft_user FOREIGN KEY (user_id) REFERENCES client (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS wardrobe_item_draft');
    }
}
