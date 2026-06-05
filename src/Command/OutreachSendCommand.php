<?php

namespace App\Command;

use App\Entity\Brand;
use App\Service\Outreach\BrandOutreachMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Warmup-фаза email-активации (дизайн: КОНФЛИКТ дрип-случайности и когорты A решён так):
 * первые письма шлёт ЭТА команда по когорте A — опубликованные (active) бренды
 * с живыми данными (есть магазины, есть валидный email с MX) — малыми батчами
 * (10→15→25 за 3 дня, цель 0 жалоб / open ≥20%). Только ПОСЛЕ успешного warmup
 * включается авто-врезка в publish-tick (env OUTREACH_AUTO=1).
 *
 *   php bin/console app:outreach:send 10 --dry-run     # посмотреть, кому уйдёт
 *   php bin/console app:outreach:send 10               # день 1 warmup
 */
#[AsCommand(
    name: 'app:outreach:send',
    description: 'Warmup: activation-письма когорте A (опубликованные бренды с данными)',
)]
class OutreachSendCommand extends Command
{
    private const SLEEP_BETWEEN_SEC = 20; // не залпом: размазываем батч (антиспам-паттерн)

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BrandOutreachMailer $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('limit', InputArgument::OPTIONAL, 'Сколько писем отправить', 10)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать получателей, не слать')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $limit  = max(1, (int) $input->getArgument('limit'));
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Outreach · warmup-отправка (когорта A)');
        if (!$this->mailer->isConfigured() && !$dryRun) {
            $io->error('Не настроено: OUTREACH_FROM / OUTREACH_BASE_URL / MAILER_DSN');
            return Command::FAILURE;
        }

        // Когорта A: опубликован (active) + есть email + есть магазины (живой бизнес,
        // персонализация письма) + письмо ещё не отправлялось и адрес не суппрессирован.
        $ids = $this->em->getConnection()->fetchFirstColumn(
            "SELECT b.id FROM brand b
             WHERE b.status = 'active'
               AND b.email IS NOT NULL AND b.email != ''
               AND EXISTS (SELECT 1 FROM brand_store s WHERE s.brand_id = b.id AND s.status = 'active')
               AND NOT EXISTS (SELECT 1 FROM brand_outreach o WHERE o.brand_id = b.id AND o.sent_at IS NOT NULL)
               AND NOT EXISTS (SELECT 1 FROM brand_outreach o2 WHERE o2.email = b.email
                               AND (o2.unsubscribed_at IS NOT NULL OR o2.bounced_at IS NOT NULL))
             ORDER BY b.id ASC
             LIMIT " . $limit,
        );

        if ($ids === []) {
            $io->success('Когорта A исчерпана (или пока пуста).');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Кандидатов: %d', count($ids)));
        $sent = $skipped = 0;

        foreach ($ids as $id) {
            $brand = $this->em->find(Brand::class, (int) $id);
            if ($brand === null) {
                continue;
            }

            // Дешёвая MX-валидация до отправки (гигиена базы — дизайн маркетолога)
            $domain = substr(strrchr((string) $brand->getEmail(), '@') ?: '', 1);
            if ($domain === '' || !checkdnsrr($domain, 'MX')) {
                $io->text(sprintf('  ✗ %s: нет MX у %s — пропуск', $brand->getTitle(), $domain ?: '?'));
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $io->text(sprintf('  → %s <%s> /ru/brands/%s', $brand->getTitle(), $brand->getEmail(), $brand->getSlug()));
                $sent++;
                continue;
            }

            if ($this->mailer->sendFor($brand)) {
                $io->text(sprintf('  ✓ %s <%s>', $brand->getTitle(), $brand->getEmail()));
                $sent++;
                sleep(self::SLEEP_BETWEEN_SEC);
            } else {
                $io->text(sprintf('  ✗ %s: пропуск (suppression/ошибка — см. brand_outreach.last_error)', $brand->getTitle()));
                $skipped++;
            }
        }

        $io->success(sprintf('%s: %d, пропущено: %d', $dryRun ? 'Будет отправлено' : 'Отправлено', $sent, $skipped));

        return Command::SUCCESS;
    }
}
