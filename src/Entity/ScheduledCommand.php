<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ScheduledCommandRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Запланированная консольная команда.
 *
 * Расписанием управляет одна точка входа — `app:cron:run-scheduled` (тикает раз в минуту
 * из launchd), которая выбирает enabled-строки и запускает те, чьё cron-выражение `isDue()`.
 * Так весь крон проекта живёт в БД и редактируется из админки, а в launchd — ровно одна строка.
 */
#[ORM\Entity(repositoryClass: ScheduledCommandRepository::class)]
#[ORM\Table(name: 'scheduled_command')]
#[ORM\UniqueConstraint(name: 'uq_scheduled_command', columns: ['command'])]
class ScheduledCommand
{
    /** Где исполняется задача. Диспетчер берёт только строки своего CRON_ENV. */
    public const ENVIRONMENTS = [
        'dev'  => 'Mac (dev)',
        'prod' => 'Прод (regru)',
        'llm'  => 'LLM-сервер (.43)',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Окружение-исполнитель: dev | prod | llm (см. ENVIRONMENTS). */
    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['dev', 'prod', 'llm'])]
    private string $environment = 'dev';

    /** Человекочитаемое имя для админки: «Синк GSC», «Дайджест в Telegram» … */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $name = '';

    /** Строка команды как в терминале, например: `app:gsc:sync --no-debug`. */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $command = '';

    /** Cron-выражение из 5 полей: `17 9 * * *`. */
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private string $schedule = '';

    #[ORM\Column]
    private bool $enabled = true;

    /** Сколько секунд ждать команду, прежде чем убить (защита от зависших джобов). */
    #[ORM\Column(options: ['default' => 3600])]
    private int $timeoutSec = 3600;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastRunAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $nextRunAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastExitCode = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastDurationSec = null;

    /** Хвост вывода последнего запуска (для диагностики из админки). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastOutput = null;

    /** Метка «выполняется сейчас» — страховка от наложения запусков. NULL = свободна. */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $runningSince = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function setEnvironment(string $environment): static
    {
        $this->environment = $environment;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function setCommand(string $command): static
    {
        $this->command = $command;

        return $this;
    }

    public function getSchedule(): string
    {
        return $this->schedule;
    }

    public function setSchedule(string $schedule): static
    {
        $this->schedule = $schedule;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getTimeoutSec(): int
    {
        return $this->timeoutSec;
    }

    public function setTimeoutSec(int $timeoutSec): static
    {
        $this->timeoutSec = $timeoutSec;

        return $this;
    }

    public function getLastRunAt(): ?\DateTimeInterface
    {
        return $this->lastRunAt;
    }

    public function setLastRunAt(?\DateTimeInterface $lastRunAt): static
    {
        $this->lastRunAt = $lastRunAt;

        return $this;
    }

    public function getNextRunAt(): ?\DateTimeInterface
    {
        return $this->nextRunAt;
    }

    public function setNextRunAt(?\DateTimeInterface $nextRunAt): static
    {
        $this->nextRunAt = $nextRunAt;

        return $this;
    }

    public function getLastExitCode(): ?int
    {
        return $this->lastExitCode;
    }

    public function setLastExitCode(?int $lastExitCode): static
    {
        $this->lastExitCode = $lastExitCode;

        return $this;
    }

    public function getLastDurationSec(): ?int
    {
        return $this->lastDurationSec;
    }

    public function setLastDurationSec(?int $lastDurationSec): static
    {
        $this->lastDurationSec = $lastDurationSec;

        return $this;
    }

    public function getLastOutput(): ?string
    {
        return $this->lastOutput;
    }

    public function setLastOutput(?string $lastOutput): static
    {
        $this->lastOutput = $lastOutput;

        return $this;
    }

    public function getRunningSince(): ?\DateTimeInterface
    {
        return $this->runningSince;
    }

    public function setRunningSince(?\DateTimeInterface $runningSince): static
    {
        $this->runningSince = $runningSince;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name !== '' ? $this->name : $this->command;
    }
}
