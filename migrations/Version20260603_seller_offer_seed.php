<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Сид первой опубликованной редакции оферты продавца (seller_offer, ru, 1.0.0),
 * чтобы ЛК мог показать её на акцепт. Текст — плейсхолдер, финальную редакцию
 * утверждает юрист (см. docs). Идемпотентно через INSERT IGNORE по uq (type, locale, version).
 */
final class Version20260603_seller_offer_seed extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed initial published seller_offer document (ru, 1.0.0)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO offer_document
                (type, locale, version, title, content, content_hash, effective_from, requires_reacceptance, status, published_at, created_at)
            VALUES (
                'seller_offer', 'ru', '1.0.0',
                'Оферта для продавцов WEARBASE',
                'Настоящая оферта регулирует размещение и продажу товаров продавца на площадке WEARBASE. Площадка предоставляет витрину и приём оплаты на реквизиты продавца; продавец отвечает за товар, его описание, отгрузку и кассовый чек. Деньги за заказы поступают напрямую продавцу. ВНИМАНИЕ: это предварительная редакция, итоговый текст утверждается юристом.',
                SHA2('Настоящая оферта регулирует размещение и продажу товаров продавца на площадке WEARBASE. Площадка предоставляет витрину и приём оплаты на реквизиты продавца; продавец отвечает за товар, его описание, отгрузку и кассовый чек. Деньги за заказы поступают напрямую продавцу. ВНИМАНИЕ: это предварительная редакция, итоговый текст утверждается юристом.', 256),
                '2026-06-03', 0, 'published', NOW(), NOW()
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        // FK offer_acceptance(offer_document_id) ON DELETE RESTRICT — удаление возможно
        // только если по этой редакции ещё нет акцептов (best-effort откат).
        $this->addSql("DELETE FROM offer_document WHERE type = 'seller_offer' AND locale = 'ru' AND version = '1.0.0'");
    }
}
