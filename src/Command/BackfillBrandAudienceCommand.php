<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandAudience;
use App\Repository\BrandRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Бэкафилл разметки аудитории (`brand_audience`) из уже собранного текста бренда
 * (description/anons), детерминированно — регулярками, БЕЗ LLM. Готовит данные для
 * фасетных страниц (женская/мужская/детская одежда, см. docs/geo_city_demand_2026_09.md
 * §11) — сами страницы делает отдельная задача.
 *
 * Правила бинарные, без confidence: одному бренду может проставиться несколько
 * аудиторий сразу («мужские и женские вещи» — Мужчины И Женщины).
 * ⚠️ «женск», а не «женственн» — второе про стиль вещи (см. brand_style «zhenstvennyj»),
 * а не про то, для кого бренд шьёт; не должно матчиться как аудитория.
 *
 * Идемпотентна: Brand::addAudience()/BrandAudience::addBrand() сами проверяют contains()
 * перед добавлением в коллекцию, повторный прогон дублей в join-таблице не создаёт.
 * --force расширяет выборку на уже размеченные бренды (перепрогон регулярок после
 * правки правил) — только ДОБАВЛЯЕТ недостающие связи, ничего не снимает (в отличие от
 * app:brand:tag-styles, здесь удаление данных по действию пользователя запрещено).
 *
 * ⚠️ Выборка отсортирована по id и не исключает «без сигнала» — при дефолтном
 * --limit=500 бренды без совпадений остаются в очереди навсегда и за несколько
 * прогонов забивают всё окно (та же ловушка, что в findForContactEnrichment).
 * Боевой прогон делать ОДИН раз с запасом (--limit покрывает все активные бренды),
 * не полагаться на дефолт и повторные запуски мелкими лимитами.
 *
 *   php -d memory_limit=512M bin/console app:brand:audience-backfill --dry-run --limit=30
 *   php -d memory_limit=512M bin/console app:brand:audience-backfill --limit=3200
 */
#[AsCommand(
    name: 'app:brand:audience-backfill',
    description: 'Разметка brand_audience из description/anons регулярками, без LLM',
)]
class BackfillBrandAudienceCommand extends Command
{
    /** Заголовок BrandAudience (уже заведены в БД, новых не создаём) => регулярка по нижнему регистру текста. */
    private const RULES = [
        'Женщины' => '/женск|для женщин|женщинам/u',
        'Мужчины' => '/мужск|для мужчин|мужчинам/u',
        // «детск» голым корнем ловит не аудиторию, а риторику: «детские воспоминания»,
        // «детских целей», и худший случай — «отсутствие детского труда» (заявление об
        // этике производства у Vika Gazinskaya). Такой бренд попал бы на страницу детской
        // одежды. Замер: широкое правило 468 совпадений, узкое 366; из 102 отсеянных
        // почти все — шум. Поэтому прилагательное требуем в связке с предметным словом.
        'Дети'    => '/детск[а-я]+ (одежд|коллекц|лини|вещ|размер|мод|ассортимент)|для детей|подростк/u',
        'Унисекс' => '/унисекс|unisex/u',
    ];

    private const FLUSH_EVERY = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BrandRepository $brands,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Максимум брендов за прогон', 500)
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Только один бренд по ID (игнорирует остальную выборку)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать раскладку и примеры, не записывать')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Включить в выборку уже размеченные бренды (снятие связей не делает)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force  = (bool) $input->getOption('force');

        // Существующие сущности BrandAudience по title — заводить новые не наша задача.
        $audiences = [];
        foreach (self::RULES as $title => $pattern) {
            $audience = $this->em->getRepository(BrandAudience::class)->findOneBy(['title' => $title]);
            if ($audience === null) {
                $io->warning(sprintf('BrandAudience "%s" не найдена в справочнике — правило пропущено.', $title));
                continue;
            }
            $audiences[$title] = $audience;
        }

        if ($audiences === []) {
            $io->error('Ни одной аудитории из справочника не найдено — нечего проставлять.');
            return Command::FAILURE;
        }

        $brands = $this->select($input);
        if ($brands === []) {
            $io->success('Нечего обрабатывать (выборка пуста — все активные бренды уже размечены).');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Разметка аудитории: %d брендов%s', count($brands), $dryRun ? ' (dry-run)' : ''));

        $processed   = 0;
        $withSignal  = 0;
        $noSignal    = 0;
        $linksAdded  = 0;
        $perTitle    = array_fill_keys(array_keys($audiences), 0);
        $examples    = array_fill_keys(array_keys($audiences), []);

        foreach ($brands as $brand) {
            $processed++;
            // Схлопываем переносы строк/двойные пробелы: иначе «для\nженщин» не матчится,
            // а сниппет в dry-run разъезжается на несколько строк таблицы.
            $text = ($brand->getDescription() ?? '') . ' ' . ($brand->getAnons() ?? '');
            $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)));

            $matchedAny = false;
            foreach ($audiences as $title => $audience) {
                if (!preg_match(self::RULES[$title], $text, $m)) {
                    continue;
                }
                $matchedAny = true;
                $perTitle[$title]++;
                $linksAdded++;
                if (count($examples[$title]) < 5) {
                    $examples[$title][] = [$brand->getTitle() ?? ('#' . $brand->getId()), $this->snippet($text, $m[0])];
                }
                if (!$dryRun) {
                    $brand->addAudience($audience);
                }
            }

            $matchedAny ? $withSignal++ : $noSignal++;

            if (!$dryRun && $processed % self::FLUSH_EVERY === 0) {
                $this->em->flush();
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->table(['Показатель', 'Значение'], [
            ['Обработано брендов', $processed],
            ['С хотя бы одной аудиторией', $withSignal],
            ['Без сигнала', $noSignal],
            [$dryRun ? 'Связей к простановке' : 'Связей проставлено', $linksAdded],
        ]);
        $io->table(['Аудитория', $dryRun ? 'Брендов (к простановке)' : 'Брендов (проставлено)'],
            array_map(static fn ($title, $count) => [$title, $count], array_keys($perTitle), $perTitle));

        if ($dryRun) {
            foreach ($examples as $title => $rows) {
                if ($rows === []) {
                    continue;
                }
                $io->section(sprintf('%s — примеры', $title));
                $io->table(['Бренд', 'Фрагмент'], $rows);
            }
            $io->note('dry-run — ничего не записано.');
        }

        return Command::SUCCESS;
    }

    /** @return Brand[] */
    private function select(InputInterface $input): array
    {
        if (($id = $input->getOption('id')) !== null) {
            $brand = $this->em->find(Brand::class, (int) $id);
            return $brand !== null ? [$brand] : [];
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $force = (bool) $input->getOption('force');

        return $this->brands->findWithoutAudience($limit, $force);
    }

    /** Фрагмент текста вокруг найденного совпадения — чтобы судить о точности правила. */
    private function snippet(string $text, string $match): string
    {
        $pos = mb_stripos($text, $match);
        if ($pos === false) {
            return mb_substr($text, 0, 120);
        }
        $start = max(0, $pos - 40);
        $frag  = mb_substr($text, $start, mb_strlen($match) + 80);

        return trim(($start > 0 ? '…' : '') . $frag . ($start + mb_strlen($frag) < mb_strlen($text) ? '…' : ''));
    }
}
