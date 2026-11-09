<?php

namespace App\Command;

use App\Service\LlmService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * LLM-разметка стиля, которого нет в словаре brand_attribute (TagBrandStylesCommand — чистый маппинг,
 * по нему «indie-sleaze» набирает ~1 бренд). Кандидаты — активные бренды с подтверждённым RU-происхождением
 * (те же гейты, что у фактов SeoStyleHubCommand), у которых в тексте/атрибутах есть признаки стиля.
 * Вердикт LLM обязан содержать признаки из фиксированного списка и ДОСЛОВНУЮ цитату из описания бренда:
 * цитата, которой нет в тексте, = отказ (без догадок). Связь пишется в brand_style_brand (INSERT IGNORE).
 *
 *   php bin/console app:brand:classify-style --style=indie-sleaze --dry-run --no-debug
 */
#[AsCommand(
    name: 'app:brand:classify-style',
    description: 'LLM-разметка брендов под стиль (вердикт с цитатой из описания) → brand_style_brand',
)]
class ClassifyBrandStyleCommand extends Command
{
    private const MIN_SIGNALS = 2;

    /** slug => [признаки (код => описание), regexp предфильтра кандидатов по тексту и по brand_attribute(style)] */
    private const STYLES = [
        'indie-sleaze' => [
            'signals' => [
                'leather' => 'чёрная кожа / кожаные куртки (кожанки)',
                'flare'   => 'клёш (расклешённые брюки/джинсы)',
                'cowboy'  => 'казаки / ковбойские сапоги',
                'rock'    => 'рок-эстетика (рок, панк, рок-н-ролл, гранж-вечеринки, байкерская)',
                'black'   => 'преимущественно чёрная палитра («всё чёрное»)',
            ],
            'text_re' => 'кожан|кожаны|клеш|клёш|казак|ковбой|рок-|панк|рокер|байкер|leather|rock|punk|western|biker',
            'attr_re' => 'rock|рок|western|панк|punk|sleaze|слиз|biker|байкер|goth|leather|cowboy|ковбой|гранж|grunge',
        ],
    ];

    public function __construct(private readonly Connection $db, private readonly LlmService $llm)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('style', null, InputOption::VALUE_REQUIRED, 'slug стиля: ' . implode('|', array_keys(self::STYLES)))
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Максимум кандидатов (0 = все)', 0)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только вердикты, без записи');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = (string) $input->getOption('style');
        $cfg = self::STYLES[$slug] ?? null;
        if ($cfg === null) {
            $io->error('Укажите --style из: ' . implode(', ', array_keys(self::STYLES)));
            return Command::FAILURE;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        $styleId = $this->db->fetchOne('SELECT id FROM brand_style WHERE slug = ?', [$slug]);
        if (!$dryRun && $styleId === false) {
            $io->error("Стиль {$slug} не заведён: сначала app:brand:tag-styles");
            return Command::FAILURE;
        }

        $sql = "SELECT b.id, b.title, b.anons, b.description FROM brand b
                WHERE b.status = 'active' AND b.origin_status = 'ru' AND (b.niche_status IS NULL OR b.niche_status <> 'off')
                  AND (b.description REGEXP :t OR b.anons REGEXP :t
                       OR EXISTS (SELECT 1 FROM brand_attribute a WHERE a.brand_id = b.id AND a.name = 'style' AND LOWER(a.value) REGEXP :a))
                ORDER BY b.id";
        $rows = $this->db->fetchAllAssociative($sql, ['t' => $cfg['text_re'], 'a' => $cfg['attr_re']]);
        $limit = (int) $input->getOption('limit');
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }
        $io->title(sprintf('Классификация «%s»: кандидатов %d', $slug, count($rows)));

        $accepted = [];
        $errors = 0;
        foreach ($rows as $r) {
            $text = $this->plain(trim($r['anons'] . "\n" . $r['description']));
            if (mb_strlen($text) < 80) {
                continue;
            }
            try {
                $v = $this->verdict($cfg['signals'], $r['title'], mb_substr($text, 0, 3000));
            } catch (\Throwable $e) {
                $errors++;
                $io->warning($r['title'] . ': ' . $e->getMessage());
                continue;
            }
            $signals = array_values(array_intersect(array_keys($cfg['signals']), (array) ($v['signals'] ?? [])));
            $quote = $this->plain((string) ($v['quote'] ?? ''));
            $ok = ($v['verdict'] ?? false) === true
                && count($signals) >= self::MIN_SIGNALS
                && mb_strlen($quote) >= 15
                && mb_stripos($text, $quote) !== false;
            $io->writeln(sprintf('%s #%d %s [%s] «%s»', $ok ? '<info>+</info>' : '-', $r['id'], $r['title'], implode(',', $signals), mb_substr($quote, 0, 90)));
            if ($ok) {
                $accepted[(int) $r['id']] = $r['title'];
            }
        }

        // Бренды, уже попавшие в стиль по словарю (brand_attribute), — тоже считаем в итог.
        $dict = $styleId === false ? [] : $this->db->fetchFirstColumn('SELECT brand_id FROM brand_style_brand WHERE brand_style_id = ?', [$styleId]);
        $io->success(sprintf('Прошло вердикт: %d (+ по словарю уже в стиле: %d; ошибок LLM: %d)', count($accepted), count($dict), $errors));
        $io->writeln(implode('; ', $accepted));

        if (!$dryRun) {
            foreach (array_keys($accepted) as $bid) {
                $this->db->executeStatement('INSERT IGNORE INTO brand_style_brand (brand_style_id, brand_id) VALUES (?, ?)', [$styleId, $bid]);
            }
            $io->text('Связи записаны.');
        }

        return Command::SUCCESS;
    }

    private function plain(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s))));
    }

    /** @return array{verdict?:bool,signals?:string[],quote?:string} */
    private function verdict(array $signals, string $title, string $text): array
    {
        $list = '';
        foreach ($signals as $code => $desc) {
            $list .= "- {$code}: {$desc}\n";
        }
        $prompt = "Определи по ТЕКСТУ описания бренда одежды, относится ли он к эстетике «индислиз» "
            . "(как её описывают зумеры: казаки + кожанка + чёрные клёши, рок, всё чёрное).\n"
            . "Признаки (используй только эти коды):\n{$list}\n"
            . "Правила: учитывай только то, что прямо написано в тексте; не додумывай по названию бренда и не опирайся на общие знания. "
            . "Признак засчитывается, только если текст говорит о ключевой продукции или эстетике бренда, а не об отдельной вещи в сезонной капсуле. "
            . "verdict=true, если засчитано минимум 2 разных признака. В quote дай ОДНУ дословную цитату (15-200 символов) из текста, подтверждающую главный признак.\n"
            . "Ответ — только JSON: {\"verdict\":true|false,\"signals\":[\"код\",...],\"quote\":\"...\"}\n\n"
            . "Бренд: {$title}\nТекст: {$text}";
        $raw = $this->llm->generate($prompt, null, null, 120, 400, true, false, 0.1);
        if (!preg_match('/\{.*\}/su', $raw, $m)) {
            throw new \RuntimeException('LLM вернула не JSON');
        }
        $j = json_decode($m[0], true);
        if (!is_array($j)) {
            throw new \RuntimeException('JSON не разобран');
        }
        return $j;
    }
}
