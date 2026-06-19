<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Каноникализация стилей: свободный текст из brand_attribute(name=style) — уже извлечён
 * LLM-ом в `app:brand:extract` — маппится на каноничные `brand_style` и пишется в M2M
 * `brand_style_brand`. БЕЗ LLM (чистый маппинг по словарю синонимов). Недостающие
 * высокочастотные стили (y2k/горпкор/опиум/архив/апсайкл/…) заводятся автоматически.
 *
 *   php bin/console app:brand:tag-styles --dry-run   # показать раскладку, не писать
 *   php bin/console app:brand:tag-styles             # применить (идемпотентно, INSERT IGNORE)
 *   php bin/console app:brand:tag-styles --force      # пересобрать с нуля (очистить связи)
 */
#[AsCommand(
    name: 'app:brand:tag-styles',
    description: 'Каноникализация стилей brand_attribute → brand_style (M2M), без LLM',
)]
class TagBrandStylesCommand extends Command
{
    /** Каноничные стили: slug => русский title (заводятся, если их нет). */
    private const CANON = [
        'minimalism' => 'Минимализм', 'classic' => 'Классика', 'sport' => 'Спортивный',
        'streetwear' => 'Уличный стиль', 'boho' => 'Бохо', 'romantic' => 'Романтичный',
        'vintage' => 'Винтаж', 'grunge' => 'Гранж', 'avantgarde' => 'Авангард',
        'casual' => 'Повседневный', 'business-casual' => 'Офисный / Смарт-кэжуал',
        'militari' => 'Милитари', 'safari' => 'Сафари', 'zhenstvennyj' => 'Женственный',
        'morskoj' => 'Морской', 'drama' => 'Драма', 'dendi' => 'Дэнди',
        // недостающие, но частотные и различимые (SEO-дыра vitrine — стилевые вселенные):
        'y2k' => 'Y2K', 'gorpcore' => 'Горпкор', 'opium' => 'Опиум', 'archive' => 'Архив',
        'upcycle' => 'Апсайклинг', 'outdoor' => 'Аутдор', 'oversize' => 'Оверсайз',
        'luxury' => 'Премиум / Люкс', 'beach' => 'Пляжный / Курортный', 'school' => 'Школьный',
    ];

    /** Свободный текст (lowercased) => canonical slug. */
    private const SYN = [
        'минимализм' => 'minimalism', 'minimalism' => 'minimalism', 'minimal' => 'minimalism',
        'минималистичный' => 'minimalism', 'базовый' => 'minimalism', 'basic' => 'minimalism', 'нормкор' => 'minimalism',
        'minimalist' => 'minimalism', 'лаконичный' => 'minimalism',
        'скандинавский' => 'minimalism', 'базовые' => 'minimalism',
        'классика' => 'classic', 'классический' => 'classic', 'classic' => 'classic',
        'элегантный' => 'classic', 'элегантность' => 'classic', 'элегантная' => 'classic',
        'elegant' => 'classic', 'timeless' => 'classic', 'классические' => 'classic', 'old money' => 'classic',
        'спорт' => 'sport', 'спортивный' => 'sport', 'спортивная' => 'sport', 'sport' => 'sport',
        'sportswear' => 'sport', 'спортвир' => 'sport', 'атлетик' => 'sport', 'актив' => 'sport',
        'спорт-шик' => 'sport', 'athleisure' => 'sport', 'sporty' => 'sport', 'активный отдых' => 'sport',
        'sport casual' => 'sport', 'спортивный стиль' => 'sport', 'спортивная одежда' => 'sport', 'activewear' => 'sport', 'фитнес' => 'sport',
        'streetwear' => 'streetwear', 'стритвир' => 'streetwear', 'уличный' => 'streetwear',
        'уличный стиль' => 'streetwear', 'urban' => 'streetwear', 'street' => 'streetwear', 'стрит' => 'streetwear',
        'городской' => 'streetwear', 'городской стиль' => 'streetwear',
        'уличная мода' => 'streetwear', 'молодежный' => 'streetwear', 'city' => 'streetwear',
        'бохо' => 'boho', 'boho' => 'boho',
        'романтичный' => 'romantic', 'романтический' => 'romantic', 'романтика' => 'romantic', 'romantic' => 'romantic',
        'винтаж' => 'vintage', 'vintage' => 'vintage', 'ретро' => 'vintage', 'retro' => 'vintage', 'винтажный' => 'vintage',
        'гранж' => 'grunge', 'grunge' => 'grunge',
        'авангард' => 'avantgarde', 'авангардный' => 'avantgarde', 'avantgarde' => 'avantgarde',
        'арт' => 'avantgarde', 'art' => 'avantgarde', 'концептуальный' => 'avantgarde', 'экспериментальный' => 'avantgarde',
        'casual' => 'casual', 'кэжуал' => 'casual', 'кежуал' => 'casual', 'повседневный' => 'casual',
        'повседневная' => 'casual', 'домашняя одежда' => 'casual', 'лаунж' => 'casual', 'loungewear' => 'casual',
        'everyday' => 'casual', 'кэжуал-шик' => 'casual',
        'повседневная' => 'casual', 'повседневные' => 'casual', 'повседневная одежда' => 'casual', 'домашний' => 'casual',
        'lifestyle' => 'casual', 'лайфстайл' => 'casual', 'расслабленный' => 'casual', 'комфорт' => 'casual', 'relaxed' => 'casual',
        'офисный' => 'business-casual', 'смарт-кэжуал' => 'business-casual', 'деловой' => 'business-casual',
        'деловая' => 'business-casual', 'business' => 'business-casual', 'business-casual' => 'business-casual',
        'smart casual' => 'business-casual', 'смарт кэжуал' => 'business-casual', 'деловой стиль' => 'business-casual',
        'офис' => 'business-casual', 'офисный стиль' => 'business-casual', 'formal' => 'business-casual', 'smart' => 'business-casual',
        'милитари' => 'militari', 'military' => 'militari', 'militari' => 'militari',
        'сафари' => 'safari', 'safari' => 'safari',
        'женственный' => 'zhenstvennyj', 'женственная' => 'zhenstvennyj', 'женственность' => 'zhenstvennyj', 'feminine' => 'zhenstvennyj',
        'морской' => 'morskoj', 'marine' => 'morskoj', 'nautical' => 'morskoj',
        'драма' => 'drama', 'вечерний' => 'drama', 'вечерняя' => 'drama', 'evening' => 'drama', 'dramatic' => 'drama',
        'праздничный' => 'drama', 'свадебный' => 'drama', 'вечерние' => 'drama',
        'дэнди' => 'dendi', 'dandy' => 'dendi',
        'y2k' => 'y2k', 'у2к' => 'y2k', 'y2к' => 'y2k',
        'горпкор' => 'gorpcore', 'gorpcore' => 'gorpcore', 'gorp' => 'gorpcore', 'горкор' => 'gorpcore', 'workwear' => 'gorpcore',
        'гопкор' => 'gorpcore', 'функциональный' => 'gorpcore', 'технологичный' => 'gorpcore',
        'опиум' => 'opium', 'opium' => 'opium',
        'архив' => 'archive', 'archive' => 'archive', 'архивный' => 'archive', 'архивная' => 'archive',
        'апсайкл' => 'upcycle', 'апсайклинг' => 'upcycle', 'upcycle' => 'upcycle', 'переработка' => 'upcycle', 'recycled' => 'upcycle',
        'outdoor' => 'outdoor', 'аутдор' => 'outdoor', 'туризм' => 'outdoor',
        'оверсайз' => 'oversize', 'оверсайс' => 'oversize', 'oversize' => 'oversize', 'oversized' => 'oversize',
        'премиум' => 'luxury', 'luxury' => 'luxury', 'премиальный' => 'luxury', 'люкс' => 'luxury', 'премиум-сегмент' => 'luxury',
        'пляжный' => 'beach', 'beach' => 'beach', 'resort' => 'beach', 'курортный' => 'beach', 'swimwear' => 'beach', 'купальники' => 'beach', 'пляжная одежда' => 'beach',
        'школьный' => 'school', 'школьная форма' => 'school', 'school' => 'school', 'школьная одежда' => 'school',
    ];

    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать раскладку, не писать')
            ->addOption('force',   null, InputOption::VALUE_NONE, 'Очистить brand_style_brand и пересобрать');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force  = (bool) $input->getOption('force');

        $io->title('Каноникализация стилей брендов → brand_style');

        // 1. id каноничных стилей по slug (+ завести недостающие).
        $styleId = $this->ensureStyles($io, $dryRun);

        // 2. свободный текст стилей из brand_attribute.
        $rows = $this->db->fetchAllAssociative(
            "SELECT brand_id, LOWER(value) v FROM brand_attribute WHERE name='style'"
        );

        // 3. маппинг → пары (brand_id, style_id), сбор немапнутого.
        $pairs = [];            // "brandId:styleId" => [brandId, styleId]
        $unmapped = [];         // value => count
        $brandsHit = [];
        foreach ($rows as $r) {
            $slug = self::SYN[$r['v']] ?? null;
            if ($slug === null) {
                $unmapped[$r['v']] = ($unmapped[$r['v']] ?? 0) + 1;
                continue;
            }
            $bid = (int) $r['brand_id'];
            $sid = $styleId[$slug];
            $pairs[$bid . ':' . $sid] = [$bid, $sid];
            $brandsHit[$bid] = true;
        }

        // 4. раскладка по стилям (брендов на стиль).
        $perStyle = [];
        foreach ($pairs as [$bid, $sid]) {
            $perStyle[$sid] = ($perStyle[$sid] ?? 0) + 1;
        }
        $slugById = array_flip($styleId);
        arsort($perStyle);
        $table = [];
        foreach ($perStyle as $sid => $cnt) {
            $table[] = [$slugById[$sid], self::CANON[$slugById[$sid]] ?? '?', $cnt];
        }
        $io->section('Брендов на стиль (после маппинга)');
        $io->table(['slug', 'title', 'брендов'], $table);

        arsort($unmapped);
        $topUn = array_slice($unmapped, 0, 20, true);
        if ($topUn) {
            $io->section('Топ немапнутых значений (расширить SYN при желании)');
            $io->table(['значение', 'раз'], array_map(static fn($k, $v) => [$k, $v], array_keys($topUn), $topUn));
        }

        $io->writeln(sprintf('Пар (бренд×стиль): <info>%d</info> · брендов затронуто: <info>%d</info> · немапнуто значений: <comment>%d</comment>',
            count($pairs), count($brandsHit), array_sum($unmapped)));

        if ($dryRun) {
            $io->note('dry-run — ничего не записано.');
            return Command::SUCCESS;
        }

        // 5. запись.
        if ($force) {
            $this->db->executeStatement('DELETE FROM brand_style_brand');
            $io->text('brand_style_brand очищена (--force).');
        }
        $ins = $this->db->prepare('INSERT IGNORE INTO brand_style_brand (brand_style_id, brand_id) VALUES (:sid, :bid)');
        $written = 0;
        foreach ($pairs as [$bid, $sid]) {
            $ins->bindValue('sid', $sid);
            $ins->bindValue('bid', $bid);
            $written += $ins->executeStatement();
        }
        $io->success(sprintf('Связей записано (новых): %d. Стилей в каталоге: %d.', $written, count($styleId)));

        return Command::SUCCESS;
    }

    /** @return array<string,int> slug => brand_style.id (создаёт недостающие). */
    private function ensureStyles(SymfonyStyle $io, bool $dryRun): array
    {
        $existing = [];
        foreach ($this->db->fetchAllAssociative('SELECT id, slug FROM brand_style') as $r) {
            $existing[$r['slug']] = (int) $r['id'];
        }
        $created = 0;
        foreach (self::CANON as $slug => $title) {
            if (isset($existing[$slug])) {
                continue;
            }
            if ($dryRun) {
                $existing[$slug] = -1 * (count($existing) + 1); // фиктивный id для раскладки
                $created++;
                continue;
            }
            $this->db->insert('brand_style', [
                'slug' => $slug, 'title' => $title, 'status' => 'active', 'ord' => 0,
                'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                'updated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);
            $existing[$slug] = (int) $this->db->lastInsertId();
            $created++;
        }
        if ($created) {
            $io->text(sprintf('Новых стилей %s: %d', $dryRun ? '(будет заведено)' : 'заведено', $created));
        }
        return $existing;
    }
}
