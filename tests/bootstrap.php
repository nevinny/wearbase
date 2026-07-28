<?php

use App\Entity\Currency;
use App\Entity\Language;
use App\Entity\Tariff;
use App\Kernel;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

/*
 * Тест-БД — это одноразовый SQLite-файл (var/test.db, см. .env.test), а НЕ MySQL.
 * Провижиним схему из ТЕКУЩИХ сущностей на каждый запуск phpunit: пересоздаём файл
 * и создаём таблицы через SchemaTool. Так схема всегда соответствует entity — не нужно
 * гонять миграции вручную и не бывает «протухшего» var/test.db (нет колонки origin_status и т.п.).
 * Плюс сидим минимум справочников (базовая валюта RUB + язык ru), иначе currency-global = null
 * и cart/checkout падают в 500.
 */
if (($_SERVER['APP_ENV'] ?? null) === 'test') {
    $kernel = new Kernel('test', (bool) ($_SERVER['APP_DEBUG'] ?? true));
    $kernel->boot();

    /** @var \Doctrine\ORM\EntityManagerInterface $em */
    $em = $kernel->getContainer()->get('doctrine.orm.entity_manager');
    $connection = $em->getConnection();

    // Защита: провижиним только SQLite, чтобы случайно не снести реальную БД.
    if ($connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SqlitePlatform) {
        $path = $connection->getParams()['path'] ?? null;
        if ($path !== null && is_file($path)) {
            $connection->close();
            @unlink($path); // свежий файл на каждый прогон
        }

        $metadata = $em->getMetadataFactory()->getAllMetadata();
        if ($metadata !== []) {
            (new SchemaTool($em))->createSchema($metadata);
        }

        // Таблицы без сущности (создаются сырыми миграциями) SchemaTool не видит —
        // мирроим их здесь. brand_related = граф перелинковки (Version20260612_brand_related).
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS brand_related (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                brand_id INTEGER NOT NULL,
                related_brand_id INTEGER NOT NULL,
                position SMALLINT NOT NULL,
                source VARCHAR(20) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL
            )
        SQL);

        // Находки тех-аудита с дельтой (Version20260728_seo_tech_finding).
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS seo_tech_finding (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                url VARCHAR(512) NOT NULL,
                rule VARCHAR(40) NOT NULL,
                detail VARCHAR(255) DEFAULT NULL,
                first_seen_on DATE NOT NULL,
                last_seen_on DATE NOT NULL,
                fixed_on DATE DEFAULT NULL
            )
        SQL);

        // Карта идемпотентности переноса гардероба (Version20260728_wardrobe_import_map).
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS wardrobe_import_map (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_fingerprint CHAR(64) NOT NULL,
                source_user_id INTEGER NOT NULL,
                source_item_id INTEGER NOT NULL,
                wardrobe_item_id INTEGER NOT NULL,
                imported_at DATETIME NOT NULL
            )
        SQL);

        // ── Минимальный сид справочников ──────────────────────────────────────
        if ($em->getRepository(Currency::class)->count([]) === 0) {
            $rub = (new Currency())
                ->setCode('RUB')
                ->setSymbol('₽')
                ->setNameRu('Российский рубль')
                ->setNameEn('Russian Ruble')
                ->setIsBase(true)
                ->setIsActive(true);
            $em->persist($rub);
        }

        if ($em->getRepository(Language::class)->count([]) === 0) {
            $ru = (new Language())
                ->setCode('ru')
                ->setNativeName('Русский')
                ->setNameRu('Русский')
                ->setIsActive(true)
                ->setIsDefault(true);
            $em->persist($ru);
        }

        // Free-тариф: без него регистрация бренда и app:brand:grant-access падают на
        // SubscriptionFactory (assert «Free tariff not found»). На проде его ставит миграция.
        if ($em->getRepository(Tariff::class)->count([]) === 0) {
            $free = (new Tariff())
                ->setName('Бесплатный')
                ->setCode(Tariff::CODE_FREE)
                ->setPriceRub('0.00')
                ->setTrialDays(30)
                ->setMaxProducts(10)
                ->setMaxImages(5)
                ->setHasAnalytics(false)
                ->setHasPriority(false)
                ->setIsActive(true);
            $em->persist($free);
        }

        $em->flush();
    }

    $kernel->shutdown();
}
