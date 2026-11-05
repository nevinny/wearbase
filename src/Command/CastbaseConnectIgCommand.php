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
 * Подключить IG-аккаунт канала wearbase к проекту castbase (POST /v1/channels): токен расшифровывается из
 * social_channel.token_enc и уходит только в тело запроса — в консоль и логи не попадает. id канала castbase
 * сохраняется в social_channel.castbase_channel_id. publish_via НЕ переключается: это отдельный осознанный шаг.
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
        $this->addOption('channel', null, InputOption::VALUE_REQUIRED, 'id social_channel (по умолчанию — единственный IG-канал с токеном)');
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

        $token = $this->cipher->decrypt((string) $channel->getTokenEnc());
        // Ключ идемпотентности привязан к токену (хеш, не сам токен): повтор того же запроса не создаёт второй канал.
        $key = sprintf('wb-ch-%d-%s', $channel->getId(), substr(hash('sha256', $token), 0, 16));
        try {
            $result = $this->castbase->connectInstagram($token, $channel->getName() ?: 'wearbase', $key);
        } catch (CastbaseException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $channel->setCastbaseChannelId((string) ($result['id'] ?? ''));
        $this->em->flush();

        $io->success(sprintf(
            'Канал #%d подключён к castbase: %s (статус %s). Переключить публикацию: publish_via=castbase (админка «Соцсети: каналы»).',
            $channel->getId(),
            $channel->getCastbaseChannelId(),
            (string) ($result['status'] ?? '?'),
        ));

        return Command::SUCCESS;
    }
}
