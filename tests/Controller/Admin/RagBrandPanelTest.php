<?php

namespace App\Tests\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Проверяет, что кастомные RAG-страницы админки рендерятся с EA-layout:
 * AdminContext строится вручную (initAdminContext), иначе ea() = null и
 * layout падает на ea.i18n.
 */
class RagBrandPanelTest extends WebTestCase
{
    private function adminClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $email = 'rag-admin-test@example.com';
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

    public function testBrandSearchPageRenders(): void
    {
        $client = $this->adminClient();
        $client->request('GET', '/admin/rag/brand');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form input[name="q"]');
    }
}
