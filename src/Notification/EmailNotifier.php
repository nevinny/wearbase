<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

readonly class EmailNotifier
{
    /**
     * From обязан быть на домене, подтверждённом в RuSender: проверено с прода 2026-07-26 —
     * `hello@mail.wearbase.ru` даёт 201, а `hello@wearbase.ru` и любой сторонний адрес
     * (напр. ADMIN_EMAIL=nevinny@gmail.com, который стоял здесь раньше) — 404 «User Domain
     * not found». ADMIN_EMAIL остаётся адресом ПОЛУЧАТЕЛЯ админских уведомлений и Reply-To.
     */
    private const DEFAULT_FROM = 'WEARBASE <hello@mail.wearbase.ru>';

    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%env(ADMIN_EMAIL)%')]
        private string $adminEmail,
        private LoggerInterface $logger,
        #[Autowire('%env(default::MAILER_FROM)%')]
        private ?string $from = null,
    ) {}

    public function getAdminEmail(): string
    {
        return $this->adminEmail;
    }

    /** "WEARBASE <hello@mail.wearbase.ru>" → Address; без имени тоже принимаем. */
    private function fromAddress(): Address
    {
        $raw = trim((string) ($this->from ?: self::DEFAULT_FROM));

        if (preg_match('/^(.*)<([^>]+)>$/', $raw, $m) === 1) {
            return new Address(trim($m[2]), trim($m[1]));
        }

        return new Address($raw, 'WEARBASE');
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return bool false — письмо НЕ ушло (ошибка залогирована). Вызовы, которым важен
     *              факт отправки (ручные команды), обязаны проверять результат: soft-fail
     *              иначе превращается в «отчитались об успехе, письма нет».
     */
    public function send(User|string $recipient, string $subject, string $template, array $context = []): bool
    {
        if ($recipient instanceof User) {
            $to = new Address((string) $recipient->getEmail(), $recipient->getFullName());
            $context['user'] = $recipient;
        } else {
            $to = new Address($recipient, $recipient);
            $context['user'] = (new User())->setEmail($recipient)->setFirstName($recipient);
        }

        $email = (new TemplatedEmail())
            ->from($this->fromAddress())
            ->to($to)
            ->subject($subject)
            ->htmlTemplate("emails/{$template}.html.twig")
            ->context($context);

        // Ответы владельцу, а не в noreply на поддомене рассылки: пригласительные и
        // сервисные письма прямо просят ответить (название бренда, «это не я»).
        if ($this->adminEmail !== '' && $to->getAddress() !== $this->adminEmail) {
            $email->replyTo(new Address($this->adminEmail));
        }

        try {
            $this->mailer->send($email);

            return true;
        } catch (\Throwable $e) {
            // soft-fail: письмо не должно ронять заказ, но молчать об ошибке нельзя
            $this->logger->error('Email notification failed', [
                'to' => $to->getAddress(),
                'subject' => $subject,
                'template' => $template,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
