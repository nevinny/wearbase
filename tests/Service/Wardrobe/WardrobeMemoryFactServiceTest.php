<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Entity\FittingFeedback;
use App\Entity\PurchaseRequest;
use App\Entity\PurchaseRequestItem;
use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeMemoryFact;
use App\Entity\WardrobeWearEvent;
use App\Repository\FittingFeedbackRepository;
use App\Repository\WardrobeConsentRepository;
use App\Repository\WardrobeMemoryFactRepository;
use App\Repository\WardrobeWearEventRepository;
use App\Service\FamilyService;
use App\Service\Wardrobe\WardrobeMemoryFactService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class WardrobeMemoryFactServiceTest extends TestCase
{
    public function testConfirmedWearCreatesProfileScopedFactWithoutComment(): void
    {
        $subject = $this->user(1);
        $event = new WardrobeWearEvent($subject, $subject, WardrobeWearEvent::TYPE_WORN, 'self');
        $this->id($event, 11);
        $item = (new WardrobeItem())->setUser($subject)->setCategory('Футболка')->setColorName('Синий');
        $this->id($item, 21);
        $event->addItem($item);
        $event->confirm([21]);
        $event->addFeedback('comfortable', true, 'private comment must not be copied');

        $facts = $this->createStub(WardrobeMemoryFactRepository::class);
        $facts->method('findSource')->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::callback(static function (WardrobeMemoryFact $fact) use ($subject): bool {
            self::assertSame($subject, $fact->getProfileSubject());
            self::assertSame('self', $fact->getSignalSource());
            self::assertSame('Комфортно и хочет повторять: Футболка, Синий', $fact->getFact());
            self::assertStringNotContainsString('private', $fact->getFact());
            return true;
        }));
        $em->expects(self::once())->method('flush');

        $this->service($facts, $em, true)->syncWear($event);
    }

    public function testNoConsentCreatesNoFactAndContextIsEmpty(): void
    {
        $subject = $this->user(1);
        $facts = $this->createMock(WardrobeMemoryFactRepository::class);
        $facts->expects(self::never())->method('findSource');
        $facts->expects(self::never())->method('findActive');
        $service = $this->service($facts, $this->createMock(EntityManagerInterface::class), false);

        $event = new WardrobeWearEvent($subject, $subject, WardrobeWearEvent::TYPE_WORN, 'self');
        $this->id($event, 11);
        $service->syncWear($event);

        self::assertSame('', $service->context($subject));
    }

    public function testParentFittingKeepsSubjectAndObservedSource(): void
    {
        $parent = $this->user(1);
        $child = $this->user(2);
        $request = (new PurchaseRequest())->setSubject($child);
        $item = (new PurchaseRequestItem('https://example.test/item'))->setPurchaseRequest($request);
        $feedback = new FittingFeedback($parent, FittingFeedback::OUTCOME_REFUSED, 'M', FittingFeedback::SIZING_SMALL);
        $feedback->setItem($item);
        $this->id($feedback, 31);

        $facts = $this->createStub(WardrobeMemoryFactRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::callback(static function (WardrobeMemoryFact $fact) use ($child, $parent): bool {
            return $fact->getProfileSubject() === $child
                && $fact->getActor() === $parent
                && $fact->getSignalSource() === 'parent_observed'
                && !str_contains($fact->getFact(), 'example.test');
        }));

        $this->service($facts, $em, true)->syncFitting($feedback);
    }

    public function testCrossProfileEditIsDeniedBeforeLookup(): void
    {
        $actor = $this->user(1);
        $subject = $this->user(2);
        $families = $this->createStub(FamilyService::class);
        $families->method('canManage')->willReturn(false);
        $facts = $this->createMock(WardrobeMemoryFactRepository::class);
        $facts->expects(self::never())->method('find');
        $service = $this->service($facts, $this->createStub(EntityManagerInterface::class), true, $families);

        $this->expectException(AccessDeniedException::class);
        $service->edit($actor, $subject, 99, 'Чужой факт');
    }

    private function service(WardrobeMemoryFactRepository $facts, EntityManagerInterface $em, bool $consent, ?FamilyService $families = null): WardrobeMemoryFactService
    {
        $consents = $this->createStub(WardrobeConsentRepository::class);
        $consents->method('isPersonalizationGranted')->willReturn($consent);
        return new WardrobeMemoryFactService(
            $facts,
            $consents,
            $this->createStub(WardrobeWearEventRepository::class),
            $this->createStub(FittingFeedbackRepository::class),
            $families ?? $this->createStub(FamilyService::class),
            $em,
        );
    }

    private function user(int $id): User
    {
        $user = new User();
        $this->id($user, $id);
        return $user;
    }

    private function id(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
