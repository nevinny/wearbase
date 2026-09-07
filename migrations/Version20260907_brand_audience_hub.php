<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * brand_audience.h1 — человеко-читаемая формулировка H1/title публичной страницы
 * /{_locale}/audience/{slug} (docs/geo_city_demand_2026_09.md §11): «Российские
 * бренды женской одежды» и т.п. — дословные формулировки запросов из Wordstat,
 * а НЕ «Бренды одежды для аудитории Женщины». slug уже существовал (DefaultFields)
 * и уже был заполнен человекочитаемыми значениями (female/male/kids/unisex/
 * maternity/mladency) — новых слагов эта миграция не заводит.
 *
 * Материнство/Младенцы (1 бренд на каждую на момент миграции — ниже
 * MIN_INDEXABLE_BRANDS) получают формулировку по аналогии, без опоры на Wordstat
 * (спрос по ним отдельно не замерялся) — страницы всё равно уйдут под noindex,
 * пока не наберётся брендов.
 */
final class Version20260907_brand_audience_hub extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_audience.h1 — формулировки H1 фасетных страниц аудитории';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_audience ADD h1 VARCHAR(255) DEFAULT NULL');

        $rows = [
            ['female', 'Российские бренды женской одежды'],
            ['male', 'Российские бренды мужской одежды'],
            ['kids', 'Российские бренды детской одежды'],
            ['unisex', 'Российские бренды одежды унисекс'],
            ['maternity', 'Российские бренды одежды для будущих и кормящих мам'],
            ['mladency', 'Российские бренды одежды для новорождённых'],
        ];
        foreach ($rows as [$slug, $h1]) {
            $this->addSql('UPDATE brand_audience SET h1 = :h1 WHERE slug = :slug', ['slug' => $slug, 'h1' => $h1]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_audience DROP COLUMN h1');
    }
}
