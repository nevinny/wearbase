<?php

declare(strict_types=1);

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Kernel;
use App\Service\FamilyService;
use App\Service\PurchaseRequestService;
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
$password = 'E2e-lifecycle-2026';
$runId = bin2hex(random_bytes(5));

$newUser = static function (string $key, string $name) use ($em, $hasher, $password, $runId): User {
    $user = (new User())->setEmail("e2e-life-{$key}-{$runId}@test.local")->setFirstName($name)->setRoles(['ROLE_CUSTOMER']);
    $user->setPassword($hasher->hashPassword($user, $password));
    $em->persist($user);
    $em->flush();
    return $user;
};
$parent = $newUser('parent', 'Анна');
$child = $newUser('child', 'Мира');
$child->setBirthDate(new \DateTimeImmutable('-18 years -1 day'));
$families->acceptInvite($child, $families->createInvite($parent, User::FAMILY_ROLE_CHILD, $child->getEmail()));
$spouse = $newUser('spouse', 'Илья');
$spouseInvite = $families->createInvite($parent, User::FAMILY_ROLE_PARENT, $spouse->getEmail());
$foreign = $newUser('foreign', 'Чужой');

$addItem = static function (User $owner, int $number, string $name, ?string $price = null) use ($em): WardrobeItem {
    $item = (new WardrobeItem())->setUser($owner)->setOriginalOwner($owner)->setItemNo($number)->setName($name)->setCategory('Тест')->setPrice($price);
    $em->persist($item);
    return $item;
};
$items = [
    'care' => $addItem($parent, 1, 'E2E пальто', '12000.00'),
    'internal' => $addItem($parent, 2, 'E2E свитер для передачи'),
    'external' => $addItem($parent, 3, 'E2E куртка наружу'),
    'wearTop' => $addItem($parent, 4, 'E2E белая футболка', '1000.00'),
    'wearBottom' => $addItem($parent, 5, 'E2E синие джинсы', '3000.00'),
];
$em->flush();
$container->get(WardrobeOnboardingService::class)->complete($parent, $parent);

/** @var PurchaseRequestService $purchases */
$purchases = $container->get(PurchaseRequestService::class);
$purchase = $purchases->create($child, $child, 'https://shop.example.test/e2e-dress', 'Платье для E2E', '2500.00');
$purchases->decide($parent, $purchase, 'approved', 'Заказываем');

$fixture = [
    'password' => $password,
    'parent' => ['email' => $parent->getEmail(), 'id' => $parent->getId()],
    'child' => ['email' => $child->getEmail(), 'id' => $child->getId()],
    'spouse' => ['email' => $spouse->getEmail(), 'id' => $spouse->getId()],
    'foreign' => ['email' => $foreign->getEmail(), 'id' => $foreign->getId()],
    'spouseInvitePath' => '/family/invite/'.$spouseInvite->getToken(),
    'items' => array_map(static fn (WardrobeItem $item): int => (int) $item->getId(), $items),
    'purchase' => ['id' => $purchase->getId(), 'itemId' => $purchase->getItems()->first()->getId()],
];
file_put_contents(dirname(__DIR__, 3).'/var/e2e-wardrobe-lifecycle-fixture.json', json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
$kernel->shutdown();
