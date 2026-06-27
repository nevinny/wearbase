<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Чистка тест-мусора в brand_faq: отладочная запись «Что это? → Тест агент-API.» на тестовом
 * бренде-фикстуре `test-ingest-brand` (Test Ingest, status=new), оставшаяся от ручного теста
 * агент-API ингеста. Бренд в коде/фикстурах/сидах не пересоздаётся — запись безопасно удалить.
 *
 * brand_faq не имеет soft-delete (управляется delete-and-replace в BrandFaqRepository), поэтому
 * физический DELETE здесь — штатная системная операция, а не пользовательское удаление.
 * Идемпотентно: повторный прогон удалит 0 строк. Скоуп — строго по slug тестового бренда,
 * реальные бренды (включая `brightest`, где «test» — случайная подстрока) не затрагиваются.
 */
final class Version20260627_brand_faq_clean_test extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Чистка тест-мусора в brand_faq (FAQ тестового бренда test-ingest-brand)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "DELETE f FROM brand_faq f
               JOIN brand b ON b.id = f.brand_id
              WHERE b.slug = 'test-ingest-brand'",
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Удаление тест-мусора brand_faq необратимо.');
    }
}
