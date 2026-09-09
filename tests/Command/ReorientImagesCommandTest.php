<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ReorientImagesCommand;
use App\Entity\WardrobeItemPhoto;
use App\Repository\WardrobeItemPhotoRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ReorientImagesCommandTest extends TestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/wearbase-reorient-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->uploadDir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->uploadDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->uploadDir);
    }

    public function testDryRunListsCandidatesWithKnownAffectedIds(): void
    {
        $photos = [
            $this->photo(51, 'a1known-five-one.jpg'),
            $this->photo(52, 'b2known-five-two.jpg'),
            $this->photo(53, 'c3known-five-three.jpg'),
            $this->photo(54, 'd4known-five-four.jpg'),
            $this->photo(55, 'e5known-five-five.jpg'),
        ];
        $repository = $this->createMock(WardrobeItemPhotoRepository::class);
        $repository->expects(self::once())->method('findReorientCandidates')->with([51, 52, 53, 54, 55], null, null)->willReturn($photos);

        $tester = new CommandTester(new ReorientImagesCommand($repository, $this->createMock(EntityManagerInterface::class), $this->uploadDir));

        self::assertSame(Command::SUCCESS, $tester->execute(['--ids' => ['51,52,53,54,55'], '--dry-run' => true]));
        foreach ([51, 52, 53, 54, 55] as $id) {
            self::assertStringContainsString((string) $id, $tester->getDisplay());
        }
        self::assertStringContainsString('missing', $tester->getDisplay());
        self::assertStringContainsString('Dry run: 5 candidate(s).', $tester->getDisplay());
    }

    public function testRefusesToRunWithoutAnySelection(): void
    {
        $repository = $this->createMock(WardrobeItemPhotoRepository::class);
        $repository->expects(self::never())->method('findReorientCandidates');

        $tester = new CommandTester(new ReorientImagesCommand($repository, $this->createMock(EntityManagerInterface::class), $this->uploadDir));

        self::assertSame(Command::INVALID, $tester->execute([]));
    }

    public function testRefusesMalformedSelections(): void
    {
        $tester = new CommandTester(new ReorientImagesCommand(
            $this->createStub(WardrobeItemPhotoRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->uploadDir,
        ));

        self::assertSame(Command::INVALID, $tester->execute(['--ids' => ['abc']]));
        self::assertSame(Command::INVALID, $tester->execute(['--since' => 'not-a-date']));
    }

    public function testRealRunRotatesExistingFileAndReportsMissingOnes(): void
    {
        $filePath = 'a1rotated-fifty-one.jpg';
        $path = $this->uploadDir.'/a1/ro/'.$filePath;
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, $this->horizontalRedBlueJpeg());

        $fiftyOne = $this->photo(51, $filePath);
        $fiftyTwo = $this->photo(52, 'z9missing-fifty-two.jpg');
        $repository = $this->createMock(WardrobeItemPhotoRepository::class);
        $repository->method('findReorientCandidates')->willReturn([$fiftyOne, $fiftyTwo]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $tester = new CommandTester(new ReorientImagesCommand($repository, $entityManager, $this->uploadDir));

        // 90° по часовой: 2x1 (слева красный, справа синий) -> 1x2 (сверху красный, снизу синий).
        self::assertSame(Command::FAILURE, $tester->execute(['--ids' => ['51,52'], '--angle' => '90']));
        self::assertStringContainsString('z9missing-fifty-two.jpg', $tester->getDisplay());

        $gd = imagecreatefromstring((string) file_get_contents($path));
        self::assertNotFalse($gd);
        self::assertSame([1, 2], [imagesx($gd), imagesy($gd)]);
        $top = imagecolorat($gd, 0, 0);
        $bottom = imagecolorat($gd, 0, 1);
        self::assertTrue((($top >> 16) & 0xFF) > ($top & 0xFF), 'Верхний пиксель должен быть красным');
        self::assertTrue(($bottom & 0xFF) > (($bottom >> 16) & 0xFF), 'Нижний пиксель должен быть синим');
        self::assertSame(filesize($path), $fiftyOne->getFileSize());
        self::assertNotNull($fiftyOne->getUpdatedAt());
    }

    /** 2x1 JPEG без EXIF: слева красный, справа синий. */
    private function horizontalRedBlueJpeg(): string
    {
        $img = imagecreatetruecolor(2, 1);
        self::assertNotFalse($img);
        imagesetpixel($img, 0, 0, (int) imagecolorallocate($img, 255, 0, 0));
        imagesetpixel($img, 1, 0, (int) imagecolorallocate($img, 0, 0, 255));
        ob_start();
        imagejpeg($img, null, 95);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    private function photo(int $id, string $filePath): WardrobeItemPhoto
    {
        $photo = new WardrobeItemPhoto();
        $photo->setFilePath($filePath);

        $ref = new \ReflectionProperty(WardrobeItemPhoto::class, 'id');
        $ref->setValue($photo, $id);

        return $photo;
    }
}
