<?php

namespace App\Service\Seo;

/**
 * Разбор сгенерированных .md-статей (var/seo/blog|dzen/*.md) в title/excerpt/HTML.
 * Общий для `app:seo:publish-blog` (var/seo/blog) и `app:seo:attach-distribution`
 * (var/seo/{platform}) — все конвейеры используют один и тот же формат генерации.
 */
class ArticleMarkdownParser
{
    /**
     * Разбор md: H1→title, блок «## Коротко»→excerpt, остальное (без H1) → HTML.
     * @return array{0:string,1:?string,2:string}|null
     */
    public function parse(string $md): ?array
    {
        $md = preg_replace('/<!--.*?-->/s', '', $md);            // убрать комментарии
        if (!preg_match('/^\#\s+(.+?)\s*$/m', $md, $m)) {
            return null;                                          // нет H1 — не статья
        }
        $title = trim($m[1]);
        $body  = preg_replace('/^\#\s+.+?\s*$/m', '', $md, 1);   // вырезать H1 из тела

        // excerpt из блока «## Коротко» (answer-nugget) — первый абзац, без разметки.
        $excerpt = null;
        if (preg_match('/^##\s+(?:Корот|Крат)\p{L}*\s*(.+?)(?=\n##\s|\z)/smui', $body, $lm)) {
            $plain = trim(preg_replace('/\s+/u', ' ', $this->stripInline($lm[1])));
            $excerpt = mb_substr($plain, 0, 300);
        }

        return [$title, $excerpt, $this->mdToHtml($body)];
    }

    /** Минимальный конвертер под наши конструкции (заголовки/абзацы/таблица/списки/hr/JSON-LD). */
    private function mdToHtml(string $md): string
    {
        // JSON-LD <script>…</script> вынимаем как есть (это уже HTML), вернём в конце.
        $scripts = [];
        $md = preg_replace_callback('/<script type="application\/ld\+json">.*?<\/script>/s', function ($m) use (&$scripts) {
            $scripts[] = $m[0];
            // Токен БЕЗ null-байта: PHP trim() по умолчанию режет \0 → старый "\x00SCRIPTn\x00"
            // не распознавался и JSON-LD выпадал из контента.
            return "@@LDJSON" . (count($scripts) - 1) . "@@";
        }, $md);

        $lines = preg_split('/\r?\n/', (string) $md);
        $html = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = rtrim($lines[$i]);

            if (trim($line) === '') { $i++; continue; }

            // JSON-LD плейсхолдер
            if (preg_match('/^@@LDJSON(\d+)@@$/', trim($line), $sm)) {
                $html[] = $scripts[(int) $sm[1]];
                $i++;
                continue;
            }
            // hr
            if (preg_match('/^(\*\*\*|---|___)\s*$/', $line)) { $html[] = '<hr>'; $i++; continue; }
            // заголовки
            if (preg_match('/^(##|###)\s+(.+)$/', $line, $hm)) {
                $tag = $hm[1] === '##' ? 'h2' : 'h3';
                $html[] = "<{$tag}>" . $this->inline($hm[2]) . "</{$tag}>";
                $i++;
                continue;
            }
            // таблица: блок строк, начинающихся с |
            if (str_starts_with(ltrim($line), '|')) {
                $tbl = [];
                while ($i < $n && str_starts_with(ltrim($lines[$i]), '|')) { $tbl[] = trim($lines[$i]); $i++; }
                $html[] = $this->table($tbl);
                continue;
            }
            // список
            if (preg_match('/^[-*]\s+/', $line)) {
                $items = [];
                while ($i < $n && preg_match('/^[-*]\s+(.+)$/', rtrim($lines[$i]), $im)) { $items[] = '<li>' . $this->inline($im[1]) . '</li>'; $i++; }
                $html[] = '<ul>' . implode('', $items) . '</ul>';
                continue;
            }
            // абзац: до пустой строки
            $para = [];
            while ($i < $n && trim($lines[$i]) !== '' && !preg_match('/^(#{1,3}\s|[-*]\s|\||\*\*\*|---|___|@@LDJSON)/', ltrim($lines[$i]))) {
                $para[] = trim($lines[$i]); $i++;
            }
            if ($para !== []) {
                $html[] = '<p>' . $this->inline(implode(' ', $para)) . '</p>';
            }
        }

        return implode("\n", $html);
    }

    /** @param string[] $rows строки markdown-таблицы (включая разделитель). */
    private function table(array $rows): string
    {
        $cells = static fn(string $r): array => array_map('trim', explode('|', trim($r, "| \t")));
        $isSep = static fn(string $r): bool => (bool) preg_match('/^\|?[\s:|-]+\|?$/', $r) && str_contains($r, '-');

        $head = null;
        $body = [];
        foreach ($rows as $idx => $r) {
            if ($idx === 0) { $head = $cells($r); continue; }
            if ($idx === 1 && $isSep($r)) { continue; }     // строка-разделитель
            $body[] = $cells($r);
        }

        $out = '<table>';
        if ($head !== null) {
            $out .= '<thead><tr>' . implode('', array_map(fn($c) => '<th>' . $this->inline($c) . '</th>', $head)) . '</tr></thead>';
        }
        $out .= '<tbody>';
        foreach ($body as $row) {
            $out .= '<tr>' . implode('', array_map(fn($c) => '<td>' . $this->inline($c) . '</td>', $row)) . '</tr>';
        }

        return $out . '</tbody></table>';
    }

    /** Инлайн: экранирование + **жирный** + [текст](url). */
    private function inline(string $s): string
    {
        $s = htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // ссылки (url у нас без & и кавычек — внутренние)
        $s = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/u',
            static fn($m) => '<a href="' . $m[2] . '">' . $m[1] . '</a>', $s);
        $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);

        return $s;
    }

    /** Снять markdown-разметку (для excerpt). */
    private function stripInline(string $s): string
    {
        $s = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $s);   // ссылки → текст
        $s = preg_replace('/\*\*(.+?)\*\*/u', '$1', $s);          // жирный

        return (string) $s;
    }
}
