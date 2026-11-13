<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Курсор ленты событий castbase (`/v1/events?after=`): последний обработанный seq. Одна строка на проект castbase. */
#[ORM\Entity]
#[ORM\Table(name: 'castbase_sync_cursor')]
class CastbaseSyncCursor
{
    public const EVENTS = 'events';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 32)]
        private string $name = self::EVENTS,
        #[ORM\Column(options: ['default' => 0])]
        private int $seq = 0,
    ) {
    }

    public function getSeq(): int
    {
        return $this->seq;
    }

    public function setSeq(int $seq): void
    {
        $this->seq = $seq;
    }
}
