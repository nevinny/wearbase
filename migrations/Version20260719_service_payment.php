<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * service_payment — разовая оплата платной услуги (напр. «Размещение под ключ» 5 000₽,
 * sales_offer.md §10) на ПЛАТФОРМЕННЫЕ реквизиты YooKassa (тот же путь, что подписки — не
 * счёт бренда). Не привязана к Brand/Order/Subscription — свободный текст brand_hint.
 * Никакого физического DELETE — только status pending → succeeded/canceled.
 */
final class Version20260719_service_payment extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_payment — разовые платежи услуг (placement 5000₽) через платформенный YooKassa';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS service_payment (
                id INT AUTO_INCREMENT NOT NULL,
                service_code VARCHAR(30) NOT NULL,
                email VARCHAR(255) NOT NULL,
                amount NUMERIC(10, 2) NOT NULL DEFAULT '5000.00',
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                yookassa_payment_id VARCHAR(255) DEFAULT NULL,
                brand_hint VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                paid_at DATETIME DEFAULT NULL,
                INDEX idx_service_payment_status (status),
                INDEX idx_service_payment_yookassa_id (yookassa_payment_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS service_payment');
    }
}
