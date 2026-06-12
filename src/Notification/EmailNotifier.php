<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

readonly class EmailNotifier
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%env(ADMIN_EMAIL)%')]
        private string $adminEmail,
    ) {}

    public function getAdminEmail(): string
    {
        return $this->adminEmail;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function send(User|string $recipient, string $subject, string $template, array $context = []): void
    {
        if ($recipient instanceof User) {
            $to = new Address((string) $recipient->getEmail(), $recipient->getFullName());
            $context['user'] = $recipient;
        } else {
            $to = new Address($recipient, $recipient);
            $context['user'] = (new User())->setEmail($recipient)->setFirstName($recipient);
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->adminEmail, 'WEARBASE'))
            ->to($to)
            ->subject($subject)
            ->htmlTemplate("emails/{$template}.html.twig")
            ->context($context);

        $this->mailer->send($email);
    }
}
