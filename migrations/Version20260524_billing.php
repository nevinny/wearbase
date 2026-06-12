<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260524_billing extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tariff, subscription, and payment tables for brand billing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS tariff (
                id           INT AUTO_INCREMENT NOT NULL,
                name         VARCHAR(100) NOT NULL,
                code         VARCHAR(50) NOT NULL,
                description  TEXT DEFAULT NULL,
                price_rub    NUMERIC(10,2) DEFAULT '0.00' NOT NULL,
                trial_days   INT DEFAULT 30 NOT NULL,
                max_products INT DEFAULT NULL,
                max_images   INT DEFAULT NULL,
                has_analytics TINYINT(1) DEFAULT 0 NOT NULL,
                has_priority TINYINT(1) DEFAULT 0 NOT NULL,
                is_active    TINYINT(1) DEFAULT 1 NOT NULL,
                created_at   DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                updated_at   DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                UNIQUE INDEX UNIQ_TARIFF_CODE (code),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS subscription (
                id                    INT AUTO_INCREMENT NOT NULL,
                brand_id              INT NOT NULL,
                tariff_id             INT NOT NULL,
                status                VARCHAR(20) DEFAULT 'trial' NOT NULL,
                current_period_start  DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                current_period_end    DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                trial_ends_at         DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)",
                cancelled_at          DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)",
                auto_renew            TINYINT(1) DEFAULT 1 NOT NULL,
                created_at            DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                updated_at            DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                INDEX IDX_SUBSCRIPTION_BRAND (brand_id),
                INDEX IDX_SUBSCRIPTION_TARIFF (tariff_id),
                INDEX IDX_SUBSCRIPTION_STATUS (status),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS payment (
                id                  INT AUTO_INCREMENT NOT NULL,
                subscription_id     INT NOT NULL,
                amount              NUMERIC(10,2) NOT NULL,
                currency            VARCHAR(3) DEFAULT 'RUB' NOT NULL,
                status              VARCHAR(20) DEFAULT 'pending' NOT NULL,
                payment_method      VARCHAR(30) DEFAULT NULL,
                gateway_payment_id  VARCHAR(255) DEFAULT NULL,
                paid_at             DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)",
                created_at          DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                INDEX IDX_PAYMENT_SUBSCRIPTION (subscription_id),
                INDEX IDX_PAYMENT_STATUS (status),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE subscription
                ADD CONSTRAINT FK_SUBSCRIPTION_BRAND
                FOREIGN KEY (brand_id) REFERENCES brand (id)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE subscription
                ADD CONSTRAINT FK_SUBSCRIPTION_TARIFF
                FOREIGN KEY (tariff_id) REFERENCES tariff (id)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE payment
                ADD CONSTRAINT FK_PAYMENT_SUBSCRIPTION
                FOREIGN KEY (subscription_id) REFERENCES subscription (id)
        SQL);

        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO tariff (name, code, description, price_rub, trial_days, max_products, max_images, has_analytics, has_priority, created_at, updated_at)
            VALUES
                ('Бесплатный', 'free',  'Базовая карточка бренда, до 10 товаров',                  '0.00',   30, 10,  5,  0, 0, NOW(), NOW()),
                ('Базовый',   'basic', 'До 50 товаров, контакты, приоритет в выдаче',               '1500.00', 0,  50,  20, 0, 1, NOW(), NOW()),
                ('Премиум',   'premium','Безлимит товаров, аналитика, расширенная карточка, приоритет','3000.00', 0, NULL,NULL,1, 1, NOW(), NOW())
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS payment');
        $this->addSql('DROP TABLE IF EXISTS subscription');
        $this->addSql('DROP TABLE IF EXISTS tariff');
    }
}
