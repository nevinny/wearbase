<?php

namespace App\Command;

use App\Entity\Brand;
use App\Repository\BrandRepository;
use App\Service\BrandRagService;
use App\Service\EmbeddingService;
use App\Service\LlmService;
use App\Service\VectorStoreService;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Универсальный RAG-запрос к каталогу. Два режима по числу аргументов:
 *
 *   1 аргумент  — подбор брендов по запросу на естественном языке:
 *     app:brand:ask "подбери бренд под casual/office для женщины" --local
 *
 *   2 аргумента — вопрос про конкретный бренд (по его фактам):
 *     app:brand:ask "Снежная Королева" "из чего шьют пуховики?" --local
 *
 * ⚠️ Подбор видит только бренды, прошедшие RAG-этап embed (есть чанки в Qdrant).
 * Чистый векторный поиск не гарантирует жёсткие фильтры («женщина»): близкий по
 * смыслу мужской бренд может просочиться — LLM-реранк отсекает это по фактам.
 */
#[AsCommand(
    name: 'app:brand:ask',
    description: 'RAG-запрос к каталогу: подбор брендов (1 арг) или вопрос про бренд (2 арг)',
)]
class BrandAskCommand extends Command
{
    /** Сколько чанков тянем из Qdrant (с запасом на разнообразие брендов). */
    private const DEFAULT_CANDIDATES = 60;
    /** Сколько брендов-кандидатов отдаём в LLM на реранк. */
    private const SHORTLIST = 15;
    /** Обрезка сниппета факта на бренд. */
    private const SNIPPET_CHARS = 320;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BrandRagService        $rag,
        private readonly LlmService             $llm,
        private readonly EmbeddingService       $embedder,
        private readonly VectorStoreService     $vectors,
        private readonly BrandRepository        $brands,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::REQUIRED, 'Запрос (подбор) ИЛИ название/slug бренда (если задан question)')
            ->addArgument('question', InputArgument::OPTIONAL, 'Вопрос про бренд (включает режим вопроса про конкретный бренд)')
            ->addOption('local', null, InputOption::VALUE_NONE, 'Локальная LLM (ollama) вместо OpenRouter')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Сколько брендов рекомендовать (режим подбора)', 5)
            ->addOption('candidates', null, InputOption::VALUE_REQUIRED, 'Сколько чанков тянуть из Qdrant', self::DEFAULT_CANDIDATES)
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Без LLM: топ брендов по векторной близости');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $query    = trim((string) $input->getArgument('query'));
        $question = $input->getArgument('question');
        $local    = (bool) $input->getOption('local');

        if ($query === '') {
            $io->error('Пустой запрос.');
            return Command::INVALID;
        }

        // 2 аргумента → вопрос про конкретный бренд (по фактам из Qdrant).
        if ($question !== null && trim((string) $question) !== '') {
            return $this->askBrand($io, $query, trim((string) $question), $local);
        }

        // 1 аргумент → подбор брендов по каталогу.
        return $this->recommend($io, $input, $query, $local);
    }

    // ---------------------------------------------------------------------
    //  Режим подбора (RAG по всей коллекции)
    // ---------------------------------------------------------------------

    private function recommend(SymfonyStyle $io, InputInterface $input, string $query, bool $local): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $candK = max($limit, (int) $input->getOption('candidates'));
        $raw   = (bool) $input->getOption('raw');

        try {
            $qvec = $this->embedder->embed($query);
            $hits = $this->vectors->search($qvec, $candK);
        } catch (\Throwable $e) {
            $io->error('Поиск не удался: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if ($hits === []) {
            $io->warning('Ничего не нашлось. Возможно, коллекция brand_chunks пуста (нужен этап embed).');
            return Command::SUCCESS;
        }

        // Группируем чанки по бренду: лучший score + представительный сниппет.
        $byBrand = [];
        foreach ($hits as $hit) {
            $bid = (int) ($hit['payload']['brand_id'] ?? 0);
            if ($bid === 0) {
                continue;
            }
            $score = (float) ($hit['score'] ?? 0);
            if (!isset($byBrand[$bid]) || $score > $byBrand[$bid]['score']) {
                $byBrand[$bid] = [
                    'score'   => $score,
                    'snippet' => mb_substr(trim((string) ($hit['payload']['text'] ?? '')), 0, self::SNIPPET_CHARS),
                ];
            }
        }
        uasort($byBrand, static fn($a, $b) => $b['score'] <=> $a['score']);

        // Загружаем активные бренды, сохраняя порядок по score.
        $ids       = array_slice(array_keys($byBrand), 0, self::SHORTLIST);
        $loaded    = $this->brands->findBy(['id' => $ids, 'status' => Statuses::Active]);
        $brandById = [];
        foreach ($loaded as $b) {
            $brandById[$b->getId()] = $b;
        }

        $candidates = [];
        foreach ($ids as $bid) {
            if (isset($brandById[$bid])) {
                $candidates[] = ['brand' => $brandById[$bid], 'meta' => $byBrand[$bid]];
            }
        }

        if ($candidates === []) {
            $io->warning('Кандидаты найдены в векторах, но соответствующие бренды неактивны/удалены.');
            return Command::SUCCESS;
        }

        if ($raw) {
            $this->printCandidates($io, array_slice($candidates, 0, $limit));
            return Command::SUCCESS;
        }

        $io->section("Запрос: «{$query}»");
        $io->text(sprintf('Кандидатов из каталога: %d, реранк %s LLM…', count($candidates), $local ? 'локальной' : 'OpenRouter'));

        $answer = $this->rerank($query, $candidates, $limit, $local);
        $io->newLine();
        $io->writeln($answer);

        $io->newLine();
        $io->text('— Кандидаты (по векторной близости), для ссылок: —');
        $this->printCandidates($io, $candidates);

        return Command::SUCCESS;
    }

    /**
     * @param array<int,array{brand:Brand,meta:array{score:float,snippet:string}}> $candidates
     */
    private function rerank(string $query, array $candidates, int $limit, bool $local): string
    {
        $lines = [];
        foreach ($candidates as $i => $c) {
            $brand   = $c['brand'];
            $city    = $brand->getCity() ? ", {$brand->getCity()}" : '';
            $snippet = $c['meta']['snippet'] !== '' ? "\n   Факты: {$c['meta']['snippet']}" : '';
            $lines[] = sprintf('%d. %s%s%s', $i + 1, $brand->getTitle(), $city, $snippet);
        }
        $list = implode("\n", $lines);

        $system = 'Ты — консультант каталога российских брендов одежды WEARBASE. '
            . 'Отвечай на русском. Рекомендуй ТОЛЬКО бренды из списка кандидатов — ничего не выдумывай. '
            . 'Если запросу никто толком не подходит — честно скажи об этом.';

        $prompt = <<<EOT
        Запрос пользователя: «{$query}»

        Кандидаты из каталога (номер. название, город, факты):
        {$list}

        Выбери до {$limit} наиболее подходящих под запрос брендов и кратко (1–2 предложения на каждый)
        объясни, почему он подходит, опираясь на факты. Расставь по убыванию релевантности.
        Формат каждой строки: «— Название — почему подходит».
        EOT;

        try {
            return trim($this->llm->generate($prompt, $system, local: $local, think: false));
        } catch (\Throwable $e) {
            return '(LLM недоступна: ' . $e->getMessage() . ')';
        }
    }

    /**
     * @param array<int,array{brand:Brand,meta:array{score:float,snippet:string}}> $candidates
     */
    private function printCandidates(SymfonyStyle $io, array $candidates): void
    {
        $rows = [];
        foreach ($candidates as $c) {
            $brand = $c['brand'];
            $rows[] = [
                number_format($c['meta']['score'], 3),
                $brand->getTitle(),
                $brand->getCity() ?: '—',
                '/ru/brands/' . $brand->getSlug(),
            ];
        }
        $io->table(['score', 'бренд', 'город', 'url'], $rows);
    }

    // ---------------------------------------------------------------------
    //  Режим вопроса про конкретный бренд (исходное поведение)
    // ---------------------------------------------------------------------

    private function askBrand(SymfonyStyle $io, string $brandArg, string $question, bool $local): int
    {
        $brand = $this->findBrand($brandArg);
        if ($brand === null) {
            $io->error("Бренд «{$brandArg}» не найден");
            return Command::FAILURE;
        }

        $io->info("Бренд: {$brand->getTitle()} (ID: {$brand->getId()})");

        $io->section('Поиск в RAG…');
        $result = $this->rag->retrieve($brand);

        if ($result['context'] === null) {
            $io->warning('Нет релевантных фактов в Qdrant (скорее всего, бренд ещё не прошёл конвейер).');
            return Command::FAILURE;
        }

        $io->text(sprintf('Найдено чанков: %d, top-score: %.4f', $result['chunks'], $result['score']));

        $io->section('Ответ LLM…');
        $answer = $this->llm->generate(
            prompt: "Факты о бренде «{$brand->getTitle()}»:\n{$result['context']}\n\nВопрос: {$question}\n\nОтветь строго на основе фактов. Если в фактах нет ответа — так и скажи.",
            systemPrompt: 'Ты — ассистент по каталогу российских брендов одежды. Отвечай на русском, только по фактам, без выдумок. Кратко и по делу.',
            local: $local,
            timeout: 120,
        );

        $io->success($answer);

        return Command::SUCCESS;
    }

    private function findBrand(string $arg): ?Brand
    {
        $repo = $this->entityManager->getRepository(Brand::class);

        $brand = $repo->findOneBy(['slug' => $arg]);
        if ($brand !== null) {
            return $brand;
        }

        $brands = $repo->findBrandsBySearch($arg);
        if ($brands !== []) {
            return $brands[0];
        }

        return null;
    }
}
