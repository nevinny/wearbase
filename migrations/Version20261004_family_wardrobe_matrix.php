<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004_family_wardrobe_matrix extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Family wardrobe purchase needs and jumpsuit category';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE wardrobe_need (
                id INT AUTO_INCREMENT NOT NULL,
                family_id INT NOT NULL,
                subject_id INT NOT NULL,
                category_id INT NOT NULL,
                purchase_request_id INT DEFAULT NULL,
                season VARCHAR(10) NOT NULL,
                title VARCHAR(150) NOT NULL,
                quantity INT NOT NULL,
                size VARCHAR(50) DEFAULT NULL,
                notes VARCHAR(1000) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                closed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_wardrobe_need_family_closed (family_id, closed_at),
                INDEX idx_wardrobe_need_subject (subject_id),
                INDEX idx_wardrobe_need_category (category_id),
                INDEX idx_wardrobe_need_purchase (purchase_request_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_wardrobe_need_family FOREIGN KEY (family_id) REFERENCES family (id) ON DELETE CASCADE,
                CONSTRAINT fk_wardrobe_need_subject FOREIGN KEY (subject_id) REFERENCES client (id) ON DELETE CASCADE,
                CONSTRAINT fk_wardrobe_need_category FOREIGN KEY (category_id) REFERENCES wardrobe_category (id),
                CONSTRAINT fk_wardrobe_need_purchase FOREIGN KEY (purchase_request_id) REFERENCES purchase_request (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql("INSERT IGNORE INTO wardrobe_category (parent_id, code, name, sort_order, is_active, created_at, updated_at) SELECT id, 'jumpsuit', 'Комбинезон', 42, 1, NOW(), NOW() FROM wardrobe_category WHERE code = 'dresses'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE wardrobe_need');
        // The category can already be used by wardrobe items; keep it on rollback.
    }
}
