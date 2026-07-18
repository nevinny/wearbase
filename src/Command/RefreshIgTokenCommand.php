<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SocialChannel;
use App\Repository\SocialChannelRepository;
use App\Service\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Продление долгоживущего IG-токена (Instagram Login, ~60 дней). Токен можно рефрешить, когда
 * ему >24ч и до истечения — поэтому гоняем еженедельно, чтобы срок всегда откладывался вперёд.
 * refresh_access_token не требует client_secret — только текущий токен.
 * Гонять с Mac (egress к Meta). Новый токен кладём в social_channel.token_enc (источник истины
 * для паблишера).
 */
#[AsCommand(name: 'app:social:refresh-ig-token', description: 'Продлить долгоживущий IG-токен (Instagram Login)')]
class RefreshIgTokenCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SocialChannelRepository $channels,
        private readonly SecretCipher $cipher,
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $channel = null;
        foreach ($this->channels->findAll() as $c) {
            if ($c->getPlatform() === SocialChannel::PLATFORM_IG) {
                $channel = $c;
                break;
            }
        }
        if ($channel === null) {
            $io->note('IG-канал не найден — нечего продлевать.');
            return Command::SUCCESS;
        }

        $enc = $channel->getTokenEnc();
        if ($enc === null || $enc === '') {
            $io->warning('У IG-канала нет токена.');
            return Command::SUCCESS;
        }
        $current = $this->cipher->decrypt($enc);

        $data = $this->httpClient->request('GET', 'https://graph.instagram.com/refresh_access_token', [
            'query'   => ['grant_type' => 'ig_refresh_token', 'access_token' => $current],
            'timeout' => 30,
        ])->toArray(false);

        $newToken = $data['access_token'] ?? null;
        if ($newToken === null) {
            $io->error('refresh_access_token не вернул токен: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
            return Command::FAILURE;
        }

        $channel->setTokenEnc($this->cipher->encrypt($newToken));
        $this->em->flush();

        $io->success(sprintf('IG-токен продлён (expires_in=%s сек ≈ %d дн.).',
            $data['expires_in'] ?? '?', (int) (($data['expires_in'] ?? 0) / 86400)));
        return Command::SUCCESS;
    }
}
