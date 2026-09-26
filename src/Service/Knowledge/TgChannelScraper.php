<?php

namespace App\Service\Knowledge;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Скрапит публичное web-превью Telegram-канала (`https://t.me/s/<channel>`) —
 * без бота/API/токена, доступно напрямую с Mac (прод РФ для Meta заблокирован,
 * t.me — нет). Отдаёт посты с непустым текстом (чистое медиа/сервисные — пропуск).
 *
 * `/s/<channel>` без параметров отдаёт последнюю страницу превью (~20 постов).
 * Более старые страницы — через `?before=<id>` (id — минимальный/самый старый
 * id уже полученной страницы). Листания вперёд (к более новым) нет — не нужно,
 * ежедневный `app:kb:sync-tg` и так берёт последнюю страницу. Глубокий backfill
 * истории — через `--backfill` у `app:kb:sync-tg`, идёт страница за страницей
 * пока не упрётся в пустую страницу или в уже сохранённый пост.
 */
class TgChannelScraper
{
    private const BASE_URL = 'https://t.me/s/';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<array{id:int,text:string,date:\DateTimeImmutable,title:string}>
     */
    public function fetchPosts(string $channel, ?int $before = null): array
    {
        $url = self::BASE_URL . $channel . ($before !== null ? '?before=' . $before : '');
        $response = $this->httpClient->request('GET', $url, [
            'headers' => ['User-Agent' => self::USER_AGENT],
            'timeout' => 30,
        ]);

        $html = $response->getContent();
        $crawler = new Crawler($html);

        $title = trim($crawler->filter('.tgme_channel_info_header_title')->count() > 0
            ? $crawler->filter('.tgme_channel_info_header_title')->first()->text('')
            : "@{$channel}");

        $posts = [];
        foreach ($crawler->filter('.tgme_widget_message_wrap') as $node) {
            $wrap = new Crawler($node);

            $msg = $wrap->filter('.tgme_widget_message[data-post]');
            if ($msg->count() === 0) {
                continue;
            }
            $dataPost = $msg->attr('data-post') ?? '';
            if (!preg_match('~/(\d+)$~', $dataPost, $m)) {
                continue;
            }
            $id = (int) $m[1];

            $textNode = $wrap->filter('.tgme_widget_message_text');
            if ($textNode->count() === 0) {
                continue; // чистое медиа/сервисный пост без текста
            }
            $text = $this->htmlToText($textNode->first()->html(''));
            if ($text === '') {
                continue;
            }

            $timeNode = $wrap->filter('time[datetime]');
            $datetime = $timeNode->count() > 0 ? $timeNode->first()->attr('datetime') : null;
            $date = $datetime !== null
                ? new \DateTimeImmutable($datetime)
                : new \DateTimeImmutable();

            $posts[] = ['id' => $id, 'text' => $text, 'date' => $date, 'title' => $title];
        }

        return $posts;
    }

    /** <br> → \n, снимает теги, декодирует сущности, нормализует пустые строки. */
    private function htmlToText(string $html): string
    {
        $html = preg_replace('~<br\s*/?>~i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("~\n{3,}~", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
