<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SocialChannel;
use App\Service\SecretCipher;
use App\Social\Castbase\CastbaseClient;
use App\Social\Castbase\CastbaseException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Подключить IG-аккаунт канала wearbase к проекту castbase (POST /v1/channels, BYO-токен): токен расшифровывается из
 * social_channel.token_enc и уходит только в тело запроса — в консоль и логи не попадает. publish_via НЕ переключается:
 * это отдельный осознанный шаг.
 *
 * castbase отвечает 202 pending и проверяет токен асинхронно: команда ждёт итога (GET /v1/channels/{id}) и пишет id в
 * social_channel.castbase_channel_id только при active. Повторный запуск с тем же токеном и тем же прежним каналом
 * шлёт тот же Idempotency-Key — castbase вернёт тот же pending-канал, и команда дождётся его. Уже active канал не
 * переподключается; после reauth_required/failed/отключения ключ другой (в нём id прежнего канала) — новое подключение.
 */
#[AsCommand(name: 'app:castbase:connect-ig', description: 'Подключить IG-канал wearbase к castbase (токен из social_channel)')]
class CastbaseConnectIgCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CastbaseClient $castbase,
        private readonly SecretCipher $cipher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'id social_channel (по умолчанию — единственный IG-канал с токеном)')
            ->addOption('wait', null, InputOption::VALUE_REQUIRED, 'Сколько секунд ждать итога проверки токена (pending → active/failed)', '90')
            ->addOption('attempt', null, InputOption::VALUE_REQUIRED, 'Номер попытки: новый номер = новое подключение тем же токеном (после failed по временной причине)', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $repo = $this->em->getRepository(SocialChannel::class);
        if ($input->getOption('channel') !== null) {
            $channel = $repo->find((int) $input->getOption('channel'));
        } else {
            $all = array_values(array_filter(
                $repo->findBy(['platform' => SocialChannel::PLATFORM_IG]),
                static fn (SocialChannel $c) => $c->getHasToken(),
            ));
            if (count($all) > 1) {
                $io->error('Несколько IG-каналов с токеном — укажи --channel=<id>.');
                return Command::FAILURE;
            }
            $channel = $all[0] ?? null;
        }
        if (!$channel instanceof SocialChannel || $channel->getPlatform() !== SocialChannel::PLATFORM_IG || !$channel->getHasToken()) {
            $io->error('IG-канал с токеном не найден.');
            return Command::FAILURE;
        }

        $previous = $channel->getCastbaseChannelId();
        if ($previous !== null && $previous !== '') {
            try {
                $current = $this->castbase->getChannel($previous);
            } catch (CastbaseException $e) {
                if ($e->status !== 404) {
                    $io->error($e->getMessage());
                    return Command::FAILURE;
                }
                $current = ['status' => 'deleted'];
            }
            if (($current['status'] ?? null) === 'active') {
                $io->success(sprintf('Канал #%d уже подключён к castbase: %s (active, @%s). Ничего не делаем.', $channel->getId(), $previous, (string) ($current['username'] ?? '?')));
                return Command::SUCCESS;
            }
            $io->text(sprintf('Прежний castbase-канал %s в статусе %s — подключаем заново.', $previous, (string) ($current['status'] ?? '?')));
        }

        $token = $this->cipher->decrypt((string) $channel->getTokenEnc());
        // Ключ привязан к токену (хеш, не сам токен), к прежнему castbase-каналу и номеру попытки: сетевой повтор того же
        // подключения не создаёт второй канал, а переподключение после reauth/failed — не реплей старого ответа (72 ч).
        $key = sprintf(
            'wb-ch-%d-%s-%s-a%d',
            $channel->getId(),
            substr(hash('sha256', $token), 0, 16),
            $previous !== null && $previous !== '' ? substr(hash('sha256', $previous), 0, 8) : 'new',
            max(1, (int) $input->getOption('attempt')),
        );
        try {
            $result = $this->castbase->connectInstagram($token, $channel->getName() ?: 'wearbase', $key);
            $castbaseId = (string) ($result['id'] ?? '');
            if ($castbaseId === '') {
                throw new CastbaseException('castbase: в ответе /v1/channels нет id');
            }
            $deadline = time() + max(0, (int) $input->getOption('wait'));
            while (($result['status'] ?? null) === 'pending' && time() < $deadline) {
                sleep(3);
                $result = $this->castbase->getChannel($castbaseId);
            }
            if (($result['status'] ?? null) === 'pending') {
                $result = $this->castbase->getChannel($castbaseId);
            }
        } catch (CastbaseException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $status = (string) ($result['status'] ?? '?');
        if ($status !== 'active') {
            $io->error(sprintf(
                'castbase-канал %s: %s%s. castbase_channel_id не изменён. %s',
                $castbaseId,
                $status,
                isset($result['status_reason']) ? ' (' . $result['status_reason'] . ': ' . (string) ($result['status_message'] ?? '') . ')' : '',
                $status === 'pending'
                    ? 'Проверка ещё идёт — запусти команду ещё раз, она дождётся этого же канала.'
                    : 'Новый токен — просто запусти снова; тот же токен после временного сбоя — с --attempt=<n+1>.',
            ));
            return Command::FAILURE;
        }

        $channel->setCastbaseChannelId($castbaseId);
        $this->em->flush();

        $io->success(sprintf(
            'Канал #%d подключён к castbase: %s (active, @%s, %s). Переключить публикацию: publish_via=castbase (админка «Соцсети: каналы»).',
            $channel->getId(),
            $castbaseId,
            (string) ($result['username'] ?? '?'),
            (string) ($result['kind'] ?? '?'),
        ));

        return Command::SUCCESS;
    }
}
