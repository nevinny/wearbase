<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * brand.logo_locked — логотип закреплён оператором (ручной пик на карточке).
 * Закреплённый логотип НЕ перезаписывается агент-пушем (BrandIngestService::applyLogo).
 */
final class Version20260625_brand_logo_locked extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand.logo_locked — защита ручного логотипа от перезаписи пушем';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand ADD COLUMN logo_locked TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand DROP COLUMN logo_locked');
    }
}
