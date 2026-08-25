<?php

declare(strict_types=1);

namespace App\Service\News;

/**
 * Минимальный RSS 2.0-парсер: все MVP-фиды — стандартный RSS, различается
 * только путь до <item> (_docs/news-sources.md §3). Namespace-префиксы
 * (content:, dc:) игнорируем — берём title/link/guid/pubDate/description.
 *
 * @return array<int, array{guid: string, link: string, title: string, pubDate: ?\DateTimeImmutable, description: string}>
 */
final class RssParser
{
    /**
     * @throws \RuntimeException при невалидном XML
     */
    public function parse(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $root = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA);
            if ($root === false) {
                throw new \RuntimeException('Невалидный RSS/XML');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }

        $items = [];
        foreach ($root->xpath('//*[local-name()="item"]') ?: [] as $node) {
            $link = trim((string) ($node->xpath('./*[local-name()="link"]')[0] ?? ''));
            if ($link === '') {
                continue;
            }

            $pubRaw = trim((string) ($node->xpath('./*[local-name()="pubDate"]')[0] ?? ''));
            $pub = null;
            if ($pubRaw !== '') {
                $ts = strtotime($pubRaw);
                $pub = $ts !== false ? (new \DateTimeImmutable())->setTimestamp($ts) : null;
            }

            $items[] = [
                'guid' => trim((string) ($node->xpath('./*[local-name()="guid"]')[0] ?? '')),
                'link' => $link,
                'title' => html_entity_decode(trim((string) ($node->xpath('./*[local-name()="title"]')[0] ?? '')), ENT_QUOTES),
                'pubDate' => $pub,
                'description' => html_entity_decode(trim(strip_tags((string) ($node->xpath('./*[local-name()="description"]')[0] ?? ''))), ENT_QUOTES),
            ];
        }

        return $items;
    }
}
