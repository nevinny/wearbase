<?php

declare(strict_types=1);

namespace App\Tests\Service\Seo;

use App\Entity\CompetitorArticle;
use App\Entity\SeoCompetitorScan;
use App\Service\Seo\GapContextResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Общая точка правды для --gap-context (вынесена из GenerateListicleCommand/
 * ReplaceListicleCommand/SeoGuideCommand — см. код-ревью 2026-09-23, было
 * продублировано почти дословно в трёх местах).
 */
final class GapContextResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private GapContextResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->resolver = self::getContainer()->get(GapContextResolver::class);
    }

    public function testResolveNullIdIsNoop(): void
    {
        $result = $this->resolver->resolve(null);

        self::assertNull($result['scan']);
        self::assertNull($result['topics']);
        self::assertNull($result['error']);
    }

    public function testResolveUnknownIdFails(): void
    {
        $result = $this->resolver->resolve('999999');

        self::assertNull($result['scan']);
        self::assertStringContainsString('999999', (string) $result['error']);
        self::assertStringContainsString('не найден', (string) $result['error']);
    }

    public function testResolveEmptyGapSummaryFailsWithStatusInMessage(): void
    {
        $scan = (new SeoCompetitorScan())
            ->setKeyword('тест фраза')
            ->setDemandSource(SeoCompetitorScan::SOURCE_GSC)
            ->setIntentGroup(SeoCompetitorScan::INTENT_OTHER)
            ->setSerpResults([])
            ->setStatus(SeoCompetitorScan::STATUS_SCANNED);
        $this->em->persist($scan);
        $this->em->flush();

        $result = $this->resolver->resolve((string) $scan->getId());

        self::assertNull($result['scan']);
        self::assertNull($result['topics']);
        self::assertStringContainsString('gap_summary пуст', (string) $result['error']);
        self::assertStringContainsString(SeoCompetitorScan::STATUS_SCANNED, (string) $result['error']);
    }

    public function testResolveWithTopicsSucceeds(): void
    {
        $scan = (new SeoCompetitorScan())
            ->setKeyword('тест фраза 2')
            ->setDemandSource(SeoCompetitorScan::SOURCE_GSC)
            ->setIntentGroup(SeoCompetitorScan::INTENT_OTHER)
            ->setSerpResults([])
            ->setGapSummary("- тема 1\n- тема 2")
            ->setStatus(SeoCompetitorScan::STATUS_ANALYZED);
        $this->em->persist($scan);
        $this->em->flush();

        $result = $this->resolver->resolve((string) $scan->getId());

        self::assertNotNull($result['scan']);
        self::assertSame("- тема 1\n- тема 2", $result['topics']);
        self::assertNull($result['error']);
    }

    public function testNearDuplicateIssuesEmptyWithoutScan(): void
    {
        self::assertSame([], $this->resolver->nearDuplicateIssues('любой текст', null));
    }

    public function testNearDuplicateIssuesFlagsNearVerbatimCopy(): void
    {
        $competitorText = str_repeat('это очень характерный уникальный текст статьи про бренды одежды в россии, состоящий из множества разных слов ', 30);

        $article = (new CompetitorArticle())
            ->setUrl('https://example-competitor.ru/article-' . uniqid())
            ->setDomain('example-competitor.ru')
            ->setPageType(CompetitorArticle::TYPE_ARTICLE)
            ->setContent($competitorText);
        $this->em->persist($article);
        $this->em->flush();

        $scan = (new SeoCompetitorScan())
            ->setKeyword('тест фраза 3')
            ->setDemandSource(SeoCompetitorScan::SOURCE_GSC)
            ->setIntentGroup(SeoCompetitorScan::INTENT_OTHER)
            ->setSerpResults([
                ['url' => $article->getUrl(), 'position' => 1, 'page_type' => CompetitorArticle::TYPE_ARTICLE, 'competitor_article_id' => $article->getId()],
            ])
            ->setGapSummary('- тема')
            ->setStatus(SeoCompetitorScan::STATUS_ANALYZED);
        $this->em->persist($scan);
        $this->em->flush();

        // Почти дословная копия текста конкурента — должна словить near-duplicate.
        $issues = $this->resolver->nearDuplicateIssues($competitorText, $scan);

        self::assertNotEmpty($issues);
        self::assertStringContainsString('near-duplicate', $issues[0]);
        self::assertStringContainsString('example-competitor.ru', $issues[0]);
    }

    public function testNearDuplicateIssuesEmptyForDifferentText(): void
    {
        $article = (new CompetitorArticle())
            ->setUrl('https://example-competitor2.ru/article-' . uniqid())
            ->setDomain('example-competitor2.ru')
            ->setPageType(CompetitorArticle::TYPE_ARTICLE)
            ->setContent(str_repeat('совершенно другой текст про конкурентов и их статьи ', 30));
        $this->em->persist($article);
        $this->em->flush();

        $scan = (new SeoCompetitorScan())
            ->setKeyword('тест фраза 4')
            ->setDemandSource(SeoCompetitorScan::SOURCE_GSC)
            ->setIntentGroup(SeoCompetitorScan::INTENT_OTHER)
            ->setSerpResults([
                ['url' => $article->getUrl(), 'position' => 1, 'page_type' => CompetitorArticle::TYPE_ARTICLE, 'competitor_article_id' => $article->getId()],
            ])
            ->setGapSummary('- тема')
            ->setStatus(SeoCompetitorScan::STATUS_ANALYZED);
        $this->em->persist($scan);
        $this->em->flush();

        $issues = $this->resolver->nearDuplicateIssues('Наш собственный уникальный текст статьи, никак не похожий на конкурента.', $scan);

        self::assertSame([], $issues);
    }
}
