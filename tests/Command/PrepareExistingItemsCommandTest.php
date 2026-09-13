<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\Wardrobe\PrepareExistingItemsCommand;
use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Repository\WardrobeItemRepository;
use App\Service\Wardrobe\WardrobeAiService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Vich\UploaderBundle\Storage\StorageInterface;

final class PrepareExistingItemsCommandTest extends TestCase
{
    public function testOnlyMissingAttributesAreFilled(): void
    {
        $user = (new User())->setEmail('prepare@test.local');
        $item = (new WardrobeItem())
            ->setUser($user)
            ->setItemNo(4)
            ->setCategory('Рубашки')
            ->setColorName(null)
            ->setSeason('summer')
            ->setMaterialText(null);
        $this->setId($user, 7);
        $this->setId($item, 12);

        $repo = $this->createMock(WardrobeItemRepository::class);
        $repo->expects(self::once())->method('findNeedingPreparation')->with($user, 0, 15)->willReturn([$item]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $userRepository = $this->createMock(EntityRepository::class);
        $userRepository->expects(self::once())->method('findOneBy')->with(['email' => 'prepare@test.local'])->willReturn($user);
        $em->method('getRepository')->with(User::class)->willReturn($userRepository);
        $ai = $this->createMock(WardrobeAiService::class);
        $ai->expects(self::once())->method('suggestFromPhoto')->with('/tmp/item.jpg', $user)->willReturn([
            'ok' => true,
            'fields' => [
                'category' => 'Платья',
                'colorName' => 'белый',
                'materialText' => 'лён',
                'season' => 'winter',
            ],
        ]);
        $storage = $this->createStub(StorageInterface::class);
        $storage->method('resolvePath')->willReturn('/tmp/item.jpg');
        $projectDir = sys_get_temp_dir().'/wardrobe_prepare_'.bin2hex(random_bytes(4));
        mkdir($projectDir.'/var', 0777, true);
        touch('/tmp/item.jpg');

        try {
            $command = new PrepareExistingItemsCommand($em, $repo, $ai, $storage, $projectDir);
            self::assertSame(Command::SUCCESS, (new CommandTester($command))->execute(['--user' => 'prepare@test.local']));
            self::assertSame('Рубашки', $item->getCategory());
            self::assertSame('белый', $item->getColorName());
            self::assertSame('лён', $item->getMaterialText());
            self::assertSame('summer', $item->getSeason());
        } finally {
            unlink('/tmp/item.jpg');
            unlink($projectDir.'/var/wardrobe_prepare_items.lock');
            rmdir($projectDir.'/var');
            rmdir($projectDir);
        }
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity::class, 'id');
        $property->setValue($entity, $id);
    }
}
