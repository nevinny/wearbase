<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия контента бренда для агент-API (/api/v1/brands/upsert):
 * прод пропускает payload с версией ≤ текущей (защита от ре-доставки/гонок).
 */
final class Version20260604_brand_content_version extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand.content_version — защита от ре-доставки в агент-API';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand ADD content_version INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand DROP content_version');
    }
}
