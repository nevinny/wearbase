<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * yandex_history — дневной ряд из Яндекс.Вебмастера (history-эндпоинты):
 * страниц в поиске + суммарные показы/клики. Нужен, чтобы видеть ДИНАМИКУ за месяцы
 * (эффект правок сайта) и как сенсор для разгона дрип-публикации под живой индекс Яндекса.
 */
final class Version20260702_yandex_history extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'yandex_history — дневной ряд показов/кликов/страниц-в-поиске (динамика)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS yandex_history (
                day DATE NOT NULL,
                pages_in_search INT DEFAULT NULL,
                shows INT DEFAULT NULL,
                clicks INT DEFAULT NULL,
                PRIMARY KEY (day)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS yandex_history');
    }
}
