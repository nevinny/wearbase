<?php

declare(strict_types=1);

namespace App\Service\Family;

use App\Dto\Family\ChildProfileInput;
use App\Entity\User;
use App\Service\FamilyService;
use Doctrine\ORM\EntityManagerInterface;

class ChildProfileService
{
    public function __construct(
        private readonly FamilyService $familyService,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function create(User $parent, ChildProfileInput $input): User
    {
        $child = $this->familyService->createChild(
            $parent,
            trim((string) $input->firstName),
            $input->birthDate,
        );

        $this->apply($child, $input);
        $this->entityManager->flush();

        return $child;
    }

    public function input(User $child): ChildProfileInput
    {
        $input = new ChildProfileInput();
        $input->firstName = $child->getFirstName();
        $input->lastName = $child->getLastName();
        $input->birthDate = $child->getBirthDate();
        $input->gender = $child->getGender();
        $input->heightCm = $child->getHeightCm();
        $input->clothingSize = $child->getClothingSize();
        $input->shoeSize = $child->getShoeSize();
        $input->profileNotes = $child->getProfileNotes();

        return $input;
    }

    public function updateSelf(User $child, ChildProfileInput $input): void
    {
        if ($child->getFamilyRole() !== User::FAMILY_ROLE_CHILD) {
            throw new \DomainException('Анкета ребёнка доступна только детскому профилю');
        }

        $this->apply($child, $input);
        $this->entityManager->flush();
    }

    private function apply(User $child, ChildProfileInput $input): void
    {
        $child
            ->setFirstName(trim((string) $input->firstName))
            ->setLastName($this->nullable($input->lastName))
            ->setBirthDate($input->birthDate)
            ->setGender($input->gender)
            ->setHeightCm($input->heightCm)
            ->setClothingSize($this->nullable($input->clothingSize))
            ->setShoeSize($this->nullable($input->shoeSize))
            ->setProfileNotes($this->nullable($input->profileNotes))
            ->completeProfile();

        if ($input->avatarFile !== null) {
            $child->setAvatarFile($input->avatarFile);
        }

    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
