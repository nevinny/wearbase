<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * api_usage_daily — учёт обращений к платным внешним API по дням (грань: service + дата).
 * Первый потребитель — Yandex Search API (discover). Атомарный INSERT…ON DUPLICATE KEY
 * держит счётчик корректным при параллельных воркерах.
 */
final class Version20260623_api_usage_daily extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'api_usage_daily — суточный учёт обращений к платным API (Yandex Search и др.)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS api_usage_daily (
                id INT AUTO_INCREMENT NOT NULL,
                service VARCHAR(64) NOT NULL,
                usage_date DATE NOT NULL,
                requests INT NOT NULL DEFAULT 0,
                UNIQUE INDEX uniq_service_date (service, usage_date),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS api_usage_daily');
    }
}
