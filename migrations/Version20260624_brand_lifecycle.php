<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Жизненный цикл бренда:
 *  - niche_status / niche_reason / niche_checked_at — вердикт классификатора ниши
 *    (app:brand:niche-check). NULL=не проверен, 'in'=в нише (одежда+красота),
 *    'off'=чужая ниша (аптека/техника/авто/продукты/гигиена рта/БАД). 'off' гейтит
 *    конвейер и дрип-публикацию.
 *  - closed_at — бренд прекратил работу (tombstone): страница остаётся 200/индексируется,
 *    показывает плашку «закрылся» + действующие альтернативы.
 */
final class Version20260624_brand_lifecycle extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand.niche_status/niche_reason/niche_checked_at + closed_at — гигиена ниши и tombstone';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand ADD COLUMN niche_status VARCHAR(12) DEFAULT NULL, ADD COLUMN niche_reason VARCHAR(255) DEFAULT NULL, ADD COLUMN niche_checked_at DATETIME DEFAULT NULL, ADD COLUMN closed_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_brand_niche ON brand (niche_status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_brand_niche ON brand');
        $this->addSql('ALTER TABLE brand DROP COLUMN niche_status, DROP COLUMN niche_reason, DROP COLUMN niche_checked_at, DROP COLUMN closed_at');
    }
}
