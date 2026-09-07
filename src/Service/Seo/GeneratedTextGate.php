<?php

namespace App\Service\Seo;

use App\Service\ArticleQaService;
use App\Service\NearDuplicateDetector;

/**
 * Общий гейт качества сгенерированного grounded-контента SEO-хабов. Вынесено из
 * SeoCityHubCommand при появлении второго потребителя (app:seo:style-hub, стилевые
 * хабы /{_locale}/style/{slug}) — поведение городского генератора не должно
 * измениться НИ НА ШАГ, поэтому пороги и комментарии-обоснования (замеры на живом
 * корпусе городов) перенесены как есть, без правок формулировок.
 *
 * Это набор независимых проверок/починок, а не монолитный check(): у городских
 * хабов есть HTML-intro, meta и FAQ, у стилевых — один плоский текст без HTML.
 * Оркестровку (порядок вызовов, что считать блокирующим, диапазон длины) держит
 * вызывающая команда — общей на двоих она быть не может без потери специфики.
 */
class GeneratedTextGate
{
    /**
     * Латинский токен от 5 символов: апостроф и цифры внутри сохраняем, иначе
     * KUL'TURA рвётся на «KUL»/«TURA», а DE4444TH — на «DE»/«TH» (все короче порога),
     * и подмены «KUL'TARS»/«DE444TH» проскакивают.
     */
    public const LATIN_WORD_RE = "/[A-Za-z][A-Za-z0-9']{4,}/";

    /**
     * Штампы, которые промпт запрещает, а модель всё равно вставляет («широкий
     * ассортимент» пролез при overall 90.6 — модуль AI-почерка тулкита их не блокирует,
     * только снижает балл).
     */
    private const CLICHES = [
        'широкий ассортимент', 'мир моды', 'идеальный выбор', 'на любой вкус',
        'уникальный стиль', 'своя специфика', 'важная часть локальной индустрии',
    ];

    /**
     * Мусор, который локальная модель выдаёт регулярно и который дороже ловить глазами,
     * чем регуляркой (все три случая — из первого прогона по 7 городам):
     *   - пересказ семантики в теле текста («пользователи часто ищут одежду abda в Казани»),
     *   - символы чужих алфавитов посреди слова («в катало지가 WEARBASE»),
     *   - артефакты экранирования из anons («Master Of Chillin'''»).
     */
    private const JUNK_PATTERNS = [
        'пересказ поисковых запросов' => '/поисковы[ех]\s+запрос|популярн\w+\s+запрос|пользовател\w+\s+(часто\s+)?ищ|многие\s+ищ\w*\s+.{0,30}(через\s+)?наш|добавля\w+\s+к\s+запрос|поиск\s+\S+\s+.{0,40}приводит|запрос\w*\s+(касается|звучит)/iu',
        'символы чужих алфавитов'     => '/[\x{0370}-\x{03FF}\x{0530}-\x{058F}\x{0590}-\x{05FF}\x{0600}-\x{06FF}\x{0E00}-\x{0E7F}\x{1100}-\x{11FF}\x{3040}-\x{30FF}\x{3130}-\x{318F}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}]/u',
        'артефакт экранирования'      => "/'{3,}|\\\\{2,}/u",
    ];

    /**
     * Блокирующий порог — ЭТО overall, НЕ $qa['passed'] сервиса. Прогон
     * tools/article-qa-toolkit на 4 живых городских хабах (docs/geo_city_demand_2026_09.md
     * §8) показал passed=false у ВСЕХ них (Human-likeness 7.0 < порога сервиса 8.0),
     * включая sankt-peterburg — ту самую страницу с 2329 показов/поз.8.4. Сервис
     * откалиброван на длинные описания брендов (~1900 симв., HL 8.2–8.5), а не на
     * 500–800-символьные intro хабов. Гейтить их по $qa['passed'] значит отбраковывать
     * контент лучше уже работающего. MIN_QA_OVERALL — эмпирический пол по замеру:
     * живые хабы 77.9–84.3, описания брендов 86.7–87.7. Не возвращай сюда $qa['passed'].
     * Стилевые хабы своего замера пока не имеют — используют тот же пол, пока не
     * наберётся собственная история (closed-loop).
     */
    public const MIN_QA_OVERALL = 80.0;

    /** Короче — детектор на 3-граммах слеп (пересечение пустое), считаем униграммами. */
    private const SHORT_TEXT_WORDS = 15;

    public function __construct(
        private readonly SpellChecker $speller,
        private readonly ArticleQaService $articleQa,
        private readonly NearDuplicateDetector $nearDup,
    ) {
    }

    /**
     * Вычитка орфографии по абзацам. HTML целиком в Speller отдавать нельзя: теги
     * склеиваются со словами («<p>В» → один токен), API возвращает НОЛЬ ошибок и
     * вычитка молча становится пустышкой (проверено: тот же текст без тегов даёт
     * «каталога» → «каталоге»). Поэтому правим текст внутри каждого <p>; текст без
     * <p> (стилевые хабы — плоский текст) правится целиком.
     *
     * @param string[] $protected названия брендов — не автоправятся
     */
    public function spellFix(string $value, array $protected, int &$fixes): string
    {
        $fix = function (string $text) use ($protected, &$fixes): string {
            if (trim($text) === '') {
                return $text;
            }
            $checked = $this->speller->proofread($text, $protected);
            $applied = count(array_filter($checked['flags'], static fn(array $f) => $f['applied']));
            if ($applied === 0) {
                return $text;
            }
            $fixes += $applied;

            return $checked['fixed'];
        };

        if (!str_contains($value, '<p')) {
            return $fix($value);
        }

        return preg_replace_callback(
            '/(<p[^>]*>)(.*?)(<\/p>)/su',
            static fn(array $m) => $m[1] . $fix($m[2]) . $m[3],
            $value,
        ) ?? $value;
    }

    /**
     * Чинит искажённые названия: латинское слово, которое почти совпадает с чем-то из
     * ФАКТОВ, заменяем на написание из ФАКТОВ. Браковать генерацию целиком из-за
     * удвоенной буквы («Seventtouch» вместо Seventouch) — расточительно и зацикливается:
     * модель повторяет одну и ту же опечатку прогон за прогоном. Замена безопасна,
     * потому что подставляем строку, которая пришла из фактов, а не выдуманную.
     * findDistortedName() остаётся страховкой для того, что не починилось.
     */
    public function fixDistortedNames(string $value, string $facts, int &$fixes): string
    {
        $vocab = [];
        preg_match_all(self::LATIN_WORD_RE, $facts, $fm);
        foreach ($fm[0] as $w) {
            $vocab[mb_strtolower($w)] = $w;
        }
        if ($vocab === []) {
            return $value;
        }

        return preg_replace_callback(
            self::LATIN_WORD_RE,
            static function (array $m) use ($vocab, &$fixes): string {
                $lower = mb_strtolower($m[0]);
                if (isset($vocab[$lower])) {
                    return $m[0];
                }
                foreach ($vocab as $key => $original) {
                    $d = levenshtein($lower, $key);
                    if ($d >= 1 && $d <= 2 && abs(mb_strlen($lower) - mb_strlen($key)) <= 2) {
                        $fixes++;

                        return $original;
                    }
                }

                return $m[0];
            },
            $value,
        ) ?? $value;
    }

    /**
     * Ищет латинское слово текста, которое ПОЧТИ совпадает с чем-то из ФАКТОВ, но не
     * совпадает точно, — то есть модель переписала название с ошибкой («KUL\'TARS»
     * вместо KUL\'TURA, «Drobyschena» вместо Drobysheva).
     *
     * Словарь строим по всему тексту фактов, а не только по названиям брендов: имена
     * товаров из anons («свитшоты BEIGE FOG») — тоже законная латиница, и по словарю
     * из одних названий BEIGE ловился как искажение BRIGHT. Расстояние 1–2: на 3 в
     * ложные срабатывания попадают обычные термины. Кириллицу не проверяем — там
     * склонения дают ту же дистанцию, что и опечатки.
     *
     * @return array{0: string, 1: string}|null [искажение, как было в фактах]
     */
    public function findDistortedName(string $text, string $facts): ?array
    {
        $vocab = [];
        preg_match_all(self::LATIN_WORD_RE, $facts, $fm);
        foreach ($fm[0] as $w) {
            $vocab[mb_strtolower($w)] = $w;
        }
        if ($vocab === []) {
            return null;
        }

        preg_match_all(self::LATIN_WORD_RE, $text, $m);
        foreach (array_unique($m[0]) as $word) {
            $lower = mb_strtolower($word);
            if (isset($vocab[$lower])) {
                continue; // ровно то, что дали в фактах
            }
            foreach ($vocab as $key => $original) {
                $d = levenshtein($lower, $key);
                if ($d >= 1 && $d <= 2 && abs(mb_strlen($lower) - mb_strlen($key)) <= 2) {
                    return [$word, $original];
                }
            }
        }

        return null;
    }

    /**
     * Слово, в котором смешаны кириллица и латиница. Разрешено только если ровно так
     * написано в ФАКТАХ (бывают названия вида «YUGE ЮДЖ»).
     */
    public function findMixedScriptWord(string $text, string $facts): ?string
    {
        $allowed = [];
        preg_match_all('/\S*[A-Za-z]\S*[\x{0400}-\x{04FF}]\S*|\S*[\x{0400}-\x{04FF}]\S*[A-Za-z]\S*/u', $facts, $fm);
        foreach ($fm[0] as $w) {
            $allowed[mb_strtolower(trim($w, " \t\n.,;:!?()«»\"'"))] = true;
        }

        preg_match_all('/[\p{L}\x{0027}]{3,}/u', $text, $m);
        foreach (array_unique($m[0]) as $word) {
            $hasLatin = preg_match('/[A-Za-z]/', $word) === 1;
            $hasCyr   = preg_match('/[\x{0400}-\x{04FF}]/u', $word) === 1;
            if ($hasLatin && $hasCyr && !isset($allowed[mb_strtolower($word)])) {
                return $word;
            }
        }

        return null;
    }

    /** Первый рекламный штамп из CLICHES, найденный в тексте, или null. */
    public function findCliche(string $everything): ?string
    {
        foreach (self::CLICHES as $cliche) {
            if (mb_stripos($everything, $cliche) !== false) {
                return $cliche;
            }
        }

        return null;
    }

    /**
     * Первый мусорный паттерн из JUNK_PATTERNS, найденный в тексте: [метка, фрагмент].
     *
     * @return array{0: string, 1: string}|null
     */
    public function findJunkPattern(string $everything): ?array
    {
        foreach (self::JUNK_PATTERNS as $label => $re) {
            if (preg_match($re, $everything, $m) === 1) {
                return [$label, mb_substr(trim($m[0]), 0, 40)];
            }
        }

        return null;
    }

    /**
     * Дословное вхождение поисковой фразы = переспам («Поиск abda одежда казань
     * приводит к знакомству с местными мастерами»). Но проверять так ВСЕ фразы
     * нельзя: «новосибирские бренды одежды» — и запрос, и нормальная русская фраза,
     * которую текст про бренды Новосибирска обойти не может (ложное срабатывание,
     * не брак модели). Различаем по форме анкора (название города/стиля): прилагательное
     * («новосибирские бренды одежды») — живая речь, а анкор в исходной форме, приклеенный
     * к остальной фразе отдельным словом («бренд одежды новосибирск»), — порядок слов
     * поискового запроса. Проверяем фразу, только если анкор входит в неё отдельным словом.
     *
     * @param string   $haystackLower весь текст, который уедет на страницу, в нижнем регистре
     * @param string[] $phrases       фразы, отданные модели
     * @param string   $anchor        форма анкора для отбора фраз (город в им.п. / slug или title стиля), нижний регистр
     * @return string|null найденная дословная фраза, если есть
     */
    public function findVerbatimPhrase(string $haystackLower, array $phrases, string $anchor): ?string
    {
        foreach ($phrases as $phrase) {
            $needle = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $phrase) ?? $phrase));
            if (!preg_match('/(^|\s)' . preg_quote($anchor, '/') . '(\s|$)/u', $needle)) {
                continue;
            }
            if (mb_strlen($needle) >= 10 && str_word_count($needle, 0, 'абвгдеёжзийклмнопрстуфхцчшщъыьэюя') >= 2
                && str_contains($haystackLower, $needle)) {
                return $phrase;
            }
        }

        return null;
    }

    /** Длина плоского текста вне [$min,$max] — причина провала или null. */
    public function checkLength(int $plainLen, int $min, int $max): ?string
    {
        return ($plainLen < $min || $plainLen > $max)
            ? sprintf('длина текста %d вне диапазона %d–%d', $plainLen, $min, $max)
            : null;
    }

    /**
     * ArticleQaService::check() + блокирующий порог MIN_QA_OVERALL (см. константу —
     * НЕ $qa['passed']). $qa['checked'] === false (тулкит недоступен) — fail-open.
     *
     * @return array{overall: ?float, passed: bool, qa: array}
     */
    public function qaOverall(string $plainText): array
    {
        $qa      = $this->articleQa->check($plainText);
        $overall = $qa['metrics']['overall'] ?? null;
        $passed  = !$qa['checked'] || ($overall !== null && $overall >= self::MIN_QA_OVERALL);

        return ['overall' => $overall, 'passed' => $passed, 'qa' => $qa];
    }

    public function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', trim(strip_tags($text)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Near-dup гейт: попарно против существующего корпуса И против уже принятых в
     * этом же прогоне (чтобы N хабов, генерируемых одним промптом из однотипных
     * фактов, не расползлись в scaled content между собой). Короткие тексты (< 15
     * слов) детектор на 3-граммах не видит — считаем униграммами для той стороны
     * пары, что короче. Считает МАКСИМУМ по каждому полю (не выходит по первому
     * совпадению) — чтобы печатать реальные цифры и на проходе, не только на провале.
     *
     * @param array<string,array{0:string,1:array<string,string>,2:array<string,string>,3:float}> $fields
     *   имя_поля => [текст, существующий_корпус (key=>text), принятое_в_этом_прогоне (key=>text), порог Jaccard]
     * @return array<string,array{score:float,key:?string}>&array{failedField:?string}
     */
    public function checkNearDup(string $ownKey, array $fields): array
    {
        $result      = [];
        $failedField = null;
        foreach ($fields as $field => [$text, $existingPool, $generatedPool, $threshold]) {
            $best    = 0.0;
            $bestKey = null;
            foreach ([$existingPool, $generatedPool] as $pool) {
                foreach ($pool as $key => $other) {
                    if ($key === $ownKey || trim($other) === '') {
                        continue;
                    }
                    $size  = min($this->wordCount($text), $this->wordCount($other)) < self::SHORT_TEXT_WORDS ? 1 : 3;
                    $score = $this->nearDup->similarity($text, $other, $size);
                    if ($score > $best) {
                        $best    = $score;
                        $bestKey = $key;
                    }
                }
            }
            $result[$field] = ['score' => $best, 'key' => $bestKey];
            if ($failedField === null && $best >= $threshold) {
                $failedField = $field;
            }
        }
        $result['failedField'] = $failedField;

        return $result;
    }
}
