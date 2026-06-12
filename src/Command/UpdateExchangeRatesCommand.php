<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ExchangeRate;
use App\Repository\CurrencyRepository;
use App\Repository\ExchangeRateRepository;
use App\Service\CurrencyConverter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Консольная команда: обновляет курсы валют из внешних источников.
 *
 * Источники (приоритет):
 *   1. ЦБ РФ (cbr.ru) — бесплатно, без ключа, для RUB-пар
 *   2. Fixer.io / OpenExchangeRates — платные, для кросс-курсов
 *
 * Запуск:
 *   php bin/console app:currency:update-rates
 *   php bin/console app:currency:update-rates --source=cbr
 *   php bin/console app:currency:update-rates --dry-run
 *
 * Планировщик (cron): ежедневно в 12:00
 *   0 12 * * * php /path/to/project/bin/console app:currency:update-rates
 */
#[AsCommand(
    name: 'app:currency:update-rates',
    description: 'Обновляет курсы валют из ЦБ РФ (cbr.ru) или Fixer.io',
)]
class UpdateExchangeRatesCommand extends Command
{
    /** XML-фид ЦБ РФ — публичный, без ключа */
    private const CBR_URL = 'https://www.cbr.ru/scripts/XML_daily.asp';

    public function __construct(
        private readonly CurrencyRepository     $currencyRepo,
        private readonly ExchangeRateRepository $rateRepo,
        private readonly EntityManagerInterface $em,
        private readonly CurrencyConverter      $converter,
        private readonly HttpClientInterface    $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', 's', InputOption::VALUE_OPTIONAL,
                'Источник курсов: cbr (default), fixer', 'cbr')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Показать что будет сохранено, но не писать в БД')
            ->addOption('date', 'd', InputOption::VALUE_OPTIONAL,
                'Дата котировки в формате DD/MM/YYYY (по умолчанию — сегодня)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $source = (string) $input->getOption('source');
        $dateOpt = $input->getOption('date');

        $io->title('Обновление курсов валют');

        if ($dryRun) {
            $io->warning('Режим dry-run: данные НЕ будут сохранены в БД');
        }

        // Загружаем базовую валюту (RUB)
        $baseCurrency = $this->currencyRepo->findBase();
        if (!$baseCurrency) {
            $io->error('Базовая валюта не найдена. Создайте валюту RUB с флагом is_base=true.');
            return Command::FAILURE;
        }

        $io->text(sprintf('Базовая валюта: %s', $baseCurrency->getCode()));

        // Загружаем активные валюты для обновления
        $targetCurrencies = $this->currencyRepo->findActive();

        $rates = match ($source) {
            'cbr'   => $this->fetchFromCbr($io, $dateOpt),
            default => $this->fetchFromCbr($io, $dateOpt),
        };

        if (empty($rates)) {
            $io->error('Не удалось получить курсы валют.');
            return Command::FAILURE;
        }

        $rateDate = new \DateTimeImmutable('today');
        $saved = 0;
        $skipped = 0;

        $io->section('Обрабатываем курсы');
        $rows = [];

        foreach ($targetCurrencies as $targetCurrency) {
            if ($targetCurrency->getCode() === $baseCurrency->getCode()) {
                continue; // не делаем RUB→RUB
            }

            $code = $targetCurrency->getCode();

            if (!isset($rates[$code])) {
                $io->text(sprintf('  ⚠ Курс для %s не найден в ответе источника', $code));
                $skipped++;
                continue;
            }

            $rate = $rates[$code]; // float: 1 RUB = N {code}

            $rows[] = [$baseCurrency->getCode(), $code, number_format($rate, 8, '.', ''), 'cbr'];

            if ($dryRun) {
                $saved++;
                continue;
            }

            // Upsert: ищем запись за сегодня, обновляем или создаём
            $existing = $this->rateRepo->findLatest($baseCurrency, $targetCurrency);
            $today    = $rateDate->format('Y-m-d');

            if ($existing && $existing->getRateDate()->format('Y-m-d') === $today) {
                $existing->setRate((string) $rate);
                $existing->setSource('cbr');
            } else {
                $er = new ExchangeRate();
                $er->setBaseCurrency($baseCurrency);
                $er->setTargetCurrency($targetCurrency);
                $er->setRate((string) $rate);
                $er->setRateDate($rateDate);
                $er->setSource('cbr');
                $this->em->persist($er);
            }

            $saved++;
        }

        $io->table(['Из', 'В', 'Курс', 'Источник'], $rows);

        if (!$dryRun) {
            $this->em->flush();
            $this->converter->clearCache();
            $io->success(sprintf('Сохранено: %d курсов, пропущено: %d', $saved, $skipped));
        } else {
            $io->info(sprintf('[dry-run] Будет сохранено: %d курсов, пропущено: %d', $saved, $skipped));
        }

        return Command::SUCCESS;
    }

    /**
     * Загружает курсы из XML-фида ЦБ РФ.
     *
     * ЦБ публикует курсы валют к RUB.
     * Формат: 1 единица иностранной валюты = N рублей.
     * Нам нужен обратный: 1 RUB = (1/N) единиц иностранной валюты.
     *
     * @return array<string, float>  ['USD' => 0.011, 'EUR' => 0.010, …]
     */
    private function fetchFromCbr(SymfonyStyle $io, ?string $dateStr = null): array
    {
        $url = self::CBR_URL;
        if ($dateStr) {
            $url .= '?date_req=' . $dateStr; // DD/MM/YYYY
        }

        $io->text(sprintf('Запрос к ЦБ РФ: %s', $url));

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 10,
                'headers' => ['Accept' => 'application/xml, text/xml'],
            ]);

            $xml = new \SimpleXMLElement($response->getContent());
        } catch (\Throwable $e) {
            $io->error(sprintf('Ошибка запроса ЦБ РФ: %s', $e->getMessage()));
            return [];
        }

        $rates = [];

        foreach ($xml->Valute as $valute) {
            $charCode = (string) $valute->CharCode;
            $nominal  = (int)   $valute->Nominal;
            $valueStr = str_replace(',', '.', (string) $valute->Value);
            $valueRub = (float) $valueStr; // N рублей за nominal единиц валюты

            if ($nominal <= 0 || $valueRub <= 0) {
                continue;
            }

            // Курс: 1 RUB = ? единиц валюты
            $rates[$charCode] = $nominal / $valueRub;
        }

        $io->text(sprintf('  Получено курсов: %d', count($rates)));

        return $rates;
    }
}
