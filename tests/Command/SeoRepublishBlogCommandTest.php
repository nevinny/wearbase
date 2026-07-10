<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Article;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Переиздание (app:seo:republish-blog): тот же slug и publishedAt, свежие
 * контент/title/meta_title (год), updatedAt = now (→ dateModified/lastmod).
 */
class SeoRepublishBlogCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testRepublishKeepsSlugAndPublishedAtAndBumpsUpdatedAt(): void
    {
        $publishedAt = new \DateTime('2025-06-01 09:00:00');

        $article = (new Article())
            ->setSlug('top-5-brendov-streetwear-reyting')
            ->setLocale('ru')
            ->setTitle('ТОП-5 брендов streetwear: рейтинг')
            ->setMetaTitle('Топ-5 лучших брендов streetwear 2025 года')
            ->setContent('<p>Старый текст.</p>')
            ->setPublishedAt($publishedAt);
        $article->setStatus(Statuses::Active);
        $this->em->persist($article);
        $this->em->flush();

        // PrePersist ставит updatedAt=now — откатываем в прошлое, чтобы увидеть бамп.
        $article->setUpdatedAt(new \DateTime('2025-06-01 09:00:00'));
        $this->em->flush();

        $file = tempnam(sys_get_temp_dir(), 'republish') . '.md';
        file_put_contents($file, <<<MD
        <!-- meta-title: Топ-5 лучших брендов streetwear 2026 года -->

        # ТОП-5 брендов streetwear: рейтинг

        ## Коротко

        Обновлённый ответ.

        Новый текст статьи.
        MD);

        $command = (new Application(self::$kernel))->find('app:seo:republish-blog');
        $exit = (new CommandTester($command))->execute([
            'slug'   => 'top-5-brendov-streetwear-reyting',
            '--file' => $file,
        ]);
        unlink($file);

        self::assertSame(0, $exit);
        self::assertSame('top-5-brendov-streetwear-reyting', $article->getSlug());
        self::assertEquals($publishedAt, $article->getPublishedAt());                 // первая публикация сохранена
        self::assertSame('Топ-5 лучших брендов streetwear 2026 года', $article->getMetaTitle()); // год обновлён
        self::assertStringContainsString('Новый текст статьи.', $article->getContent());
        self::assertGreaterThan(new \DateTime('2025-06-01 10:00:00'), $article->getUpdatedAt()); // dateModified бампнут
    }

    public function testUnknownSlugFails(): void
    {
        $command = (new Application(self::$kernel))->find('app:seo:republish-blog');
        $tester  = new CommandTester($command);

        self::assertSame(1, $tester->execute(['slug' => 'no-such-slug']));
        self::assertStringContainsString('не найдена', $tester->getDisplay());
    }
}
