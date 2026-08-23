<?php

declare(strict_types=1);

use App\Entity\User;
use App\Entity\WardrobeItemDraft;
use App\Kernel;
use App\Service\FamilyService;
use App\Service\Wardrobe\WardrobeOnboardingService;
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
/** @var WardrobeOnboardingService $onboarding */
$onboarding = $container->get(WardrobeOnboardingService::class);

$password = 'E2e-onboarding-2026';
$runId = bin2hex(random_bytes(5));

$createUser = static function (string $key, string $name) use ($em, $hasher, $password, $runId): User {
    $user = (new User())
        ->setEmail("e2e-onboarding-{$key}-{$runId}@test.local")
        ->setFirstName($name)
        ->setRoles(['ROLE_CUSTOMER']);
    $user->setPassword($hasher->hashPassword($user, $password));
    $em->persist($user);
    $em->flush();

    return $user;
};

$intro = $createUser('intro', 'Ирина');
$skip = $createUser('skip', 'Ольга');
$parent = $createUser('parent', 'Анна');
$child = $families->createChild($parent, 'Лиза');
$child->setPassword($hasher->hashPassword($child, $password));
$foreign = $createUser('foreign', 'Посторонний');

$batchParent = $createUser('batch-parent', 'Мария');
$batchChild = $families->createChild($batchParent, 'Соня');
$batchId = '11111111-1111-4111-8111-111111111111';
$draft = (new WardrobeItemDraft())
    ->setProfileSubject($batchChild)
    ->setActor($batchParent)
    ->setBatchId($batchId)
    ->setStatus(WardrobeItemDraft::STATUS_RECOGNIZED)
    ->setConfidence('high')
    ->setCategory('Рубашки')
    ->setName('Белая рубашка')
    ->setSize('158');
$em->persist($draft);
$em->flush();
$onboarding->startBatch($batchParent, $batchChild, $batchId);

$fixture = [
    'password' => $password,
    'intro' => ['email' => $intro->getEmail(), 'id' => $intro->getId()],
    'skip' => ['email' => $skip->getEmail(), 'id' => $skip->getId()],
    'parent' => ['email' => $parent->getEmail(), 'id' => $parent->getId()],
    'child' => ['email' => $child->getEmail(), 'id' => $child->getId()],
    'foreign' => ['email' => $foreign->getEmail(), 'id' => $foreign->getId()],
    'batchParent' => ['email' => $batchParent->getEmail(), 'id' => $batchParent->getId()],
    'batchChild' => ['email' => $batchChild->getEmail(), 'id' => $batchChild->getId()],
    'batch' => ['id' => $batchId, 'draftId' => $draft->getId()],
];

file_put_contents(
    dirname(__DIR__, 3).'/var/e2e-wardrobe-onboarding-fixture.json',
    json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
);

$kernel->shutdown();
