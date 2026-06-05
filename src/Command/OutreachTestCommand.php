<?php

namespace App\Command;

use App\Entity\Brand;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Тест outreach-письма: реальный шаблон/заголовки/SMTP, но НА УКАЗАННЫЙ адрес
 * (не владельцу бренда!) и с тестовым токеном — БД не трогается, трекинг
 * не считается. Для проверки рендера в Mail.ru/Яндекс/Gmail и SMTP-связки.
 *
 *   php bin/console app:outreach:test you@example.com --brand=1
 */
#[AsCommand(
    name: 'app:outreach:test',
    description: 'Outreach: тестовое письмо реальным шаблоном на указанный адрес',
)]
class OutreachTestCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        #[Autowire('%env(default::OUTREACH_FROM)%')]
        private readonly ?string $from,
        #[Autowire('%env(default::OUTREACH_BASE_URL)%')]
        private readonly ?string $baseUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('to', InputArgument::REQUIRED, 'Куда слать тест')
            ->addArgument('brand', InputArgument::OPTIONAL, 'ID бренда для подстановки', '1')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $brand = $this->em->find(Brand::class, (int) $input->getArgument('brand'));
        if ($brand === null) {
            $io->error('Бренд не найден');
            return Command::FAILURE;
        }

        $base  = rtrim((string) $this->baseUrl, '/');
        $token = 'test' . bin2hex(random_bytes(14)); // невалидный для БД — трекинг не запишется

        $msg = (new TemplatedEmail())
            ->from(Address::create((string) $this->from))
            ->to((string) $input->getArgument('to'))
            ->replyTo('hello@wearbase.ru')
            ->subject(sprintf('[ТЕСТ] %s — мы опубликовали страницу о вашем бренде на Wearbase', $brand->getTitle()))
            ->htmlTemplate('email/outreach/brand_published.html.twig')
            ->textTemplate('email/outreach/brand_published.txt.twig')
            ->context([
                'brand'     => $brand,
                'stores'    => $brand->getActiveStores()->slice(0, 2),
                'click_url' => "{$base}/e/c/{$token}",
                'pixel_url' => "{$base}/e/o/{$token}.gif",
                'unsub_url' => "{$base}/e/u/{$token}",
            ]);
        $msg->getHeaders()->addTextHeader('List-Unsubscribe', "<{$base}/e/u/{$token}>");
        $msg->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $this->mailer->send($msg);
        $io->success(sprintf('Отправлено на %s (бренд: %s)', $input->getArgument('to'), $brand->getTitle()));

        return Command::SUCCESS;
    }
}
