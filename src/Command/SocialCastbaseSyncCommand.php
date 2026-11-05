<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CastbaseSyncCursor;
use App\Entity\SocialPost;
use App\Social\Castbase\CastbaseClient;
use App\Social\Castbase\CastbaseException;
use App\Social\SocialAlerts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Итоги публикации из ленты событий castbase (/v1/events?after=<курсор>) → social_post.
 * Курсор (castbase_sync_cursor) сдвигается после flush каждой страницы; повторное чтение безопасно:
 * переход применяется один раз (published не перезаписывается, failed/unknown — только из handed_off).
 *
 * post.outcome_unknown НЕ превращается в publish_failed и не повторяется: пост мог выйти, повтор даст дубль.
 * Пост остаётся handed_off с пометкой 'outcome_unknown: …' в last_error и уходит алерт на ручную сверку профиля.
 */
#[AsCommand(name: 'app:social:castbase-sync', description: 'Подтянуть итоги публикации из ленты событий castbase')]
class SocialCastbaseSyncCommand extends Command
{
    private const PAGE = 100;
    private const REF = '/^social_post:(\d+)$/'; // теневые ссылки (…:shadow:N) сюда не попадают

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CastbaseClient $castbase,
        private readonly SocialAlerts $alerts,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cursor = $this->em->find(CastbaseSyncCursor::class, CastbaseSyncCursor::EVENTS);
        if ($cursor === null) {
            $cursor = new CastbaseSyncCursor();
            $this->em->persist($cursor);
        }

        $applied = 0;
        while (true) {
            try {
                $feed = $this->castbase->events($cursor->getSeq(), self::PAGE);
            } catch (CastbaseException $e) {
                $io->error($e->getMessage());
                return Command::FAILURE;
            }
            $events = $feed['data'] ?? [];
            if ($events === []) {
                break;
            }
            foreach ($events as $event) {
                $applied += $this->apply($event, $io) ? 1 : 0;
            }
            $cursor->setSeq(max((int) ($feed['next_after'] ?? 0), $cursor->getSeq()));
            $this->em->flush();
        }
        $this->em->flush();

        $io->success(sprintf('Применено событий: %d, курсор %d', $applied, $cursor->getSeq()));

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $event */
    private function apply(array $event, SymfonyStyle $io): bool
    {
        $type = (string) ($event['type'] ?? '');
        $data = (array) ($event['data'] ?? []);

        if ($type === 'channel.reauth_required') {
            $this->alerts->text('🔑 <b>castbase: IG-каналу нужен новый токен</b> (channel.reauth_required). Публикации не пойдут, пока токен не обновлён: app:castbase:connect-ig.');
            return true;
        }
        if (!in_array($type, ['post.published', 'post.failed', 'post.outcome_unknown'], true)
            || !preg_match(self::REF, (string) ($data['external_ref'] ?? ''), $m)) {
            return false;
        }
        $post = $this->em->find(SocialPost::class, (int) $m[1]);
        if ($post === null) {
            $io->warning("Событие {$type}: поста #{$m[1]} нет в wearbase.");
            return false;
        }

        $status = $post->getStatus();
        if ($type === 'post.published') {
            // Реальный выход поста — истина, даже если мы уже пометили его publish_failed (ответ POST потерялся).
            if (in_array($status, [SocialPost::STATUS_PUBLISHED, SocialPost::STATUS_DONE], true)) {
                return false;
            }
            $post->setStatus(SocialPost::STATUS_PUBLISHED)
                ->setExternalId(($data['external_id'] ?? '') !== '' ? (string) $data['external_id'] : null)
                ->setPublishedAt(isset($event['created_at']) ? (new \DateTime((string) $event['created_at']))->setTimezone(new \DateTimeZone(date_default_timezone_get())) : new \DateTime())
                ->setClaimedAt(null)
                ->setLastError(null);
            return true;
        }
        if ($status !== SocialPost::STATUS_HANDED_OFF) {
            return false;
        }
        $message = mb_substr(sprintf('%s: %s', (string) ($data['error_code'] ?? ''), (string) ($data['error_message'] ?? '')), 0, 500);
        if ($type === 'post.outcome_unknown') {
            $post->setLastError('outcome_unknown: ' . $message);
            $this->alerts->outcomeUnknown($post);
            return true;
        }
        $post->setStatus(SocialPost::STATUS_PUBLISH_FAILED)->setLastError($message);
        $this->alerts->publishFailed($post);

        return true;
    }
}
