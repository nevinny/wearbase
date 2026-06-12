<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Юридический слой маркетплейса:
 *  - seller_legal_entity — юр.лица продавца (1 бренд → N юр.лиц, с периодами действия);
 *  - offer_document       — версионированные оферты/политики (иммутабельны);
 *  - offer_acceptance     — факт акцепта оферты пользователем (append-only, доказательство);
 *  - payment_provider      — справочник платёжек (ЮKassa, Т-Бизнес, CloudPayments, СБП);
 *  - seller_payment_account — счёт приёма: юр.лицо ↔ платёжка + реквизиты (бренд выбирает платёжку);
 *  - снимки на order/subscription: продавец-of-record, счёт приёма, действовавшая редакция оферты;
 *  - регламент ЗоЗПП: возврат предоплаты владельцем агрегатора (правило 10 дней).
 *
 * Реквизиты/секреты живут в seller_payment_account — пока только хранилище,
 * маршрутизация PaymentService по выбранной платёжке не затрагивается.
 *
 * Примечание: MySQL не поддерживает ALTER TABLE ... ADD COLUMN IF NOT EXISTS
 * (это MariaDB). Применение миграции трекается Doctrine, поэтому ALTER — обычный.
 */
final class Version20260603_legal_offers extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seller legal entities, versioned offers + acceptances, prepayment-refund regulation snapshots';
    }

    public function up(Schema $schema): void
    {
        // ── seller_legal_entity ───────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS seller_legal_entity (
                id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
                brand_id            INT          NOT NULL,
                legal_form          VARCHAR(20)  NOT NULL DEFAULT 'ooo' COMMENT 'ooo | ip | self_employed',
                legal_name          VARCHAR(255) NOT NULL COMMENT 'Полное наименование/ФИО',
                inn                 VARCHAR(12)  DEFAULT NULL,
                kpp                 VARCHAR(9)   DEFAULT NULL,
                ogrn                VARCHAR(15)  DEFAULT NULL COMMENT 'ОГРН / ОГРНИП',
                legal_address       VARCHAR(500) DEFAULT NULL,
                is_identified       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Идентификация продавца (ЗоЗПП/289-ФЗ)',
                effective_from      DATE         DEFAULT NULL,
                effective_to        DATE         DEFAULT NULL COMMENT 'NULL = действует сейчас',
                status              VARCHAR(20)  NOT NULL DEFAULT 'active' COMMENT 'active | archived',
                created_at          DATETIME     NOT NULL,
                updated_at          DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY idx_sle_brand (brand_id),
                KEY idx_sle_brand_status (brand_id, status),
                CONSTRAINT fk_sle_brand
                    FOREIGN KEY (brand_id) REFERENCES brand(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ── payment_provider (справочник платёжек) ───────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS payment_provider (
                id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                code                 VARCHAR(30)  NOT NULL COMMENT 'yookassa | tinkoff | cloudpayments | sbp …',
                name                 VARCHAR(100) NOT NULL,
                supports_direct      TINYINT(1)   NOT NULL DEFAULT 1,
                supports_marketplace TINYINT(1)   NOT NULL DEFAULT 0,
                is_active            TINYINT(1)   NOT NULL DEFAULT 1,
                sort_order           INT          NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_payment_provider_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO payment_provider (code, name, supports_direct, supports_marketplace, is_active, sort_order) VALUES
                ('yookassa',     'ЮKassa',       1, 1, 1, 10),
                ('tinkoff',      'Т-Бизнес',     1, 1, 0, 20),
                ('cloudpayments','CloudPayments',1, 1, 0, 30),
                ('sbp',          'СБП',          1, 0, 0, 40)
        SQL);

        // ── seller_payment_account (юр.лицо ↔ платёжка + реквизиты) ───────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS seller_payment_account (
                id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                legal_entity_id  INT UNSIGNED NOT NULL,
                provider_id      INT UNSIGNED NOT NULL,
                mode             VARCHAR(20)  NOT NULL DEFAULT 'direct' COMMENT 'direct | marketplace',
                account_ref      VARCHAR(255) DEFAULT NULL COMMENT 'Публичный идентификатор: shopId / seller account_id',
                secret_encrypted TEXT         DEFAULT NULL COMMENT 'Секрет шлюза, шифруется на уровне приложения',
                config           JSON         DEFAULT NULL COMMENT 'Доп. параметры провайдера',
                is_primary       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Основной счёт приёма для юр.лица',
                status           VARCHAR(20)  NOT NULL DEFAULT 'active' COMMENT 'active | disabled',
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_spa_entity_provider (legal_entity_id, provider_id),
                KEY idx_spa_entity (legal_entity_id),
                KEY idx_spa_provider (provider_id),
                CONSTRAINT fk_spa_legal_entity
                    FOREIGN KEY (legal_entity_id) REFERENCES seller_legal_entity(id) ON DELETE CASCADE,
                CONSTRAINT fk_spa_provider
                    FOREIGN KEY (provider_id) REFERENCES payment_provider(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ── offer_document (иммутабельные версии) ─────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS offer_document (
                id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
                type                  VARCHAR(30)  NOT NULL COMMENT 'buyer_offer | seller_offer | privacy | returns | platform_rules',
                locale                VARCHAR(5)   NOT NULL DEFAULT 'ru',
                version               VARCHAR(20)  NOT NULL COMMENT 'напр. 2.3.0',
                title                 VARCHAR(255) NOT NULL,
                content               LONGTEXT     NOT NULL,
                content_hash          CHAR(64)     NOT NULL COMMENT 'sha256 — фиксирует неизменность текста',
                effective_from        DATE         NOT NULL,
                requires_reacceptance TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Редакция требует повторного акцепта',
                status                VARCHAR(20)  NOT NULL DEFAULT 'draft' COMMENT 'draft | published | archived',
                published_at          DATETIME     DEFAULT NULL,
                created_at            DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_offer_type_locale_version (type, locale, version),
                KEY idx_offer_lookup (type, locale, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ── offer_acceptance (append-only, доказательство акцепта) ────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS offer_acceptance (
                id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id           INT          NOT NULL,
                offer_document_id INT UNSIGNED NOT NULL,
                context_type      VARCHAR(30)  NOT NULL COMMENT 'registration | reaccept | seller_onboarding',
                accepted_at       DATETIME     NOT NULL,
                ip                VARCHAR(45)  DEFAULT NULL,
                user_agent        VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_oa_user (user_id),
                KEY idx_oa_offer (offer_document_id),
                CONSTRAINT fk_oa_user
                    FOREIGN KEY (user_id) REFERENCES client(id) ON DELETE CASCADE,
                CONSTRAINT fk_oa_offer
                    FOREIGN KEY (offer_document_id) REFERENCES offer_document(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ── order: продавец-of-record, снимок оферты, регламент возврата ──────
        $this->addSql(<<<'SQL'
            ALTER TABLE `order`
                ADD COLUMN seller_legal_entity_id        INT UNSIGNED DEFAULT NULL COMMENT 'Юр.лицо-продавец на момент заказа',
                ADD COLUMN seller_payment_account_id     INT UNSIGNED DEFAULT NULL COMMENT 'Счёт/платёжка, через которую ушли деньги (NULL при оплате при получении)',
                ADD COLUMN accepted_offer_id             INT UNSIGNED DEFAULT NULL COMMENT 'Редакция оферты покупателя, действовавшая для заказа',
                ADD COLUMN prepayment_refund_requested_at DATETIME    DEFAULT NULL COMMENT 'ЗоЗПП: дата требования возврата предоплаты (старт 10 дней)',
                ADD COLUMN seller_delivery_confirmed_at  DATETIME     DEFAULT NULL COMMENT 'Внутреннее подтверждение бренда: товар принят/передан',
                ADD COLUMN refund_confirmation_sent_at   DATETIME     DEFAULT NULL COMMENT 'Копия подтверждения направлена покупателю (гасит требование, если в 10 дней)'
        SQL);
        $this->addSql('CREATE INDEX idx_order_seller_legal_entity ON `order` (seller_legal_entity_id)');
        $this->addSql('CREATE INDEX idx_order_seller_payment_account ON `order` (seller_payment_account_id)');
        $this->addSql('CREATE INDEX idx_order_accepted_offer ON `order` (accepted_offer_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE `order`
                ADD CONSTRAINT fk_order_seller_legal_entity
                    FOREIGN KEY (seller_legal_entity_id) REFERENCES seller_legal_entity(id) ON DELETE SET NULL,
                ADD CONSTRAINT fk_order_seller_payment_account
                    FOREIGN KEY (seller_payment_account_id) REFERENCES seller_payment_account(id) ON DELETE SET NULL,
                ADD CONSTRAINT fk_order_accepted_offer
                    FOREIGN KEY (accepted_offer_id) REFERENCES offer_document(id) ON DELETE SET NULL
        SQL);

        // ── subscription: снимок редакции оферты продавца ─────────────────────
        $this->addSql(<<<'SQL'
            ALTER TABLE subscription
                ADD COLUMN accepted_offer_id INT UNSIGNED DEFAULT NULL COMMENT 'Редакция оферты продавца, действовавшая для подписки'
        SQL);
        $this->addSql('CREATE INDEX idx_subscription_accepted_offer ON subscription (accepted_offer_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE subscription
                ADD CONSTRAINT fk_subscription_accepted_offer
                    FOREIGN KEY (accepted_offer_id) REFERENCES offer_document(id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription DROP FOREIGN KEY fk_subscription_accepted_offer');
        $this->addSql('DROP INDEX idx_subscription_accepted_offer ON subscription');
        $this->addSql('ALTER TABLE subscription DROP COLUMN accepted_offer_id');

        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY fk_order_seller_legal_entity');
        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY fk_order_seller_payment_account');
        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY fk_order_accepted_offer');
        $this->addSql('DROP INDEX idx_order_seller_legal_entity ON `order`');
        $this->addSql('DROP INDEX idx_order_seller_payment_account ON `order`');
        $this->addSql('DROP INDEX idx_order_accepted_offer ON `order`');
        $this->addSql(<<<'SQL'
            ALTER TABLE `order`
                DROP COLUMN seller_legal_entity_id,
                DROP COLUMN seller_payment_account_id,
                DROP COLUMN accepted_offer_id,
                DROP COLUMN prepayment_refund_requested_at,
                DROP COLUMN seller_delivery_confirmed_at,
                DROP COLUMN refund_confirmation_sent_at
        SQL);

        $this->addSql('DROP TABLE IF EXISTS offer_acceptance');
        $this->addSql('DROP TABLE IF EXISTS offer_document');
        $this->addSql('DROP TABLE IF EXISTS seller_payment_account');
        $this->addSql('DROP TABLE IF EXISTS payment_provider');
        $this->addSql('DROP TABLE IF EXISTS seller_legal_entity');
    }
}
