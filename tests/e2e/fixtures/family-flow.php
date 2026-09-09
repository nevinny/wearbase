<?php

declare(strict_types=1);

use App\Entity\User;
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
$foreign = $createUser("e2e-foreign-{$runId}@test.local", 'Посторонний');
$invite = $families->createInvite($parent, User::FAMILY_ROLE_CHILD);

$fixture = [
    'password' => $password,
    'parent' => ['email' => $parent->getEmail(), 'id' => $parent->getId()],
    'child' => ['email' => $child->getEmail(), 'id' => $child->getId()],
    'foreign' => ['email' => $foreign->getEmail(), 'id' => $foreign->getId()],
    'invitePath' => '/family/invite/'.$invite->getToken(),
];

$target = dirname(__DIR__, 3).'/var/e2e-family-fixture.json';
file_put_contents($target, json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

$kernel->shutdown();
