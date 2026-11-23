<?php

declare(strict_types=1);

namespace App\Notification;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Входящие утреннего дайджеста (docs/commands.md «Утренний дайджест»).
 *
 * Информационные кроны с флагом --digest не шлют в TG сами, а кладут свою секцию сюда:
 * var/digest/inbox/<Y-m-d МСК>/<order>-<source>.html (повторный add того же source
 * перезаписывает). app:report:daily в 09:30 собирает ядро + sections() и шлёт одним
 * заходом, затем markSent(). Опоздавший (дайджест сегодня уже ушёл) шлётся напрямую —
 * секция не теряется.
 */
class MorningDigestInbox
{
    public const TG_LIMIT = 4000;
    private const TZ = 'Europe/Moscow';
    private const KEEP_DAYS = 7;
    private const SENT_MARKER = '.sent';

    public function __construct(
        private readonly AdminNotifier $notifier,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.project_dir%/var/digest/inbox')]
        private readonly string $baseDir,
    ) {}

    /**
     * @return bool false — только если опоздавшую секцию не удалось отправить напрямую
     */
    public function add(string $source, string $html, int $order): bool
    {
        if ($this->isSent()) {
            return $this->notifier->sendChecked($html);
        }

        $dir = $this->todayDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $source = (string) preg_replace('/[^a-z0-9_-]+/i', '-', $source);
        foreach (glob($dir . '/*-' . $source . '.html') ?: [] as $old) {
            unlink($old); // тот же source с другим order — не дублировать
        }
        file_put_contents(sprintf('%s/%d-%s.html', $dir, $order, $source), $html);

        return true;
    }

    /** @return list<string> HTML секций сегодняшнего дня, по возрастанию order */
    public function sections(): array
    {
        $files = glob($this->todayDir() . '/*.html') ?: [];
        usort($files, static fn (string $a, string $b) => (int) basename($a) <=> (int) basename($b) ?: strcmp($a, $b));

        return array_values(array_filter(array_map(
            static fn (string $f) => trim((string) file_get_contents($f)),
            $files,
        ), static fn (string $s) => $s !== ''));
    }

    public function isSent(): bool
    {
        return is_file($this->todayDir() . '/' . self::SENT_MARKER);
    }

    public function markSent(): void
    {
        $dir = $this->todayDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        touch($dir . '/' . self::SENT_MARKER);
        $this->prune();
    }

    /**
     * Разбить секции на сообщения ≤ $limit знаков по границам секций (склейка "\n\n").
     * Секция длиннее лимита режется по абзацам, затем по строкам — никогда внутри HTML-тега
     * (наши секции открывают и закрывают теги в пределах строки). Строка длиннее лимита
     * (аварийный случай) отдаётся без тегов и обрезается.
     *
     * @param list<string> $sections
     * @return list<string>
     */
    public static function pack(array $sections, int $limit = self::TG_LIMIT): array
    {
        $pieces = [];
        foreach ($sections as $s) {
            foreach (self::fit($s, $limit, ["\n\n", "\n"]) as $p) {
                $pieces[] = $p;
            }
        }

        $messages = [];
        $cur = '';
        foreach ($pieces as $p) {
            if ($cur === '') {
                $cur = $p;
            } elseif (mb_strlen($cur) + 2 + mb_strlen($p) <= $limit) {
                $cur .= "\n\n" . $p;
            } else {
                $messages[] = $cur;
                $cur = $p;
            }
        }
        if ($cur !== '') {
            $messages[] = $cur;
        }

        return $messages;
    }

    /**
     * @param list<string> $separators
     * @return list<string>
     */
    private static function fit(string $text, int $limit, array $separators): array
    {
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }
        if ($separators === []) {
            $plain = strip_tags($text);

            return [mb_strlen($plain) <= $limit ? $plain : mb_substr($plain, 0, $limit - 1) . '…'];
        }
        $sep = array_shift($separators);
        $out = [];
        $cur = '';
        foreach (explode($sep, $text) as $part) {
            foreach (self::fit($part, $limit, $separators) as $chunk) {
                if ($cur !== '' && mb_strlen($cur) + mb_strlen($sep) + mb_strlen($chunk) <= $limit) {
                    $cur .= $sep . $chunk;
                } else {
                    if ($cur !== '') {
                        $out[] = $cur;
                    }
                    $cur = $chunk;
                }
            }
        }
        if ($cur !== '') {
            $out[] = $cur;
        }

        return $out;
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(self::TZ));
    }

    private function todayDir(): string
    {
        return rtrim($this->baseDir, '/') . '/' . $this->today()->format('Y-m-d');
    }

    /** Удалить дни старше KEEP_DAYS (каталоги вида Y-m-d). */
    private function prune(): void
    {
        $cutoff = $this->today()->modify('-' . self::KEEP_DAYS . ' days')->format('Y-m-d');
        foreach (glob(rtrim($this->baseDir, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $day = basename($dir);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && $day < $cutoff) {
                array_map('unlink', glob($dir . '/{,.}[!.]*', GLOB_BRACE) ?: []);
                @rmdir($dir);
            }
        }
    }
}
