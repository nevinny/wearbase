<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\OfferAcceptance;
use App\Entity\OfferDocument;
use App\Entity\User;
use App\Repository\OfferAcceptanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class OfferAcceptanceFlowTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private OfferAcceptanceRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(OfferAcceptanceRepository::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    private function sellerOffer(): OfferDocument
    {
        $offer = new OfferDocument();
        $offer->setType(OfferDocument::TYPE_SELLER_OFFER);
        $offer->setLocale('ru');
        $offer->setVersion('test-' . uniqid());
        $offer->setTitle('Оферта');
        $offer->setContent('Текст оферты');
        $offer->setContentHash($offer->computeHash());
        $offer->setEffectiveFrom(new \DateTimeImmutable('today'));
        $offer->setStatus(OfferDocument::STATUS_PUBLISHED);
        $this->em->persist($offer);
        return $offer;
    }

    private function user(): User
    {
        $user = new User();
        $user->setEmail('owner-' . uniqid() . '@example.com');
        $user->setPassword('x');
        $this->em->persist($user);
        return $user;
    }

    public function testHasAcceptedFlipsAfterRecordingAcceptance(): void
    {
        $user = $this->user();
        $offer = $this->sellerOffer();
        $this->em->flush();

        $this->assertFalse($this->repo->hasAccepted($user, $offer), 'До акцепта — не принято');

        $acceptance = new OfferAcceptance();
        $acceptance->setUser($user);
        $acceptance->setOfferDocument($offer);
        $acceptance->setContextType(OfferAcceptance::CONTEXT_SELLER_ONBOARDING);
        $acceptance->setIp('127.0.0.1');
        $this->em->persist($acceptance);
        $this->em->flush();

        $this->assertTrue($this->repo->hasAccepted($user, $offer), 'После акцепта — принято');
    }

    public function testAcceptanceIsPerDocumentVersion(): void
    {
        $user = $this->user();
        $v1 = $this->sellerOffer();
        $v2 = $this->sellerOffer();
        $this->em->flush();

        $acceptance = new OfferAcceptance();
        $acceptance->setUser($user);
        $acceptance->setOfferDocument($v1);
        $acceptance->setContextType(OfferAcceptance::CONTEXT_SELLER_ONBOARDING);
        $this->em->persist($acceptance);
        $this->em->flush();

        $this->assertTrue($this->repo->hasAccepted($user, $v1));
        $this->assertFalse($this->repo->hasAccepted($user, $v2), 'Новая редакция требует отдельного акцепта');
    }
}
