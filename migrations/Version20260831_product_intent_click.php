<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * product_intent_click — лог сигналов «Хочу купить» на карточке товара бренда без
 * настроенного приёма онлайн-оплаты (см. App\Twig\BrandSaleExtension::canSell()).
 *
 * Зачем: гейтим саму ПРОДАЖУ (не показ) у таких брендов — вместо «В корзину» кнопка
 * «Хочу купить» (POST /product/{uuid}/want). Append-only лог копит спрос: видимость
 * в ЛК бренда/админке + прогрессивные напоминания владельцу (app:brand:payment-reminders).
 */
final class Version20260831_product_intent_click extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'product_intent_click — лог сигналов «Хочу купить» для брендов без приёма оплаты';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS product_intent_click (
                id INT AUTO_INCREMENT NOT NULL,
                brand_id INT NOT NULL,
                product_id INT NOT NULL,
                locale VARCHAR(8) DEFAULT NULL,
                referer VARCHAR(255) DEFAULT NULL,
                ua_hash CHAR(64) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_pic_brand_created (brand_id, created_at),
                INDEX idx_pic_product (product_id),
                CONSTRAINT fk_pic_brand FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE CASCADE,
                CONSTRAINT fk_pic_product FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE,
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS product_intent_click');
    }
}
