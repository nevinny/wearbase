<?php

declare(strict_types=1);

namespace App\Dto\Family;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;

class ChildProfileInput
{
    #[Assert\NotBlank(message: 'Введите имя')]
    #[Assert\Length(max: 100)]
    public ?string $firstName = null;

    #[Assert\Length(max: 100)]
    public ?string $lastName = null;

    #[Assert\LessThanOrEqual('today', message: 'Дата рождения не может быть в будущем')]
    public ?\DateTimeImmutable $birthDate = null;

    #[Assert\Choice(choices: ['girl', 'boy', 'prefer_not_to_say'])]
    public ?string $gender = null;

    #[Assert\Range(min: 40, max: 250, notInRangeMessage: 'Укажите рост от {{ min }} до {{ max }} см')]
    public ?int $heightCm = null;

    #[Assert\Length(max: 20)]
    public ?string $clothingSize = null;

    #[Assert\Length(max: 10)]
    public ?string $shoeSize = null;

    #[Assert\Length(max: 1000)]
    public ?string $profileNotes = null;

    #[Assert\Image(
        maxSize: '5M',
        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
        mimeTypesMessage: 'Допустимы JPEG, PNG и WebP',
    )]
    public ?File $avatarFile = null;
}
