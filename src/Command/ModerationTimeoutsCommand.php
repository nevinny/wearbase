<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BrandModeration;
use App\Notification\AdminNotifier;
use App\Repository\BrandClaimRepository;
use App\Repository\BrandModerationRepository;
use App\Service\BrandActionSigner;
use App\Service\Moderation\ModerationLabels;
use App\Service\Moderation\ModerationOwnerNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Таймауты очереди премодерации (ПРОД, раз в день) — очередь `brand_moderation` живёт
 * только на проде, поэтому команда не мигрирует на Mac (в отличие от app:brand:moderate-tick).
 * Четыре независимых правила:
 *
 *  а) `reviewed` без решения >2 дней — повторное TG-досье админу (с теми же кнопками
 *     approve/request-changes/reject, что и первичный вердикт), троттлинг `reminded_at`.
 *  б) `queued` >48ч — TG-список: analyzeAttempts=0 (анализатор стоит) и >=3 (нужна ручная).
 *  в) `changes_requested` без ответа владельца >14 дней — авто-архивация + уведомление
 *     владельца (ModerationOwnerNotifier, ветка STATUS_ARCHIVED). Бренд не трогаем.
 *  г) `BrandClaim` pending/email_verified >2 дней — TG-список админу.
 *
 *   php bin/console app:moderation:timeouts --dry-run   # что сделал бы, ничего не пишет/не шлёт
 *   php bin/console app:moderation:timeouts --no-debug  # боевой прогон (крон прода)
 */
#[AsCommand(
    name: 'app:moderation:timeouts',
    description: 'Таймауты очереди премодерации: напоминания, простой анализатора, авто-архивация, зависшие claim',
)]
class ModerationTimeoutsCommand extends Command
{
    private const REVIEWED_REMINDER_AFTER = '-2 days';
    private const QUEUED_STALLED_AFTER    = '-48 hours';
    private const CHANGES_REQUESTED_STALE_AFTER = '-14 days';
    private const CLAIM_OVERDUE_AFTER     = '-2 days';

    private const CLAIM_ANALYZE_ATTEMPTS_MIN = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BrandModerationRepository $moderationRepo,
        private readonly BrandClaimRepository $claimRepo,
        private readonly AdminNotifier $notifier,
        private readonly BrandActionSigner $actionSigner,
        private readonly ModerationOwnerNotifier $ownerNotifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать, что сделала бы команда, ничего не писать и не слать');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $now    = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow'));

        $this->remindOverdueReviewed($now, $dryRun, $io);
        $this->reportStalledQueue($now, $dryRun, $io);
        $this->archiveStaleChangesRequested($now, $dryRun, $io);
        $this->reportOverdueClaims($now, $dryRun, $io);

        return Command::SUCCESS;
    }

    // ── а) reviewed без решения >2 дней — повторное досье админу ────────────────────

    private function remindOverdueReviewed(\DateTimeImmutable $now, bool $dryRun, SymfonyStyle $io): void
    {
        $cutoff = $now->modify(self::REVIEWED_REMINDER_AFTER);
        $items  = $this->moderationRepo->findOverdueReviewed($cutoff);

        if ($items === []) {
            $io->text('(а) Нечего напоминать — reviewed без решения >2 дней нет.');
            return;
        }

        foreach ($items as $moderation) {
            $brand = $moderation->getBrand();
            $days  = $this->daysSince($moderation->getAnalyzedAt(), $now);
            $text  = $this->buildReminderText($brand->getTitle(), $days, $moderation);

            $io->text(sprintf('(а) Напоминание: «%s» (#%d), %d дн. без решения', $brand->getTitle(), $brand->getId(), $days));

            if ($dryRun) {
                continue;
            }

            try {
                $this->notifier->sendWithButtons($text, $this->signedButtons($brand->getId()));
            } catch (\Throwable $e) {
                $io->warning('TG не отправлен: ' . $e->getMessage());
            }

            $moderation->setRemindedAt($now);
        }

        if (!$dryRun) {
            $this->em->flush();
        }
    }

    private function buildReminderText(string $title, int $days, BrandModeration $moderation): string
    {
        $lines = [
            sprintf('⏰ <b>Напоминание:</b> заявка «%s» ждёт решения %d дн.', htmlspecialchars($title, ENT_QUOTES, 'UTF-8'), $days),
        ];

        $missing = ModerationLabels::missing($moderation->getMissing());
        if ($missing !== []) {
            $lines[] = 'Не хватает: ' . implode(', ', $missing);
        }

        $flags = ModerationLabels::flags($moderation->getRedFlags());
        if ($flags !== []) {
            $lines[] = '🚩 ' . implode('; ', $flags);
        }

        $summary = $moderation->getSummary();
        if ($summary !== null && $summary !== '') {
            $lines[] = htmlspecialchars($summary, ENT_QUOTES, 'UTF-8');
        }

        return implode("\n", $lines);
    }

    /** @return list<array{text:string,url:string}> копия ModerateTickCommand::signedButtons — те же ссылки/TTL. */
    private function signedButtons(int $brandId): array
    {
        $exp  = time() + 7 * 86400;
        $host = 'https://wearbase.ru';

        return [
            ['text' => '✅ Одобрить', 'url' => sprintf('%s/mod/brand-action?action=approve&id=%d&key=%s&exp=%d', $host, $brandId, $this->actionSigner->sign('approve', $brandId, $exp), $exp)],
            ['text' => '✏️ На доработку', 'url' => sprintf('%s/mod/brand-action?action=request-changes&id=%d&key=%s', $host, $brandId, $this->actionSigner->sign('request-changes', $brandId))],
            ['text' => '🚫 Отклонить', 'url' => sprintf('%s/mod/brand-action?action=reject&id=%d&key=%s', $host, $brandId, $this->actionSigner->sign('reject', $brandId))],
        ];
    }

    // ── б) queued >48ч — анализатор стоит / нужна ручная модерация ──────────────────

    private function reportStalledQueue(\DateTimeImmutable $now, bool $dryRun, SymfonyStyle $io): void
    {
        $cutoff = $now->modify(self::QUEUED_STALLED_AFTER);
        $items  = $this->moderationRepo->findStalledQueued($cutoff);

        $stuck  = array_filter($items, static fn (BrandModeration $m): bool => $m->getAnalyzeAttempts() === 0);
        $manual = array_filter($items, fn (BrandModeration $m): bool => $m->getAnalyzeAttempts() >= self::CLAIM_ANALYZE_ATTEMPTS_MIN);

        if ($stuck === [] && $manual === []) {
            $io->text('(б) Нечего сообщать — очередь анализа в норме.');
            return;
        }

        $lines = ['⏸ <b>Очередь премодерации стоит &gt;48ч</b>'];
        if ($stuck !== []) {
            $lines[] = "\nАнализатор стоит (0 попыток):";
            foreach ($stuck as $m) {
                $lines[] = $this->queueLine($m, $now);
            }
        }
        if ($manual !== []) {
            $lines[] = "\nНужна ручная модерация (≥3 попыток):";
            foreach ($manual as $m) {
                $lines[] = $this->queueLine($m, $now);
            }
        }
        $text = implode("\n", $lines);

        $io->text('(б) ' . str_replace("\n", ' | ', strip_tags($text)));

        if ($dryRun) {
            return;
        }

        try {
            $this->notifier->send($text);
        } catch (\Throwable $e) {
            $io->warning('TG не отправлен: ' . $e->getMessage());
        }
    }

    private function queueLine(BrandModeration $m, \DateTimeImmutable $now): string
    {
        $brand = $m->getBrand();

        return sprintf('• %s — %d дн.', htmlspecialchars($brand->getTitle(), ENT_QUOTES, 'UTF-8'), $this->daysSince($m->getCreatedAt(), $now));
    }

    // ── в) changes_requested без ответа владельца >14 дней — авто-архивация ─────────

    private function archiveStaleChangesRequested(\DateTimeImmutable $now, bool $dryRun, SymfonyStyle $io): void
    {
        $cutoff = $now->modify(self::CHANGES_REQUESTED_STALE_AFTER);
        $items  = $this->moderationRepo->findStaleChangesRequested($cutoff);

        if ($items === []) {
            $io->text('(в) Нечего архивировать — changes_requested старше 14 дней нет.');
            return;
        }

        foreach ($items as $moderation) {
            $brand = $moderation->getBrand();
            $io->text(sprintf('(в) Архивация: «%s» (#%d), без ответа %d дн.', $brand->getTitle(), $brand->getId(), $this->daysSince($moderation->getDecidedAt(), $now)));

            if ($dryRun) {
                continue;
            }

            // Статус обязан смениться ДО notify(): и заголовок письма, и dedupe-ключ
            // (moderation:{id}:archived) читают текущий статус.
            $moderation->setStatus(BrandModeration::STATUS_ARCHIVED);
            $this->ownerNotifier->notify($brand, $moderation);
        }

        if (!$dryRun) {
            $this->em->flush();
        }
    }

    // ── г) BrandClaim pending/email_verified >2 дней — TG-список админу ─────────────

    private function reportOverdueClaims(\DateTimeImmutable $now, bool $dryRun, SymfonyStyle $io): void
    {
        $cutoff = $now->modify(self::CLAIM_OVERDUE_AFTER);
        $claims = $this->claimRepo->findOverduePending($cutoff);

        if ($claims === []) {
            $io->text('(г) Нечего сообщать — заявок на владение >2 дней без решения нет.');
            return;
        }

        $lines = ['📋 <b>Заявки на владение ждут решения (&gt;2 дня):</b>'];
        foreach ($claims as $claim) {
            $lines[] = sprintf(
                '• #%d «%s» — %s · %s · %d дн.',
                $claim->getId(),
                htmlspecialchars($claim->getBrand()->getTitle(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $claim->getUser()->getEmail(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($claim->getMethod() ?? '—', ENT_QUOTES, 'UTF-8'),
                $this->daysSince($claim->getCreatedAt(), $now),
            );
        }
        $text = implode("\n", $lines);

        $io->text('(г) ' . str_replace("\n", ' | ', strip_tags($text)));

        if ($dryRun) {
            return;
        }

        try {
            $this->notifier->send($text);
        } catch (\Throwable $e) {
            $io->warning('TG не отправлен: ' . $e->getMessage());
        }
    }

    private function daysSince(?\DateTimeInterface $from, \DateTimeImmutable $now): int
    {
        if ($from === null) {
            return 0;
        }

        return (int) floor(($now->getTimestamp() - $from->getTimestamp()) / 86400);
    }
}
