<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Каталожные строки провайдеров Payselection и PayKeeper (is_active=0 — адаптеры-scaffold,
 * не проверены в песочнице). Идемпотентно через INSERT IGNORE.
 */
final class Version20260603_payselection_paykeeper_providers extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catalog rows for Payselection and PayKeeper payment providers (inactive)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO payment_provider (code, name, supports_direct, supports_marketplace, is_active, sort_order) VALUES
                ('payselection', 'Payselection', 1, 1, 0, 35),
                ('paykeeper',    'PayKeeper',    1, 0, 0, 45)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM payment_provider WHERE code IN ('payselection', 'paykeeper')");
    }
}
