<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Каталожные строки провайдеров Сбер и Robokassa (is_active=0 — адаптеры написаны,
 * но не проверены в песочнице; активировать после sandbox + расширения
 * SellerPaymentAccount::isReadyToAcceptOnline()). Идемпотентно через INSERT IGNORE.
 */
final class Version20260603_sber_robokassa_providers extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catalog rows for Sber and Robokassa payment providers (inactive)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO payment_provider (code, name, supports_direct, supports_marketplace, is_active, sort_order) VALUES
                ('sber',      'Сбербанк',  1, 0, 0, 15),
                ('robokassa', 'Robokassa', 1, 0, 0, 25)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Удаляем только если на провайдера нет счетов (FK seller_payment_account RESTRICT).
        $this->addSql("DELETE FROM payment_provider WHERE code IN ('sber', 'robokassa')");
    }
}
