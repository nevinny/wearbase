<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\InstagramCommentReply;
use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Repository\SocialChannelRepository;
use App\Repository\SocialPostRepository;
use App\Service\SecretCipher;
use App\Service\Social\CommentKeyword;
use App\Service\Social\InstagramApiException;
use App\Service\Social\InstagramComments;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * «Кодовое слово в комментарии → ответ в директ со ссылкой». Ссылки в подписях и комментариях IG
 * не кликабельны, в личных сообщениях — кликабельны.
 *
 * Поллинг, а не вебхуки: Meta не достукивается до РФ-прода, а Mac ходит в IG через VPN (host=mac, как publish-tick).
 * Берёт опубликованные IG-посты за 7 дней (окно Private Reply) с comment_keyword в script_json, читает
 * комментарии, на каждый с кодовым словом шлёт Private Reply. Дедуп — instagram_comment_reply (UNIQUE comment_id).
 * Свои комментарии пропускаются. Ошибка по посту/комментарию не роняет прогон.
 */
#[AsCommand(name: 'app:social:ig-comment-replies', description: 'Отвечает в директ на комментарии IG с кодовым словом (Private Reply)')]
class SocialIgCommentRepliesCommand extends Command
{
    private const WINDOW_DAYS = 7;
    private const RETRY_AFTER = '-15 minutes';
    private const LINK_BASE = 'https://wearbase.ru/ru/wardrobe';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SocialChannelRepository $channels,
        private readonly SocialPostRepository $posts,
        private readonly InstagramComments $api,
        private readonly SecretCipher $cipher,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'egress-хост: mac|prod', SocialChannel::HOST_MAC)
            ->addOption('max-replies', null, InputOption::VALUE_REQUIRED, 'Максимум ответов за прогон (лимиты Meta на личные сообщения)', '20')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только читать комментарии и показывать, на что ответили бы; ничего не отправлять и не писать в БД');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $maxReplies = max(1, (int) $input->getOption('max-replies'));

        $lock = fopen($this->projectDir . '/var/ig-comment-replies.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $io->text('Предыдущий прогон ещё идёт.');
            return Command::SUCCESS;
        }

        try {
            $channels = array_values(array_filter(
                $this->channels->findEnabledByHost((string) $input->getOption('host')),
                static fn (SocialChannel $c) => $c->getPlatform() === SocialChannel::PLATFORM_IG,
            ));
            $since = new \DateTimeImmutable('-' . self::WINDOW_DAYS . ' days');
            $posts = $this->posts->findPublishedWithCommentKeyword($channels, $since);
            if ($posts === []) {
                $io->text('Нет опубликованных IG-постов с кодовым словом за ' . self::WINDOW_DAYS . ' дн.');
                return Command::SUCCESS;
            }

            $own = [];   // channelId => [token, username]; null — канал недоступен, пропускаем его посты
            $sent = $failed = $matched = 0;
            foreach ($posts as $post) {
                $channel = $post->getChannel();
                $cid = $channel->getId();
                if (!array_key_exists($cid, $own)) {
                    $own[$cid] = $this->prepareChannel($channel, $io);
                }
                if ($own[$cid] === null) {
                    continue;
                }
                [$token, $username] = $own[$cid];
                $config = $post->commentKeywordConfig();

                try {
                    $comments = $this->api->comments((string) $post->getExternalId(), $token, $since);
                } catch (\Throwable $e) {
                    $io->warning(sprintf('#%d: чтение комментариев: %s', $post->getId(), $e->getMessage()));
                    continue;
                }
                $io->text(sprintf('#%d «%s»: комментариев %d', $post->getId(), $config['keyword'], count($comments)));

                foreach ($comments as $comment) {
                    if (strcasecmp($comment['username'], $username) === 0 || !CommentKeyword::matches($comment['text'], $config['keyword'])) {
                        continue;
                    }
                    $row = $this->em->getRepository(InstagramCommentReply::class)->findOneBy(['commentId' => $comment['id']]);
                    if ($row !== null && !$this->canRetry($row)) {
                        continue;
                    }
                    $matched++;
                    if ($dryRun) {
                        $io->text(sprintf('  dry-run: ответил бы @%s на %s: «%s»', $comment['username'], $comment['id'], mb_substr($comment['text'], 0, 60)));
                        continue;
                    }
                    if ($sent + $failed >= $maxReplies) {
                        $io->text('Достигнут лимит ответов за прогон — остальное в следующий.');
                        break 2;
                    }
                    $this->reply($post, $comment['id'], $config['reply'], $channel->getTarget(), $token, $row) ? $sent++ : $failed++;
                }
            }

            $io->success(sprintf('Совпадений: %d, отправлено: %d, ошибок: %d%s', $matched, $sent, $failed, $dryRun ? ' (dry-run)' : ''));
            return Command::SUCCESS;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{0: string, 1: string}|null токен и username аккаунта (по нему отсекаем свои комментарии) */
    private function prepareChannel(SocialChannel $channel, SymfonyStyle $io): ?array
    {
        $enc = $channel->getTokenEnc();
        if ($enc === null || $enc === '' || $channel->getTarget() === '') {
            $io->warning(sprintf('Канал #%d: нет токена или target.', $channel->getId()));
            return null;
        }
        try {
            $token = $this->cipher->decrypt($enc);
            $username = $this->api->ownUsername($token);
        } catch (\Throwable $e) {
            $io->warning(sprintf('Канал #%d: %s', $channel->getId(), $e->getMessage()));
            return null;
        }
        if ($username === '') {
            // Без своего username не отличить собственные комментарии — лучше не отвечать никому.
            $io->warning(sprintf('Канал #%d: IG не вернул username, пропуск.', $channel->getId()));
            return null;
        }

        return [$token, $username];
    }

    private function canRetry(InstagramCommentReply $row): bool
    {
        return $row->getStatus() === InstagramCommentReply::STATUS_FAILED
            && $row->getAttempts() < InstagramCommentReply::MAX_ATTEMPTS
            && $row->getUpdatedAt() <= new \DateTime(self::RETRY_AFTER);
    }

    /** true — ушло. Строка пишется до отправки: при сбое посреди отправки повторного письма человеку не будет. */
    private function reply(SocialPost $post, string $commentId, string $template, string $igUserId, string $token, ?InstagramCommentReply $row): bool
    {
        if ($row === null) {
            $row = new InstagramCommentReply($post, $commentId);
            $this->em->persist($row);
        }
        $row->startAttempt();
        $this->em->flush();

        try {
            $link = self::LINK_BASE . '?utm_source=instagram&utm_medium=dm&utm_campaign=' . rawurlencode($post->getScriptKey() ?: 'ig-comment');
            $this->api->sendPrivateReply($igUserId, $commentId, str_replace('{ссылка}', $link, $template), $token);
            $row->markSent();
            $ok = true;
        } catch (InstagramApiException $e) {
            $row->markFailed($e->getMessage(), $e->isPermanent());
            $ok = false;
        } catch (\Throwable $e) {
            $row->markFailed($e::class . ': ' . $e->getMessage(), false);
            $ok = false;
        }
        $this->em->flush();

        return $ok;
    }
}
