<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Brand;
use App\Service\Outreach\BrandOutreachMailer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Реальная отправка тёплого оффера «Размещение под ключ» 5000₽ (docs/sales_offer.md)
 * кандидатам из outreach_log (type=warm_offer_draft, status=drafted), задрафтованным
 * app:outreach:warm-refresh. ЧЕЛОВЕК-ГЕЙТ той команды не отменяется — эта команда лишь
 * выполняет решение человека по уже одобренному списку.
 *
 * Whitelist (та же логика, что у холодной когорты OutreachSendCommand): подтверждённо
 * российский бренд (origin_status='ru'), есть email, домен email не в денилисте
 * агрегаторов/рекрутинга. Guard'ы отправки (suppression/foreign-niche/domain-mismatch) —
 * в BrandOutreachMailer::sendWarmOfferFor().
 *
 *   php bin/console app:outreach:send-warm 10 --dry-run   # кому уйдёт, ничего не слать
 *   php bin/console app:outreach:send-warm 10             # реальная отправка
 *   php bin/console app:outreach:send-warm --id=391 --to=you@example.com   # ТЕСТ на свой ящик
 */
#[AsCommand(
    name: 'app:outreach:send-warm',
    description: 'Тёплый оффер 5000₽: реальная отправка одобренным кандидатам outreach_log (warm_offer_draft)',
)]
class OutreachSendWarmCommand extends Command
{
    private const LOG_TYPE = 'warm_offer_draft';
    /** Известный мусор: агрегатор (zoon.ru) и рекрутинг-хостинг (h.careers) вместо реального контакта бренда. */
    private const EMAIL_DOMAIN_DENYLIST = ['zoon.ru', 'h.careers'];

    public function __construct(
        private readonly Connection $db,
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
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Только этот бренд (ID)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'ТЕСТ: письмо реальным текстом на этот адрес вместо email бренда (требует --id, БД не трогает)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $limit   = max(1, (int) $input->getArgument('limit'));
        $dryRun  = (bool) $input->getOption('dry-run');
        $idOpt   = $input->getOption('id') !== null ? (int) $input->getOption('id') : null;
        $testTo  = $input->getOption('to');

        if ($testTo !== null && $idOpt === null) {
            $io->error('--to требует --id=<brand_id> (тест шлётся по одному бренду)');
            return Command::FAILURE;
        }

        $io->title('Тёплый оффер 5000₽ · отправка одобренным кандидатам');

        $candidates = $this->fetchCandidates($idOpt, $idOpt !== null ? 1 : $limit);

        if ($testTo !== null) {
            if ($candidates === []) {
                $io->error('Бренд не найден среди кандидатов warm-офера (проверь ID/фильтры).');
                return Command::FAILURE;
            }
            $brand = $this->em->find(Brand::class, $candidates[0]['id']);
            if ($brand === null) {
                $io->error('Бренд не найден в БД.');
                return Command::FAILURE;
            }

            $status = $this->mailer->sendWarmOfferTest($brand, $testTo);
            $io->success(sprintf('ТЕСТ отправлен на %s (бренд: %s, RuSender %d)', $testTo, $brand->getTitle(), $status));

            return Command::SUCCESS;
        }

        if ($candidates === []) {
            $io->success('Одобренных кандидатов нет (outreach_log пуст либо все уже отправлены).');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Кандидатов: %d', count($candidates)));
        $sent = $skipped = 0;

        foreach ($candidates as $c) {
            if ($dryRun) {
                $io->text(sprintf('  → %s <%s> origin=%s /ru/brands/%s', $c['title'], $c['email'], $c['origin_status'], $c['slug']));
                $sent++;
                continue;
            }

            $brand = $this->em->find(Brand::class, $c['id']);
            if ($brand === null) {
                $io->text(sprintf('  ✗ id=%d: бренд не найден', $c['id']));
                $skipped++;
                continue;
            }

            if ($this->mailer->sendWarmOfferFor($brand)) {
                $this->db->executeStatement(
                    "UPDATE outreach_log SET status = 'sent', sent_at = :now WHERE brand_id = :brand_id AND type = :type",
                    ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'brand_id' => $c['id'], 'type' => self::LOG_TYPE],
                );
                $io->text(sprintf('  ✓ %s <%s>', $c['title'], $c['email']));
                $sent++;
            } else {
                $io->text(sprintf('  ✗ %s: пропуск (suppression/guard — см. brand_outreach.last_error)', $c['title']));
                $skipped++;
            }
        }

        $io->success(sprintf('%s: %d, пропущено: %d', $dryRun ? 'Будет отправлено' : 'Отправлено', $sent, $skipped));

        return Command::SUCCESS;
    }

    /**
     * @return list<array{id:int,title:string,slug:string,email:string,origin_status:string}>
     */
    private function fetchCandidates(?int $onlyId, int $limit): array
    {
        $rows = $this->db->fetchAllAssociative(
            <<<'SQL'
                SELECT b.id, b.title, b.slug, b.email, b.origin_status
                FROM outreach_log o
                JOIN brand b ON b.id = o.brand_id
                WHERE o.type = 'warm_offer_draft'
                  AND o.status = 'drafted'
                  AND b.origin_status = 'ru'
                  AND b.email IS NOT NULL AND b.email != ''
                ORDER BY b.id ASC
            SQL,
        );

        $domainOk = static function (string $email): bool {
            $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));

            return $domain !== '' && !in_array($domain, self::EMAIL_DOMAIN_DENYLIST, true);
        };

        $filtered = array_values(array_filter(
            $rows,
            static fn (array $r): bool => $domainOk((string) $r['email']) && ($onlyId === null || (int) $r['id'] === $onlyId),
        ));

        return array_map(static fn (array $r): array => [
            'id'            => (int) $r['id'],
            'title'         => (string) $r['title'],
            'slug'          => (string) $r['slug'],
            'email'         => (string) $r['email'],
            'origin_status' => (string) $r['origin_status'],
        ], array_slice($filtered, 0, $limit));
    }
}
