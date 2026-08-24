<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ExternalNotificationOutbox;
use App\Entity\Notification;
use App\Notification\EmailNotifier;
use App\Notification\TelegramNotifier;
use App\Notification\WebPushPublisherInterface;
use App\Repository\ExternalNotificationOutboxRepository;
use App\Repository\NotificationSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:notification:deliver-outbox', description: 'Deliver pending external notification outbox messages')]
class DeliverExternalNotificationsCommand extends Command
{
    public function __construct(
        private readonly ExternalNotificationOutboxRepository $outbox,
        private readonly NotificationSettingsRepository $settings,
        private readonly EmailNotifier $email,
        private readonly TelegramNotifier $telegram,
        private readonly WebPushPublisherInterface $webPush,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum messages', '100')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report pending messages without claiming or sending them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, min(1000, (int) $input->getOption('limit')));
        if ($input->getOption('dry-run')) {
            $count = $this->outbox->count(['status' => ExternalNotificationOutbox::STATUS_PENDING]);
            $output->writeln(sprintf('%d pending external notification(s).', $count));
            return Command::SUCCESS;
        }

        $sent = $retried = 0;
        for ($i = 0; $i < $limit; ++$i) {
            $now = new \DateTimeImmutable();
            $message = $this->outbox->claimNext($now);
            if ($message === null) {
                break;
            }

            try {
                if (!$this->isStillEnabled($message)) {
                    $message->markSent($now);
                    $ok = true;
                } else {
                    $ok = $this->deliver($message);
                    $ok ? $message->markSent($now) : $message->retry($now, 'Notifier returned false');
                }
            } catch (\Throwable $e) {
                $ok = false;
                $message->retry($now, $e->getMessage());
            }
            $this->em->flush();
            $ok ? ++$sent : ++$retried;
        }

        $output->writeln(sprintf('%d sent/skipped, %d scheduled for retry.', $sent, $retried));
        return Command::SUCCESS;
    }

    private function isStillEnabled(ExternalNotificationOutbox $message): bool
    {
        $settings = $this->settings->findOneBy([
            'user' => $message->getRecipient(),
            'eventType' => $message->getNotificationType(),
        ]);
        if ($settings === null) {
            return $message->getChannel() === Notification::CHANNEL_EMAIL;
        }
        return match ($message->getChannel()) {
            Notification::CHANNEL_EMAIL => $settings->isChannelEmail(),
            Notification::CHANNEL_TELEGRAM => $settings->isChannelTelegram(),
            Notification::CHANNEL_PUSH => $settings->isChannelPush(),
            default => false,
        };
    }

    private function deliver(ExternalNotificationOutbox $message): bool
    {
        $payload = $message->getPayload();
        if ($message->getChannel() === Notification::CHANNEL_EMAIL) {
            return $this->email->sendHtml((string) $payload['to'], (string) $payload['name'], (string) $payload['subject'], (string) $payload['html']);
        }
        if ($message->getChannel() === Notification::CHANNEL_TELEGRAM) {
            return $this->telegram->send((string) $payload['chatId'], (string) $payload['text']);
        }
        if ($message->getChannel() === Notification::CHANNEL_PUSH) {
            return $this->webPush->send($message->getRecipient(), $payload);
        }
        throw new \LogicException('Unsupported external notification channel.');
    }
}
