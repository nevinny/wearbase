<?php

namespace App\Service\Seo;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Вычитка орфографии через Yandex Speller (бесплатный, без ключа) — ловит
 * LLM-артефакты-опечатки в готовых markdown-черновиках (напр. серия
 * app:seo:replace-listicle, ReplaceListicleCommand::generateForPlatform).
 *
 *   POST https://speller.yandex.net/services/spellservice.json/checkText
 *   {text, lang=ru, options, format=plain} → [{code,pos,row,col,len,word,s:[...]}]
 *   code=1 — орфография; code=3 — «слишком много ошибок подряд» (не автоправим).
 *
 * ⚠️ Лимит ~10000 симв/запрос → длинный текст режем по абзацам (MAX_CHUNK_CHARS)
 * и склеиваем обратно; чанкер режет строго по границам абзацев/строк, поэтому
 * склейка чанков даёт исходный текст побайтово (без добавленных разделителей).
 *
 * Автоправка (замена word→s[0]) применяется ТОЛЬКО когда безопасно: слово
 * строчное, есть непустой s[0], слово не в списке protected (регистронезав.),
 * и оно не внутри замаскированной зоны (код/URL/заголовок) — такие зоны перед
 * отправкой в API заменяются плейсхолдером той же длины, поэтому Speller их
 * в принципе не флагует и позиции остальных ошибок не съезжают.
 */
class SpellChecker
{
    private const ENDPOINT = 'https://speller.yandex.net/services/spellservice.json/checkText';
    private const MAX_CHUNK_CHARS = 8000;
    // IGNORE_URLS(4) + IGNORE_CAPITALIZATION(512) — не трогать URL и слова с заглавной
    // буквы (имена собственные/бренды часто капитализированы).
    private const OPTIONS = 4 | 512;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param string[] $protected имена брендов/якорей — никогда не автоправятся
     * @return array{fixed:string,flags:array<int,array{word:string,suggestion:?string,applied:bool}>}
     */
    public function proofread(string $markdown, array $protected = []): array
    {
        $protectedLower = array_map(
            static fn(string $p): string => mb_strtolower(trim($p), 'UTF-8'),
            array_values(array_filter($protected, static fn($p) => trim((string) $p) !== '')),
        );

        $fixed = '';
        $flags = [];
        foreach ($this->chunk($markdown) as $chunkText) {
            [$chunkFixed, $chunkFlags] = $this->proofreadChunk($chunkText, $protectedLower);
            $fixed .= $chunkFixed;
            $flags = array_merge($flags, $chunkFlags);
        }

        return ['fixed' => $fixed, 'flags' => $flags];
    }

    /**
     * @param string[] $protectedLower
     * @return array{0:string,1:array<int,array{word:string,suggestion:?string,applied:bool}>}
     */
    private function proofreadChunk(string $chunk, array $protectedLower): array
    {
        $errors = $this->checkText($this->mask($chunk));
        if ($errors === []) {
            return [$chunk, []];
        }

        // Применяем правки с конца — чтобы pos/len (в символах) более ранних
        // ошибок не съезжали при mb_substr-замене.
        usort($errors, static fn(array $a, array $b) => $b['pos'] <=> $a['pos']);

        $flags = [];
        $result = $chunk;
        foreach ($errors as $err) {
            if ((int) ($err['code'] ?? 0) !== 1) {
                continue; // code=3 «слишком много ошибок» и т.п. — не автоправим, не флагуем
            }
            $word = (string) ($err['word'] ?? '');
            if ($word === '' || !preg_match('/\p{L}/u', $word)) {
                continue; // токен без букв (число, ToC-маркер «1.») — не орфографическая ошибка
            }
            $suggestions = (array) ($err['s'] ?? []);
            $suggestion  = $suggestions !== [] ? (string) $suggestions[0] : null;

            $isLowercase = mb_substr($word, 0, 1, 'UTF-8') === mb_strtolower(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8');
            $isProtected = in_array(mb_strtolower($word, 'UTF-8'), $protectedLower, true);
            $applied = $isLowercase && $suggestion !== null && $suggestion !== '' && !$isProtected;

            if ($applied) {
                $pos = (int) $err['pos'];
                $len = (int) $err['len'];
                $result = mb_substr($result, 0, $pos, 'UTF-8')
                    . $suggestion
                    . mb_substr($result, $pos + $len, null, 'UTF-8');
            }

            $flags[] = ['word' => $word, 'suggestion' => $suggestion, 'applied' => $applied];
        }

        // Флаги собирались с конца текста — вернём в порядке появления в тексте.
        return [$result, array_reverse($flags)];
    }

    /**
     * @return array<int,array{code:int,pos:int,len:int,word:string,s:array<int,string>}>
     */
    private function checkText(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'body'    => ['text' => $text, 'lang' => 'ru', 'options' => self::OPTIONS, 'format' => 'plain'],
                'timeout' => 30,
            ]);
            if ($response->getStatusCode() >= 400) {
                return [];
            }
            $data = $response->toArray(false);
        } catch (HttpExceptionInterface|\Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Маскирует зоны, которые нельзя трогать/флагать — код, URL в ()/<>, заголовки —
     * плейсхолдером ТОЙ ЖЕ длины (в символах), чтобы Speller их не спеллчекал и позиции
     * остальных ошибок не съехали относительно оригинального текста.
     */
    private function mask(string $text): string
    {
        $patterns = [
            '/<script\b[^>]*>[\s\S]*?<\/script>/iu', // весь <script> JSON-LD (не только теги — иначе содержимое улетает в Speller)
            '/```[\s\S]*?```/u',                     // код-блоки
            '/`[^`\n]+`/u',                          // inline-код
            '/<[^>]*>/u',                            // <URL>, HTML-теги, <!-- комментарии -->
            '/(?<=\])\([^)]*\)/u',                    // только цель markdown-ссылки (url) — «]» НЕ трогаем,
                                                       // иначе слово перед ссылкой слипается с плейсхолдером
            '/^#{1,6}[^\n]*$/mu',                     // заголовки целиком
        ];

        foreach ($patterns as $pattern) {
            $text = (string) preg_replace_callback(
                $pattern,
                static fn(array $m): string => str_repeat('0', mb_strlen($m[0], 'UTF-8')),
                $text,
            );
        }

        return $text;
    }

    /**
     * Режет текст на чанки ≤MAX_CHUNK_CHARS по границам абзацев (пустая строка),
     * с фоллбэком на построчную резку для абзацев, которые сами длиннее лимита.
     * Конкатенация чанков всегда даёт исходный текст побайтово.
     *
     * @return string[]
     */
    private function chunk(string $text): array
    {
        $parts = preg_split('/(\n{2,})/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        // Абзац длиннее лимита сам по себе (редкая длинная таблица) — режем по строкам.
        $pieces = [];
        foreach ($parts as $part) {
            if (mb_strlen($part, 'UTF-8') <= self::MAX_CHUNK_CHARS) {
                $pieces[] = $part;
                continue;
            }
            $lines = preg_split('/(\n)/u', $part, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$part];
            foreach ($lines as $line) {
                $pieces[] = $line;
            }
        }

        $chunks = [];
        $current = '';
        foreach ($pieces as $piece) {
            if ($current !== '' && mb_strlen($current, 'UTF-8') + mb_strlen($piece, 'UTF-8') > self::MAX_CHUNK_CHARS) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= $piece;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
