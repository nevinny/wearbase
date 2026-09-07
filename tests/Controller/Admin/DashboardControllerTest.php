<?php

namespace App\Tests\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Главная страница /admin: сводка-дашборд с плитками (модерация, публикации, RAG-конвейер,
 * качество каталога, свежесть данных, closed-loop, гео-хабы, деньги/подписки).
 *
 * Тест-БД (var/test.db, SQLite, схема из сущностей — см. docs/testing.md) НЕ содержит
 * gsc_page_stats/yandex_index_status/city_hub_revision (только entity-таблицы + мирроринг
 * brand_related) — это ЕСТЬ симуляция «молодой таблицы отсутствует» из чек-листа приёмки:
 * страница обязана остаться 200, а плитки «свежесть данных»/«closed-loop» — показать «—»
 * вместо падения. Отдельно DROP TABLE не делаем: схема одна на весь прогон phpunit
 * (tests/bootstrap.php создаёт её один раз), и другие тесты её переиспользуют.
 */
class DashboardControllerTest extends WebTestCase
{
    private function adminClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $email = 'dashboard-admin-test@example.com';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($user === null) {
            $user = (new User())
                ->setEmail($email)
                ->setRoles(['ROLE_ADMIN'])
                ->setPassword('test-not-used');
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user, 'admin');
        return $client;
    }

    public function testDashboardRendersWithTiles(): void
    {
        $client = $this->adminClient();
        $client->request('GET', '/admin');
        $this->assertResponseIsSuccessful();

        $this->assertSelectorTextContains('body', 'Модерация и заявки');
        $this->assertSelectorTextContains('body', 'Публикации');
        $this->assertSelectorTextContains('body', 'RAG-конвейер');
        $this->assertSelectorTextContains('body', 'Качество каталога');
        $this->assertSelectorTextContains('body', 'Свежесть данных');
        $this->assertSelectorTextContains('body', 'Closed-loop');
        $this->assertSelectorTextContains('body', 'Гео-хабы');
        $this->assertSelectorTextContains('body', 'Деньги/подписки');
    }

    /**
     * Устойчивость: gsc_page_stats/yandex_index_status/city_hub_revision не существуют
     * в тест-БД (SQLite-схема строится только из entity + brand_related, см. docblock
     * класса) — плитки, которые от них зависят, обязаны деградировать в «—»/пусто, а не
     * уронить страницу 500-й.
     */
    public function testFreshnessAndClosedLoopTilesDegradeGracefullyWithoutYoungTables(): void
    {
        $client = $this->adminClient();
        $client->request('GET', '/admin');
        $this->assertResponseIsSuccessful();

        $this->assertSelectorTextContains('body', 'нет данных'); // GSC/Яндекс — таблиц нет
    }
}

