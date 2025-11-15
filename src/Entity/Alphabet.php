<?php

namespace App\Entity;

use App\Repository\AlphabetRepository;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;
use Nevinny\AdminCoreBundle\Entity\Trait\Status;

#[ORM\Entity(repositoryClass: AlphabetRepository::class)]
class Alphabet
{
    use Created, Status;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $locale = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $letter = null;

    #[ORM\Column(options: ['default' => 0])]
    private ?int $brandsCount = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getLetter(): ?string
    {
        return $this->letter;
    }

    public function setLetter(?string $letter): static
    {
        $this->letter = $letter;

        return $this;
    }

    public function getBrandsCount(): ?int
    {
        return $this->brandsCount;
    }

    public function setBrandsCount(int $brandsCount): static
    {
        $this->brandsCount = $brandsCount;

        return $this;
    }

    public function incrementBrandsCount(): static
    {
        $this->brandsCount++;
        $this->setUpdatedAt(new \DateTime());
        return $this;
    }

    public function decrementBrandsCount(): static
    {
        $this->brandsCount = max(0, $this->brandsCount - 1);
        $this->setUpdatedAt(new \DateTime());
        return $this;
    }
}
