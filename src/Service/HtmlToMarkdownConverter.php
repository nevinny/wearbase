<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Минималистичный HTML→Markdown конвертер на DOMDocument (без внешних зависимостей —
 * на проде нет composer и vendor/ не деплоится).
 *
 * Используется для Markdown content negotiation (Accept: text/markdown), см.
 * docs/agent_readiness.md. Цель — читаемый markdown для AI-агентов, а не пиксель-в-пиксель
 * рендер: вырезаем навигацию/скрипты, берём <main>/<article> (или body) и обходим дерево.
 */
final class HtmlToMarkdownConverter
{
    /** Узлы-шум, которые целиком выкидываем перед конвертацией. */
    private const STRIP = ['script', 'style', 'noscript', 'nav', 'header', 'footer', 'form', 'svg', 'iframe', 'button', 'aside', 'template'];

    public function convert(string $html, ?string $sourceUrl = null): string
    {
        $doc = new \DOMDocument();
        // meta charset — иначе DOMDocument считает вход Latin-1 и ломает кириллицу.
        $prefixed = '<?xml encoding="UTF-8"><!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
            . $this->bodyInner($html) . '</body></html>';

        libxml_use_internal_errors(true);
        $doc->loadHTML($prefixed, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);

        // Вырезаем шумовые узлы.
        foreach (self::STRIP as $tag) {
            foreach (iterator_to_array($xpath->query('//' . $tag) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // Контентный корень: первый <main> или <article>, иначе <body>.
        $root = $xpath->query('//main')->item(0)
            ?? $xpath->query('//article')->item(0)
            ?? $xpath->query('//body')->item(0);

        $body = $root ? $this->renderChildren($root) : '';
        $body = $this->normalize($body);

        $title = $this->extractTitle($html);

        $front = "---\n";
        if ($title !== '') {
            $front .= 'title: ' . $this->yamlScalar($title) . "\n";
        }
        if ($sourceUrl) {
            $front .= 'source: ' . $sourceUrl . "\n";
        }
        $front .= "---\n\n";

        return $front . $body . "\n";
    }

    /** Вытаскивает внутренность <body>, если на вход дали полный документ; иначе сам html. */
    private function bodyInner(string $html): string
    {
        if (preg_match('#<body[^>]*>(.*)</body>#is', $html, $m)) {
            return $m[1];
        }

        return $html;
    }

    private function extractTitle(string $html): string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    private function renderChildren(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $this->renderNode($child);
        }

        return $out;
    }

    private function renderNode(\DOMNode $node): string
    {
        if ($node->nodeType === \XML_TEXT_NODE) {
            // Сжимаем внутренние пробелы/переводы строк в один пробел.
            return preg_replace('/\s+/u', ' ', $node->nodeValue ?? '');
        }
        if ($node->nodeType !== \XML_ELEMENT_NODE) {
            return '';
        }

        /** @var \DOMElement $node */
        $tag = strtolower($node->nodeName);

        return match ($tag) {
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' =>
                "\n\n" . str_repeat('#', (int) $tag[1]) . ' ' . trim($this->renderChildren($node)) . "\n\n",
            'p'  => "\n\n" . trim($this->renderChildren($node)) . "\n\n",
            'br' => "  \n",
            'hr' => "\n\n---\n\n",
            'strong', 'b' => $this->wrapInline($node, '**'),
            'em', 'i'     => $this->wrapInline($node, '*'),
            'code' => '`' . trim($this->renderChildren($node)) . '`',
            'pre'  => "\n\n```\n" . trim($node->textContent) . "\n```\n\n",
            'a'    => $this->renderLink($node),
            'img'  => $this->renderImage($node),
            'ul'   => "\n" . $this->renderList($node, false) . "\n",
            'ol'   => "\n" . $this->renderList($node, true) . "\n",
            'blockquote' => "\n\n> " . trim(preg_replace('/\n+/', "\n> ", trim($this->renderChildren($node)))) . "\n\n",
            'li'   => $this->renderChildren($node), // нормально рендерится через renderList
            default => $this->renderChildren($node),
        };
    }

    private function wrapInline(\DOMNode $node, string $marker): string
    {
        $inner = trim($this->renderChildren($node));

        return $inner === '' ? '' : $marker . $inner . $marker;
    }

    private function renderLink(\DOMElement $node): string
    {
        $text = trim($this->renderChildren($node));
        $href = trim($node->getAttribute('href'));
        if ($text === '') {
            return '';
        }
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
            return $text;
        }

        return '[' . $text . '](' . $href . ')';
    }

    private function renderImage(\DOMElement $node): string
    {
        $src = trim($node->getAttribute('src'));
        if ($src === '') {
            return '';
        }
        $alt = trim($node->getAttribute('alt'));

        return '![' . $alt . '](' . $src . ')';
    }

    private function renderList(\DOMElement $list, bool $ordered): string
    {
        $lines = [];
        $i = 1;
        foreach ($list->childNodes as $child) {
            if ($child->nodeType === \XML_ELEMENT_NODE && strtolower($child->nodeName) === 'li') {
                $content = trim(preg_replace('/\s*\n\s*/', ' ', $this->renderChildren($child)));
                if ($content === '') {
                    continue;
                }
                $prefix = $ordered ? ($i++ . '. ') : '- ';
                $lines[] = $prefix . $content;
            }
        }

        return implode("\n", $lines);
    }

    /** Чистим лишние пустые строки и пробелы. */
    private function normalize(string $md): string
    {
        $md = preg_replace('/[ \t]+\n/', "\n", $md);  // хвостовые пробелы (кроме hard-break — он уже "  \n")
        $md = preg_replace('/\n{3,}/', "\n\n", $md);   // максимум одна пустая строка
        $md = preg_replace('/ {2,}/', ' ', $md);       // схлопываем повторные пробелы

        return trim($md);
    }

    private function yamlScalar(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);

        return '"' . str_replace('"', '\"', $value) . '"';
    }
}
