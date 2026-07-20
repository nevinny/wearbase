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
            ->addOption('slugs',   null, InputOption::VALUE_REQUIRED, 'Точечный батч: слаги через запятую (вычитанные кандидаты cold-эксперимента) вместо авто-когорты')
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

        // Точечный батч (--slugs): вычитанные кандидаты cold-эксперимента (sales_offer.md §5-6).
        // Требования те же, что у когорты: active (страница живёт — об этом письмо) + email;
        // suppression/дубль проверит mailer. Порядок слагов сохраняется.
        $slugsOpt = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('slugs')))));
        if ($slugsOpt !== []) {
            $ids = [];
            foreach ($slugsOpt as $slug) {
                $row = $this->em->getConnection()->fetchAssociative(
                    "SELECT id, status, COALESCE(email,'') email FROM brand WHERE slug = :s", ['s' => $slug],
                );
                if ($row === false) {
                    $io->text("  ✗ {$slug}: не найден — пропуск");
                } elseif ($row['status'] !== 'active') {
                    $io->text("  ✗ {$slug}: не опубликован (status={$row['status']}) — сначала push --publish");
                } elseif ($row['email'] === '') {
                    $io->text("  ✗ {$slug}: нет email — пропуск");
                } else {
                    $ids[] = (int) $row['id'];
                }
            }
            $ids = array_slice($ids, 0, $limit);
        } else {
        // Когорта: ОПУБЛИКОВАННЫЙ бренд (active — его страница реально живёт, об этом и
        // письмо) + есть email + не отправляли + адрес не суппрессирован. Магазины НЕ
        // требуем (на проде их нет, а крючок теперь каналы/ассортимент). Приоритет —
        // у кого больше данных (магазины/категории = богаче письмо), потом свежие.
        $ids = $this->em->getConnection()->fetchFirstColumn(
            "SELECT b.id FROM brand b
             WHERE b.status = 'active'
               AND b.email IS NOT NULL AND b.email != ''
               AND NOT EXISTS (SELECT 1 FROM brand_outreach o WHERE o.brand_id = b.id AND o.sent_at IS NOT NULL)
               AND NOT EXISTS (SELECT 1 FROM brand_outreach o2 WHERE o2.email = b.email
                               AND (o2.unsubscribed_at IS NOT NULL OR o2.bounced_at IS NOT NULL))
               -- Инцидент: письма ушли иностранным брендам (Tissot, Agent Provocateur) —
               -- когорта не фильтровала origin/niche. Blacklist (!= 'foreign') недостаточен:
               -- масса иностранных брендов (Lee, Piquadro, Camp David) сидит в origin='unknown'.
               -- Для холодной рассылки — строгий whitelist: пишем ТОЛЬКО подтверждённо российским
               -- (origin='ru' даёт 175 кандидатов, с запасом). Ср. PipelineQueueRepository::applyGates().
               AND b.origin_status = 'ru'
               AND (b.niche_status IS NULL OR b.niche_status != 'off')
             ORDER BY
               (SELECT COUNT(*) FROM brand_store s WHERE s.brand_id = b.id) DESC,
               (SELECT COUNT(*) FROM brand_attribute a WHERE a.brand_id = b.id) DESC,
               b.id ASC
             LIMIT " . $limit,
        );
        }

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
