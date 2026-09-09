<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\WardrobeItem;
use App\Entity\WardrobeItemDraft;
use App\Tests\Controller\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CleanupWardrobeDraftsCommandTest extends KernelTestCase
{
    public function testCleanupKeepsAcceptedReceiptButRemovesSensitiveDataAndAbandonedDraft(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = UserFactory::withEmail(self::getContainer(), 'cleanup-drafts@test.local');
        $item = (new WardrobeItem())->setUser($user)->setOriginalOwner($user)->setItemNo(1);
        $accepted = (new WardrobeItemDraft())->setUser($user)->setBatchId('cleanup-accepted')->setStatus(WardrobeItemDraft::STATUS_RECOGNIZED)->setAiRaw(['model' => 'private-diagnostic'])->setPhoto('missing.jpg')->setFileSize(1234);
        $accepted->accept($item);
        $abandoned = (new WardrobeItemDraft())->setUser($user)->setBatchId('cleanup-abandoned')->setStatus(WardrobeItemDraft::STATUS_FAILED)->setAiRaw(['error' => 'private']);
        $em->persist($item);
        $em->persist($accepted);
        $em->persist($abandoned);
        $em->flush();
        $em->getConnection()->executeStatement('UPDATE wardrobe_item_draft SET accepted_at = :old WHERE id = :id', ['old' => '2026-01-01 00:00:00', 'id' => $accepted->getId()]);
        $em->getConnection()->executeStatement('UPDATE wardrobe_item_draft SET created_at = :old WHERE id = :id', ['old' => '2026-01-01 00:00:00', 'id' => $abandoned->getId()]);
        $em->clear();

        $command = (new Application(self::$kernel))->find('app:wardrobe:cleanup-drafts');
        $exitCode = (new CommandTester($command))->execute([]);
        $this->assertSame(0, $exitCode);
        $reloadedAccepted = $em->find(WardrobeItemDraft::class, $accepted->getId());
        $this->assertNotNull($reloadedAccepted);
        $this->assertNull($reloadedAccepted->getAiRaw());
        $this->assertNull($reloadedAccepted->getPhoto());
        $this->assertNotNull($reloadedAccepted->getAcceptedItem());
        $this->assertNull($em->find(WardrobeItemDraft::class, $abandoned->getId()));
    }
}
