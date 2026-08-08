<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\FamilyInvite;
use App\Entity\User;
use App\Service\FamilyService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Integration tests for "Моя семья": /account/family/*, /family/claim/{token},
 * /family/invite/{token}.
 *
 * Run with: php bin/phpunit tests/Controller/FamilyControllerTest.php
 *
 * Каждый тест использует СВОЙ выделенный email (через UserFactory::withEmail),
 * а не общий harness-customer — иначе тесты, зависящие от «семьи ещё нет»,
 * ломались бы порядком выполнения (тест-БД общая на весь прогон phpunit).
 */
class FamilyControllerTest extends AuthenticatedWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account/family');

        $this->assertResponseRedirects('/login', 302);
    }

    public function testIndexShowsEmptyStateForUserWithoutFamily(): void
    {
        $client = static::createClient();
        $user   = UserFactory::withEmail(static::getContainer(), 'harness-family-empty@test.local');
        $client->loginUser($user);

        $client->request('GET', '/account/family');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Добавьте');
    }

    public function testAddChildCreatesFamilyAndChild(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'harness-family-addchild@test.local');
        $client->loginUser($parent);

        $crawler = $client->request('GET', '/account/family/add');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Сохранить')->form([
            'family_child_form[firstName]' => 'Маша',
        ]);
        $client->submit($form);

        $this->assertResponseRedirects('/account/family');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();

        /** @var User $reloadedParent */
        $reloadedParent = $em->getRepository(User::class)->find($parent->getId());
        $this->assertNotNull($reloadedParent->getFamily());
        $this->assertSame(User::FAMILY_ROLE_PARENT, $reloadedParent->getFamilyRole());

        $child = $em->getRepository(User::class)->findOneBy(['firstName' => 'Маша', 'family' => $reloadedParent->getFamily()]);
        $this->assertNotNull($child);
        $this->assertStringEndsWith('@' . User::MANAGED_EMAIL_DOMAIN, $child->getEmail());
        $this->assertSame(User::FAMILY_ROLE_CHILD, $child->getFamilyRole());
        $this->assertTrue($child->isManaged());

        $client->request('GET', '/account/family');
        $this->assertSelectorTextContains('body', 'Маша');
        $this->assertSelectorExists('input#claim-url-' . $child->getId());
    }

    public function testInviteCreatesPendingInvite(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'harness-family-invite@test.local');
        $client->loginUser($parent);

        $crawler = $client->request('GET', '/account/family');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Пригласить в семью')->form([
            'role' => User::FAMILY_ROLE_PARENT,
        ]);
        $client->submit($form);

        $this->assertResponseRedirects('/account/family');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();

        /** @var User $reloadedParent */
        $reloadedParent = $em->getRepository(User::class)->find($parent->getId());
        $this->assertNotNull($reloadedParent->getFamily());

        $invite = $em->getRepository(FamilyInvite::class)->findOneBy(['family' => $reloadedParent->getFamily()]);
        $this->assertNotNull($invite);
        $this->assertFalse($invite->isAccepted());
        $this->assertSame(User::FAMILY_ROLE_PARENT, $invite->getRole());

        $crawler = $client->request('GET', '/account/family');
        $this->assertStringContainsString(
            '/family/invite/' . $invite->getToken(),
            $crawler->filter('input[readonly][id^="invite-url-"]')->attr('value'),
        );
    }

    public function testChildCannotSeeOrCreateFamilyManagementActions(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'harness-family-child-rights-parent@test.local');

        /** @var FamilyService $familyService */
        $familyService = static::getContainer()->get(FamilyService::class);
        $child = $familyService->createChild($parent, 'Лена');
        $client->loginUser($child);

        $client->request('GET', '/account/family');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('a[href="/account/family/add"]');
        $this->assertSelectorNotExists('form[action="/account/family/invite"]');

        $client->request('POST', '/account/family/invite', [
            'role' => User::FAMILY_ROLE_PARENT,
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAcceptInviteJoinsFamily(): void
    {
        $client = static::createClient();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $parent = UserFactory::withEmail(static::getContainer(), 'harness-family-inviteaccept-parent@test.local');
        /** @var FamilyService $familyService */
        $familyService = static::getContainer()->get(FamilyService::class);
        $invite = $familyService->createInvite($parent, User::FAMILY_ROLE_PARENT);
        $token  = $invite->getToken();

        $invitee = UserFactory::withEmail(static::getContainer(), 'harness-family-invitee@test.local');
        $this->assertNull($invitee->getFamily());

        $client->loginUser($invitee);

        $crawler = $client->request('GET', '/family/invite/' . $token);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Принять приглашение');

        $form = $crawler->selectButton('Принять приглашение')->form();
        $client->submit($form);

        $this->assertResponseRedirects('/account/family');

        $em->clear();
        /** @var User $reloadedInvitee */
        $reloadedInvitee = $em->getRepository(User::class)->find($invitee->getId());
        /** @var User $reloadedParent */
        $reloadedParent = $em->getRepository(User::class)->find($parent->getId());

        $this->assertNotNull($reloadedInvitee->getFamily());
        $this->assertSame($reloadedParent->getFamily()->getId(), $reloadedInvitee->getFamily()->getId());
        $this->assertSame(User::FAMILY_ROLE_PARENT, $reloadedInvitee->getFamilyRole());

        /** @var FamilyInvite $reloadedInvite */
        $reloadedInvite = $em->getRepository(FamilyInvite::class)->findOneBy(['token' => $token]);
        $this->assertNotNull($reloadedInvite->getAcceptedAt());

        $client->request('GET', '/family/invite/' . $token);
        $this->assertResponseStatusCodeSame(410);
    }

    public function testClaimActivatesManagedChild(): void
    {
        $client = static::createClient();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        /** @var FamilyService $familyService */
        $familyService = static::getContainer()->get(FamilyService::class);

        $parent = UserFactory::withEmail(static::getContainer(), 'harness-family-claim-parent@test.local');
        $child  = $familyService->createChild($parent, 'Петя');
        $token  = $child->getFamilyClaimToken();
        $this->assertNotNull($token);

        $crawler = $client->request('GET', '/family/claim/' . $token);
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Создать доступ')->form([
            'form[email]'    => 'new-petya@example.com',
            'form[password]' => 'Password123',
        ]);
        $client->submit($form);

        $this->assertResponseRedirects('/login');

        $em->clear();
        /** @var User $reloadedChild */
        $reloadedChild = $em->getRepository(User::class)->find($child->getId());

        $this->assertSame('new-petya@example.com', $reloadedChild->getEmail());
        $this->assertNotNull($reloadedChild->getClaimedAt());
        $this->assertNull($reloadedChild->getFamilyClaimToken());
        $this->assertFalse($reloadedChild->isManaged());
    }

    public function testClaimInvalidTokenReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/family/claim/does-not-exist-token');

        $this->assertResponseStatusCodeSame(404);
    }
}
