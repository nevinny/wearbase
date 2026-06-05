<?php

namespace App\Command;

use App\Entity\Brand;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Environment;

/**
 * Тест outreach-письма через RuSender REST API (тот же путь, что прод-warmup),
 * но НА УКАЗАННЫЙ адрес (не владельцу!) с тестовым токеном — БД/трекинг не трогаются.
 * Проверка рендера в Mail.ru/Яндекс/Gmail и связки с RuSender.
 *
 *   php bin/console app:outreach:test you@example.com 1
 */
#[AsCommand(
    name: 'app:outreach:test',
    description: 'Outreach: тестовое письмо реальным шаблоном на указанный адрес (RuSender REST)',
)]
class OutreachTestCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        private readonly Environment $twig,
        private readonly \App\Service\Outreach\BrandOutreachMailer $mailer,
        #[Autowire('%env(default::OUTREACH_FROM)%')]
        private readonly ?string $from,
        #[Autowire('%env(default::OUTREACH_BASE_URL)%')]
        private readonly ?string $baseUrl,
        #[Autowire('%env(default::RUSENDER_API_KEY)%')]
        private readonly ?string $apiKey,
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

        if (trim((string) $this->apiKey) === '') {
            $io->error('RUSENDER_API_KEY не задан');
            return Command::FAILURE;
        }

        $base  = rtrim((string) $this->baseUrl, '/');
        $token = 'test' . bin2hex(random_bytes(14)); // невалидный для БД — трекинг не запишется
        $ctx = $this->mailer->buildContext($brand, $base, $token);

        $fromName = 'WEARBASE';
        $fromEmail = (string) $this->from;
        if (preg_match('~^(.*?)\s*<([^>]+)>$~', (string) $this->from, $m)) {
            $fromName = trim($m[1]) ?: 'WEARBASE';
            $fromEmail = trim($m[2]);
        }

        $resp = $this->httpClient->request('POST', 'https://api.beta.rusender.ru/api/v1/external-mails/send', [
            'headers' => ['X-Api-Key' => (string) $this->apiKey, 'Content-Type' => 'application/json'],
            'json'    => [
                'idempotencyKey' => $token,
                'mail' => [
                    'to'      => ['email' => (string) $input->getArgument('to')],
                    'from'    => ['email' => $fromEmail, 'name' => $fromName],
                    'subject' => sprintf('[ТЕСТ] %s — мы опубликовали страницу о вашем бренде на Wearbase', $brand->getTitle()),
                    'html'    => $this->twig->render('email/outreach/brand_published.html.twig', $ctx),
                    'text'    => $this->twig->render('email/outreach/brand_published.txt.twig', $ctx),
                ],
            ],
            'timeout' => 30,
        ]);

        $code = $resp->getStatusCode();
        if ($code >= 300) {
            $io->error(sprintf('RuSender HTTP %d: %s', $code, mb_substr($resp->getContent(false), 0, 300)));
            return Command::FAILURE;
        }
        $io->success(sprintf('Отправлено на %s (бренд: %s, RuSender %d)', $input->getArgument('to'), $brand->getTitle(), $code));

        return Command::SUCCESS;
    }
}
