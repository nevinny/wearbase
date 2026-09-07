<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * city_hub.faq — FAQ городского хаба, [{question, answer}, ...].
 *
 * Зачем: гео-посадочные /{_locale}/cities/{slug} — единственные страницы каталога,
 * которые реально собирают гео-спрос (GSC 09.2026: /cities/sankt-peterburg
 * 2329 показов на позиции 8.4, см. docs/geo_city_demand_2026_09.md). Кураторский
 * intro есть только у 4 городов — и это в точности топ-4 по показам. FAQ под живые
 * модификаторы спроса («женская», «молодёжная», «дизайнеры») даёт extractable-блок
 * и FAQPage-разметку; наполняет app:seo:city-hub.
 */
final class Version20260907_city_hub_faq extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'city_hub.faq — FAQ городского хаба (JSON) для FAQPage-разметки';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE city_hub ADD faq JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE city_hub DROP faq');
    }
}
