<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Entity\WardrobeActivationEvent;
use App\Repository\WardrobeActivationEventRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class WardrobeActivationEventRepositoryTest extends TestCase
{
    public function testRecordOnceIsIdempotent(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE wardrobe_activation_event (id INTEGER PRIMARY KEY AUTOINCREMENT, profile_subject_id INTEGER NOT NULL, event_type VARCHAR(32) NOT NULL, dedup_key VARCHAR(64) NOT NULL, metadata JSON NOT NULL, occurred_at DATETIME NOT NULL, UNIQUE (profile_subject_id, event_type, dedup_key))');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn(new ClassMetadata(WardrobeActivationEvent::class));
        $em->method('getConnection')->willReturn($connection);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $repository = new WardrobeActivationEventRepository($registry);
        $subject = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($subject, 42);
        $metadata = ['actorKind' => 'self', 'entryPoint' => 'manual'];

        self::assertTrue($repository->recordOnce($subject, WardrobeActivationEvent::FIRST_ITEM_ADDED, 'first_item_added', $metadata));
        self::assertFalse($repository->recordOnce($subject, WardrobeActivationEvent::FIRST_ITEM_ADDED, 'first_item_added', $metadata));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM wardrobe_activation_event'));
    }

    public function testDifferentOpaqueDedupKeysAreRecorded(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE wardrobe_activation_event (id INTEGER PRIMARY KEY AUTOINCREMENT, profile_subject_id INTEGER NOT NULL, event_type VARCHAR(32) NOT NULL, dedup_key VARCHAR(64) NOT NULL, metadata JSON NOT NULL, occurred_at DATETIME NOT NULL, UNIQUE (profile_subject_id, event_type, dedup_key))');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn(new ClassMetadata(WardrobeActivationEvent::class));
        $em->method('getConnection')->willReturn($connection);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $repository = new WardrobeActivationEventRepository($registry);
        $subject = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($subject, 42);

        self::assertTrue($repository->recordOnce($subject, WardrobeActivationEvent::DRAFT_ACCEPTED, hash('sha256', '1'), ['actorKind' => 'self', 'entryPoint' => 'batch']));
        self::assertTrue($repository->recordOnce($subject, WardrobeActivationEvent::DRAFT_ACCEPTED, hash('sha256', '2'), ['actorKind' => 'self', 'entryPoint' => 'batch']));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM wardrobe_activation_event'));
    }
}
