<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\User;
use App\Entity\WardrobeConsent;
use App\Repository\WardrobeConsentRepository;
use App\Service\FamilyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class WardrobeConsentService
{
    public function __construct(private readonly WardrobeConsentRepository $consents, private readonly FamilyService $families, private readonly EntityManagerInterface $em) {}

    public function grantPhotoProcessing(User $actor, User $subject): WardrobeConsent
    {
        if (!$this->families->canManage($actor, $subject)) {
            throw new AccessDeniedException('Нет доступа к профилю');
        }
        if ($subject->getFamilyRole() === User::FAMILY_ROLE_CHILD && !$actor->isFamilyParent()) {
            throw new AccessDeniedException('Для обработки фото ребёнка нужно согласие родителя');
        }
        $consent = $this->consents->findForSubject($subject) ?? new WardrobeConsent($subject, $actor);
        $consent->grantPhotoProcessing($actor);
        $this->em->persist($consent);
        $this->em->flush();
        return $consent;
    }
}
