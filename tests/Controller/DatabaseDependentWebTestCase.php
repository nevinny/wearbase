<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * База для функциональных тестов, которым нужна тест-БД (`*_test`, см. doctrine.yaml).
 *
 * Один раз на класс проверяет доступность БД. Тест, которому БД нужна, вызывает
 * skipIfNoDatabase() в начале — без локальной _test-базы он мягко скипается, а не
 * валит suite. Тесты уровня security (гостевые редиректы) БД не требуют и guard не зовут.
 *
 * Setup:
 *   php bin/console doctrine:database:create --env=test
 *   php bin/console doctrine:migrations:migrate --env=test --no-interaction
 */
abstract class DatabaseDependentWebTestCase extends WebTestCase
{
    private static bool $dbAvailable = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        try {
            $em = static::createClient()->getContainer()->get('doctrine.orm.entity_manager');
            $em->getConnection()->executeQuery('SELECT 1');
            static::$dbAvailable = true;
        } catch (\Throwable) {
            static::$dbAvailable = false;
        } finally {
            // Проба бутит kernel; гасим его, иначе первый createClient() в тесте
            // упадёт с "the kernel should only be booted once".
            static::ensureKernelShutdown();
        }
    }

    protected function skipIfNoDatabase(): void
    {
        if (!static::$dbAvailable) {
            $this->markTestSkipped('Test database not available. Run: bin/console doctrine:database:create --env=test');
        }
    }
}
