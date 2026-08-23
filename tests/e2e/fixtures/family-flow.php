<?php

declare(strict_types=1);

use App\Entity\User;
use App\Entity\Notification;
use App\Kernel;
use App\Service\FamilyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

require dirname(__DIR__, 3).'/tests/bootstrap.php';

$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');

/** @var EntityManagerInterface $em */
$em = $container->get('doctrine.orm.entity_manager');
/** @var UserPasswordHasherInterface $hasher */
$hasher = $container->get(UserPasswordHasherInterface::class);
/** @var FamilyService $families */
$families = $container->get(FamilyService::class);

$runId = bin2hex(random_bytes(5));
$password = 'E2e-family-2026';

$createUser = static function (string $email, string $firstName) use ($em, $hasher, $password): User {
    $user = (new User())
        ->setEmail($email)
        ->setFirstName($firstName)
        ->setRoles(['ROLE_CUSTOMER']);
    $user->setPassword($hasher->hashPassword($user, $password));
    $em->persist($user);
    $em->flush();

    return $user;
};

$parent = $createUser("e2e-parent-{$runId}@test.local", 'Елена');
$child = $createUser("e2e-child-{$runId}@test.local", 'Алиса');
$wardrobeChild = $createUser("e2e-wardrobe-child-{$runId}@test.local", 'Соня');
$invitedChild = $createUser("e2e-invited-child-{$runId}@test.local", 'Маша');
$spouse = $createUser("e2e-spouse-{$runId}@test.local", 'Алексей');
$foreign = $createUser("e2e-foreign-{$runId}@test.local", 'Посторонний');
$invite = $families->createInvite($parent, User::FAMILY_ROLE_CHILD);
$wardrobeInvite = $families->createInvite($parent, User::FAMILY_ROLE_CHILD);
$families->acceptInvite($wardrobeChild, $wardrobeInvite);

foreach (['E2E: проверьте семейный гардероб', 'E2E: второе непрочитанное уведомление'] as $title) {
    $notification = (new Notification())
        ->setRecipient($parent)
        ->setType(Notification::TYPE_SYSTEM)
        ->setTitle($title)
        ->setChannel(Notification::CHANNEL_INAPP);
    $em->persist($notification);
}
$em->flush();

$fixture = [
    'password' => $password,
    'parent' => ['email' => $parent->getEmail(), 'id' => $parent->getId()],
    'child' => ['email' => $child->getEmail(), 'id' => $child->getId()],
    'wardrobeChild' => ['email' => $wardrobeChild->getEmail(), 'id' => $wardrobeChild->getId()],
    'invitedChild' => ['email' => $invitedChild->getEmail(), 'id' => $invitedChild->getId()],
    'spouse' => ['email' => $spouse->getEmail(), 'id' => $spouse->getId()],
    'foreign' => ['email' => $foreign->getEmail(), 'id' => $foreign->getId()],
    'invitePath' => '/family/invite/'.$invite->getToken(),
];

$target = dirname(__DIR__, 3).'/var/e2e-family-fixture.json';
file_put_contents($target, json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

$kernel->shutdown();
