<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WardrobeConsent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WardrobeConsent> */
class WardrobeConsentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, WardrobeConsent::class); }
    public function findForSubject(User $subject): ?WardrobeConsent { return $this->findOneBy(['subject' => $subject]); }
    public function isPersonalizationGranted(User $subject): bool { return $this->findForSubject($subject)?->isPersonalizationGranted() ?? false; }
}
