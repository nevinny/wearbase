<?php

namespace App\Service\Keyword;

use App\Service\LlmService;

/**
 * Классификатор ниши для отдельных ФРАЗ brand_keyword (не для брендов — см.
 * NicheCheckCommand). Импорт лидов и сбор Wordstat подмешивают мусорные
 * related-запросы («яндекс погода») даже у нишевых брендов — эта проверка
 * режет их на уровне фразы. Одна LLM-классификация на уникальную фразу,
 * результат переиспользуется на все её вхождения (см. CheckKeywordNicheCommand).
 */
class KeywordNicheClassifier
{
    private const CHUNK_SIZE = 30;

    private const SYSTEM_PROMPT = <<<TXT
        Ты — классификатор каталога WEARBASE. WEARBASE — каталог брендов МОДЫ:
        одежда, обувь, сумки, аксессуары, ювелирка и бижутерия, а также
        КОСМЕТИКА, уход за кожей и волосами, парфюмерия. Тебе дан список
        поисковых фраз. Для КАЖДОЙ фразы реши, относится ли она к этой теме:
        IN  — фраза про моду (одежда/обувь/сумки/аксессуары/ювелирка) ИЛИ
              про косметику/уход/парфюм.
        OFF — фраза не про моду и не про красоту: техника и электроника, авто,
              аптека и лекарства, сервисы и продукты Яндекса (например «яндекс
              погода», «яндекс игры»), игры, общие слова без модной темы.
        Ответь СТРОГО одним JSON-массивом без пояснений и без markdown, вида:
        [{"k":"<фраза>","v":"in"},{"k":"<фраза>","v":"off"}]
        Верни вердикт для каждой полученной фразы, ключ "k" — точная копия фразы.
        TXT;

    public function __construct(
        private readonly LlmService $llm,
    ) {
    }

    /**
     * @param string[] $keywords уникальные фразы
     * @return array<string,string> map [фраза => 'in'|'off']; фразы, которые LLM
     *   не вернул или не удалось распарсить, в результат НЕ попадают (вызывающий
     *   оставляет niche_status = NULL для них).
     */
    public function classifyBatch(array $keywords): array
    {
        $result = [];

        foreach (array_chunk($keywords, self::CHUNK_SIZE) as $chunk) {
            $result += $this->classifyChunk($chunk);
        }

        return $result;
    }

    /** @param string[] $chunk @return array<string,string> */
    private function classifyChunk(array $chunk): array
    {
        $prompt = "Фразы:\n" . implode("\n", array_map(static fn (string $k) => '- ' . $k, $chunk));

        try {
            $raw = $this->llm->generate($prompt, self::SYSTEM_PROMPT, local: true, think: false, maxTokens: 800);
        } catch (\RuntimeException) {
            return []; // сервер недоступен/таймаут — пропускаем чанк, повторим позже
        }

        return $this->parseVerdicts($raw, $chunk);
    }

    /**
     * @param string[] $chunk исходные фразы чанка (для регистронезависимого матчинга обратно)
     * @return array<string,string>
     */
    private function parseVerdicts(string $raw, array $chunk): array
    {
        if (!preg_match('/\[.*\]/s', $raw, $m)) {
            return [];
        }

        $data = json_decode($m[0], true);
        if (!is_array($data)) {
            return [];
        }

        // Индекс исходных фраз чанка по нижнему регистру — LLM может слегка
        // менять регистр/пробелы в ответе, матчим обратно на оригинал.
        $byLower = [];
        foreach ($chunk as $keyword) {
            $byLower[mb_strtolower(trim($keyword))] = $keyword;
        }

        $result = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $verdict = $row['v'] ?? null;
            if (!in_array($verdict, ['in', 'off'], true)) {
                continue;
            }
            $key = mb_strtolower(trim((string) ($row['k'] ?? '')));
            $original = $byLower[$key] ?? null;
            if ($original === null) {
                continue;
            }
            $result[$original] = $verdict;
        }

        return $result;
    }
}
