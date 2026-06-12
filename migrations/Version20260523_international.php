<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Международные справочники: язык, страна, город, валюта, курсы валют.
 *
 * Seed-данные включены для удобства развёртывания.
 * Приоритетные рынки WEARBASE: Россия, Казахстан, Беларусь, ОАЭ, Турция, Китай.
 *
 * Совместимость: MySQL 8.0+ (без синтаксиса VALUES ROW() — требует 8.0.19+).
 */
final class Version20260523_international extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add language, country, city, currency, exchange_rate tables with seed data';
    }

    public function up(Schema $schema): void
    {
        // ── language ─────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `language` (
                id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                code           VARCHAR(5)   NOT NULL COMMENT 'ISO 639-1: ru, en, zh…',
                native_name    VARCHAR(80)  NOT NULL COMMENT 'Название на родном языке',
                name_ru        VARCHAR(80)  NOT NULL COMMENT 'Название на русском',
                text_direction VARCHAR(3)   NOT NULL DEFAULT 'ltr' COMMENT 'ltr | rtl',
                is_active      TINYINT(1)   NOT NULL DEFAULT 1,
                is_default     TINYINT(1)   NOT NULL DEFAULT 0,
                sort_order     INT          NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_language_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ── currency ─────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS currency (
                id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
                code                VARCHAR(3)   NOT NULL COMMENT 'ISO 4217: RUB, USD, EUR…',
                symbol              VARCHAR(10)  NOT NULL,
                symbol_position     VARCHAR(6)   NOT NULL DEFAULT 'suffix' COMMENT 'prefix | suffix',
                name_ru             VARCHAR(80)  NOT NULL,
                name_en             VARCHAR(80)  NOT NULL,
                decimal_places      INT          NOT NULL DEFAULT 2,
                decimal_separator   VARCHAR(1)   NOT NULL DEFAULT '.',
                thousands_separator VARCHAR(2)   NOT NULL DEFAULT ' ',
                is_base             TINYINT(1)   NOT NULL DEFAULT 0,
                is_active           TINYINT(1)   NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                UNIQUE KEY uq_currency_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ── country ───────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS country (
                id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                code                 VARCHAR(2)   NOT NULL COMMENT 'ISO 3166-1 alpha-2',
                code3                VARCHAR(3)   DEFAULT NULL COMMENT 'ISO 3166-1 alpha-3',
                name_ru              VARCHAR(120) NOT NULL,
                name_en              VARCHAR(120) NOT NULL,
                phone_code           VARCHAR(10)  DEFAULT NULL,
                default_currency_id  INT UNSIGNED DEFAULT NULL,
                default_language_id  INT UNSIGNED DEFAULT NULL,
                region               VARCHAR(30)  DEFAULT NULL,
                flag_emoji           VARCHAR(10)  DEFAULT NULL,
                is_active            TINYINT(1)   NOT NULL DEFAULT 1,
                sort_order           INT          NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_country_code (code),
                KEY idx_country_currency (default_currency_id),
                KEY idx_country_language (default_language_id),
                CONSTRAINT fk_country_currency
                    FOREIGN KEY (default_currency_id) REFERENCES currency(id) ON DELETE SET NULL,
                CONSTRAINT fk_country_language
                    FOREIGN KEY (default_language_id) REFERENCES `language`(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ── city ──────────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS city (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                country_id  INT UNSIGNED NOT NULL,
                name_ru     VARCHAR(120) NOT NULL,
                name_en     VARCHAR(120) DEFAULT NULL,
                region      VARCHAR(120) DEFAULT NULL,
                latitude    DECIMAL(10,7) DEFAULT NULL,
                longitude   DECIMAL(10,7) DEFAULT NULL,
                population  INT          DEFAULT NULL,
                is_active   TINYINT(1)   NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                KEY idx_city_country  (country_id),
                KEY idx_city_name_ru  (name_ru),
                CONSTRAINT fk_city_country
                    FOREIGN KEY (country_id) REFERENCES country(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ── exchange_rate ─────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS exchange_rate (
                id                 INT UNSIGNED   NOT NULL AUTO_INCREMENT,
                base_currency_id   INT UNSIGNED   NOT NULL,
                target_currency_id INT UNSIGNED   NOT NULL,
                rate               DECIMAL(18, 8) NOT NULL COMMENT '1 base = rate target',
                rate_date          DATE           NOT NULL,
                source             VARCHAR(30)    NOT NULL DEFAULT 'manual',
                updated_at         DATETIME       NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_exchange_rate_pair_date (base_currency_id, target_currency_id, rate_date),
                KEY idx_exchange_rate_date (rate_date),
                CONSTRAINT fk_exchange_rate_base
                    FOREIGN KEY (base_currency_id)   REFERENCES currency(id) ON DELETE CASCADE,
                CONSTRAINT fk_exchange_rate_target
                    FOREIGN KEY (target_currency_id) REFERENCES currency(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ════════════════════════════════════════════════════════════════════
        // SEED DATA
        // ════════════════════════════════════════════════════════════════════

        // ── Языки ─────────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO `language`
                (code, native_name, name_ru, text_direction, is_active, is_default, sort_order)
            VALUES
                ('ru', 'Русский',    'Русский',     'ltr', 1, 1,  0),
                ('en', 'English',    'Английский',  'ltr', 1, 0,  1),
                ('zh', '中文',       'Китайский',   'ltr', 1, 0, 10),
                ('ar', 'العربية',    'Арабский',    'rtl', 1, 0, 11),
                ('tr', 'Türkçe',     'Турецкий',    'ltr', 1, 0, 12),
                ('de', 'Deutsch',    'Немецкий',    'ltr', 1, 0, 20),
                ('fr', 'Français',   'Французский', 'ltr', 1, 0, 21),
                ('kk', 'Қазақша',   'Казахский',   'ltr', 1, 0,  5),
                ('be', 'Беларуская', 'Белорусский', 'ltr', 1, 0,  6)
        SQL);

        // ── Валюты ────────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO currency
                (code, symbol, symbol_position, name_ru, name_en,
                 decimal_places, decimal_separator, thousands_separator, is_base, is_active)
            VALUES
                ('RUB', '₽',  'suffix', 'Российский рубль',    'Russian Ruble',     2, '.', ' ', 1, 1),
                ('USD', '$',  'prefix', 'Доллар США',           'US Dollar',         2, '.', ',', 0, 1),
                ('EUR', '€',  'suffix', 'Евро',                 'Euro',              2, '.', ' ', 0, 1),
                ('CNY', '¥',  'prefix', 'Китайский юань',       'Chinese Yuan',      2, '.', ',', 0, 1),
                ('AED', 'AED','suffix', 'Дирхам ОАЭ',           'UAE Dirham',        2, '.', ',', 0, 1),
                ('TRY', '₺',  'prefix', 'Турецкая лира',        'Turkish Lira',      2, '.', ',', 0, 1),
                ('KZT', '₸',  'suffix', 'Казахстанский тенге',  'Kazakhstani Tenge', 0, '.', ' ', 0, 1),
                ('BYN', 'Br', 'suffix', 'Белорусский рубль',    'Belarusian Ruble',  2, '.', ' ', 0, 1),
                ('GBP', '£',  'prefix', 'Британский фунт',      'British Pound',     2, '.', ',', 0, 1),
                ('JPY', '¥',  'prefix', 'Японская иена',        'Japanese Yen',      0, '.', ',', 0, 1),
                ('CHF', 'Fr', 'suffix', 'Швейцарский франк',    'Swiss Franc',       2, '.', ' ', 0, 1)
        SQL);

        // ── Страны ────────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO country
                (code, code3, name_ru, name_en, phone_code, region, flag_emoji, is_active, sort_order)
            VALUES
                ('RU','RUS','Россия',         'Russia',               '+7',   'europe',      '🇷🇺', 1,  0),
                ('KZ','KAZ','Казахстан',      'Kazakhstan',           '+7',   'asia',        '🇰🇿', 1,  1),
                ('BY','BLR','Беларусь',       'Belarus',              '+375', 'europe',      '🇧🇾', 1,  2),
                ('AE','ARE','ОАЭ',            'United Arab Emirates', '+971', 'middle_east', '🇦🇪', 1,  3),
                ('TR','TUR','Турция',         'Turkey',               '+90',  'europe',      '🇹🇷', 1,  4),
                ('CN','CHN','Китай',          'China',                '+86',  'asia',        '🇨🇳', 1,  5),
                ('US','USA','США',            'United States',        '+1',   'americas',    '🇺🇸', 1,  6),
                ('DE','DEU','Германия',       'Germany',              '+49',  'europe',      '🇩🇪', 1,  7),
                ('GB','GBR','Великобритания', 'United Kingdom',       '+44',  'europe',      '🇬🇧', 1,  8),
                ('FR','FRA','Франция',        'France',               '+33',  'europe',      '🇫🇷', 1,  9),
                ('JP','JPN','Япония',         'Japan',                '+81',  'asia',        '🇯🇵', 1, 10),
                ('AM','ARM','Армения',        'Armenia',              '+374', 'asia',        '🇦🇲', 1, 11),
                ('GE','GEO','Грузия',         'Georgia',              '+995', 'asia',        '🇬🇪', 1, 12),
                ('UZ','UZB','Узбекистан',     'Uzbekistan',           '+998', 'asia',        '🇺🇿', 1, 13),
                ('KG','KGZ','Кыргызстан',     'Kyrgyzstan',           '+996', 'asia',        '🇰🇬', 1, 14)
        SQL);

        // Привязываем дефолтные валюты к странам
        $this->addSql(<<<'SQL'
            UPDATE country c JOIN currency cur ON cur.code = 'RUB' SET c.default_currency_id = cur.id WHERE c.code = 'RU'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE country c JOIN currency cur ON cur.code = 'KZT' SET c.default_currency_id = cur.id WHERE c.code = 'KZ'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE country c JOIN currency cur ON cur.code = 'BYN' SET c.default_currency_id = cur.id WHERE c.code = 'BY'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE country c JOIN currency cur ON cur.code = 'AED' SET c.default_currency_id = cur.id WHERE c.code = 'AE'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE country c JOIN currency cur ON cur.code = 'TRY' SET c.default_currency_id = cur.id WHERE c.code = 'TR'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE country c JOIN currency cur ON cur.code = 'CNY' SET c.default_currency_id = cur.id WHERE c.code = 'CN'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE country c JOIN currency cur ON cur.code = 'USD' SET c.default_currency_id = cur.id WHERE c.code = 'US'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE country c JOIN currency cur ON cur.code = 'EUR' SET c.default_currency_id = cur.id WHERE c.code IN ('DE','FR')
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE country c JOIN currency cur ON cur.code = 'GBP' SET c.default_currency_id = cur.id WHERE c.code = 'GB'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE country c JOIN currency cur ON cur.code = 'JPY' SET c.default_currency_id = cur.id WHERE c.code = 'JP'
        SQL);

        // Привязываем дефолтные языки к странам
        $this->addSql(<<<'SQL'
            UPDATE country c JOIN `language` l ON l.code = 'ru' SET c.default_language_id = l.id WHERE c.code IN ('RU','AM','UZ','KG')
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE country c JOIN `language` l ON l.code = 'kk' SET c.default_language_id = l.id WHERE c.code = 'KZ'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE country c JOIN `language` l ON l.code = 'be' SET c.default_language_id = l.id WHERE c.code = 'BY'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE country c JOIN `language` l ON l.code = 'ar' SET c.default_language_id = l.id WHERE c.code = 'AE'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE country c JOIN `language` l ON l.code = 'tr' SET c.default_language_id = l.id WHERE c.code = 'TR'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE country c JOIN `language` l ON l.code = 'zh' SET c.default_language_id = l.id WHERE c.code = 'CN'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE country c JOIN `language` l ON l.code = 'en' SET c.default_language_id = l.id WHERE c.code IN ('US','GB','GE')
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE country c JOIN `language` l ON l.code = 'de' SET c.default_language_id = l.id WHERE c.code = 'DE'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE country c JOIN `language` l ON l.code = 'fr' SET c.default_language_id = l.id WHERE c.code = 'FR'
        SQL);

        // ── Города России (один INSERT per SELECT для совместимости MySQL 8.0+) ──
        $cities_ru = [
            ['Москва',          'Moscow',           'Москва',         55.7558,  37.6173, 12506468],
            ['Санкт-Петербург', 'Saint Petersburg', 'Ленинградская',  59.9311,  30.3609,  5601000],
            ['Новосибирск',     'Novosibirsk',      'Новосибирская',  54.9884,  82.9357,  1625631],
            ['Екатеринбург',    'Yekaterinburg',    'Свердловская',   56.8389,  60.6057,  1495066],
            ['Казань',          'Kazan',            'Татарстан',      55.8304,  49.0661,  1257391],
            ['Нижний Новгород', 'Nizhny Novgorod',  'Нижегородская',  56.2965,  43.9361,  1244254],
            ['Челябинск',       'Chelyabinsk',      'Челябинская',    55.1644,  61.4368,  1156919],
            ['Самара',          'Samara',           'Самарская',      53.2028,  50.1408,  1163399],
            ['Уфа',             'Ufa',              'Башкортостан',   54.7388,  55.9721,  1128787],
            ['Ростов-на-Дону',  'Rostov-on-Don',    'Ростовская',     47.2357,  39.7015,  1125299],
            ['Пермь',           'Perm',             'Пермская',       58.0105,  56.2502,  1048005],
            ['Воронеж',         'Voronezh',         'Воронежская',    51.6615,  39.2003,  1057681],
            ['Красноярск',      'Krasnoyarsk',      'Красноярская',   56.0184,  92.8671,  1090811],
            ['Омск',            'Omsk',             'Омская',         54.9885,  73.3242,  1154116],
            ['Волгоград',       'Volgograd',        'Волгоградская',  48.7194,  44.5018,  1013533],
            ['Краснодар',       'Krasnodar',        'Краснодарский',  45.0448,  38.9760,  1064048],
            ['Тюмень',          'Tyumen',           'Тюменская',      57.1522,  68.0077,   822000],
            ['Саратов',         'Saratov',          'Саратовская',    51.5328,  46.0344,   845000],
            ['Тольятти',        'Togliatti',        'Самарская',      53.5303,  49.3461,   695564],
            ['Барнаул',         'Barnaul',          'Алтайская',      53.3606,  83.7636,   632808],
            ['Ижевск',          'Izhevsk',          'Удмуртия',       56.8526,  53.2048,   648592],
            ['Ульяновск',       'Ulyanovsk',        'Ульяновская',    54.3282,  48.3869,   626041],
            ['Хабаровск',       'Khabarovsk',       'Хабаровская',    48.4827, 135.0840,   619600],
            ['Ярославль',       'Yaroslavl',        'Ярославская',    57.6261,  39.8845,   603781],
            ['Владивосток',     'Vladivostok',      'Приморская',     43.1332, 131.9113,   600871],
            ['Иркутск',         'Irkutsk',          'Иркутская',      52.2978, 104.2964,   617395],
            ['Махачкала',       'Makhachkala',      'Дагестан',       42.9849,  47.5047,   601045],
            ['Новокузнецк',     'Novokuznetsk',     'Кемеровская',    53.7596,  87.1216,   549399],
            ['Сочи',            'Sochi',            'Краснодарский',  43.6028,  39.7342,   443562],
            ['Калининград',     'Kaliningrad',      'Калининградская', 54.7104,  20.4522,   489359],
        ];

        foreach ($cities_ru as $c) {
            $this->addSql(sprintf(
                "INSERT IGNORE INTO city (country_id, name_ru, name_en, region, latitude, longitude, population, is_active)
                 SELECT id, %s, %s, %s, %s, %s, %d, 1 FROM country WHERE code = 'RU'",
                $this->quoteStr($c[0]), $this->quoteStr($c[1]), $this->quoteStr($c[2]),
                $c[3], $c[4], (int) $c[5]
            ));
        }

        // ── Города Казахстана ─────────────────────────────────────────────────
        $cities_kz = [
            ['Алматы',   'Almaty',    'Алматинская',    43.2220, 76.8512, 1977011],
            ['Астана',   'Astana',    'Акмолинская',    51.1801, 71.4460, 1136008],
            ['Шымкент',  'Shymkent',  'Туркестанская',  42.3417, 69.5901, 1002000],
            ['Актобе',   'Aktobe',    'Актюбинская',    50.2839, 57.1669,  475000],
            ['Карагандa','Karaganda', 'Карагандинская', 49.8047, 73.1094,  500000],
            ['Тараз',    'Taraz',     'Жамбылская',     42.9000, 71.3667,  360000],
        ];

        foreach ($cities_kz as $c) {
            $this->addSql(sprintf(
                "INSERT IGNORE INTO city (country_id, name_ru, name_en, region, latitude, longitude, population, is_active)
                 SELECT id, %s, %s, %s, %s, %s, %d, 1 FROM country WHERE code = 'KZ'",
                $this->quoteStr($c[0]), $this->quoteStr($c[1]), $this->quoteStr($c[2]),
                $c[3], $c[4], (int) $c[5]
            ));
        }

        // ── Города ОАЭ ────────────────────────────────────────────────────────
        $cities_ae = [
            ['Дубай',         'Dubai',         'Дубай',   25.2048, 55.2708, 3331420],
            ['Абу-Даби',      'Abu Dhabi',      'Абу-Даби',24.4539, 54.3773, 1483000],
            ['Шарджа',        'Sharjah',        'Шарджа',  25.3462, 55.4211, 1400000],
            ['Аджман',        'Ajman',          'Аджман',  25.4052, 55.5136,  490000],
            ['Рас-эль-Хайма', 'Ras Al Khaimah', 'РАК',     25.7943, 55.9757,  350000],
        ];

        foreach ($cities_ae as $c) {
            $this->addSql(sprintf(
                "INSERT IGNORE INTO city (country_id, name_ru, name_en, region, latitude, longitude, population, is_active)
                 SELECT id, %s, %s, %s, %s, %s, %d, 1 FROM country WHERE code = 'AE'",
                $this->quoteStr($c[0]), $this->quoteStr($c[1]), $this->quoteStr($c[2]),
                $c[3], $c[4], (int) $c[5]
            ));
        }

        // ── Стартовые курсы валют ─────────────────────────────────────────────
        $rates = [
            ['USD', 0.01083],
            ['EUR', 0.01003],
            ['CNY', 0.07810],
            ['AED', 0.03978],
            ['TRY', 0.36100],
            ['KZT', 5.23000],
            ['BYN', 0.03540],
            ['GBP', 0.00860],
            ['JPY', 1.63500],
            ['CHF', 0.00966],
        ];

        foreach ($rates as [$targetCode, $rate]) {
            $this->addSql(sprintf(
                "INSERT IGNORE INTO exchange_rate
                     (base_currency_id, target_currency_id, rate, rate_date, source, updated_at)
                 SELECT b.id, t.id, %s, CURDATE(), 'seed', NOW()
                 FROM currency b, currency t
                 WHERE b.code = 'RUB' AND t.code = %s",
                $rate,
                $this->quoteStr($targetCode)
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS=0');
        $this->addSql('DROP TABLE IF EXISTS exchange_rate');
        $this->addSql('DROP TABLE IF EXISTS city');
        $this->addSql('DROP TABLE IF EXISTS country');
        $this->addSql('DROP TABLE IF EXISTS currency');
        $this->addSql('DROP TABLE IF EXISTS `language`');
        $this->addSql('SET FOREIGN_KEY_CHECKS=1');
    }

    /** Экранирует строку для вставки в SQL (простые одинарные кавычки). */
    private function quoteStr(string $s): string
    {
        return "'" . str_replace("'", "''", $s) . "'";
    }
}
