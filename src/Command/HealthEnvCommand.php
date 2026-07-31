<?php

namespace App\Command;

use App\Notification\AdminNotifier;
use App\Notification\TelegramNotifier;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Health-check инвариантов среды. Мотивация: дважды ловили «тихие» сбои постфактум
 * (потерян ADMIN_TELEGRAM_CHAT_ID → все TG-уведомления no-op с exit 0;
 * протух WORDSTAT_API_KEY → «0 ключевиков, ошибок 0»).
 *
 * Проверяет: обязательные env, доступность ollama/Qdrant/SearXNG, свежесть синков GSC/Яндекс/
 * снимков советника (state_snapshot). Qdrant: 401 тоже считаем «жив» (сервис ответил, дело
 * в ключе, а не в падении процесса).
 * Анти-спам: TG-алерт по каждой проверке не чаще 1 раза в сутки (var/health_env_state.json).
 *
 * Это и есть health-loop (nightly self-healing мониторинг): дополнительной команды
 * `app:ops:health` намеренно НЕТ — она дублировала бы эту же (те же проверки: env-ключи,
 * ollama/Qdrant, свежесть GSC/Яндекс) и завела бы второй источник TG-алертов о тех же сбоях.
 *
 *   php bin/console app:health:env             # тихо: сводка в stdout, алерт только по новым fail
 *   php bin/console app:health:env --report    # полная сводка в stdout + в TG независимо от состояния
 */
#[AsCommand(name: 'app:health:env', description: 'Инварианты среды: env/сервисы/свежесть синков → TG-алерт при сбоях (крон Mac)')]
class HealthEnvCommand extends Command
{
    /** Резерв только для алерта о потере ADMIN_TELEGRAM_CHAT_ID (AdminNotifier тогда no-op). */
    private const FALLBACK_ADMIN_CHAT = '140045444';

    /** Свежесть синков: MAX(дата) не старше этого количества дней. */
    private const SYNC_MAX_AGE_DAYS = 5;

    private const HTTP_TIMEOUT = 5;

    /** Попыток на сервис перед вердиктом «недоступен» (см. checkHttp). */
    private const HTTP_ATTEMPTS = 2;

    public function __construct(
        private readonly Connection $db,
        private readonly AdminNotifier $notifier,
        private readonly TelegramNotifier $telegram,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::ADMIN_TELEGRAM_CHAT_ID)%')]
        private readonly ?string $adminChatId,
        #[Autowire('%env(default::TELEGRAM_BOT_TOKEN)%')]
        private readonly ?string $botToken,
        #[Autowire('%env(default::WORDSTAT_API_KEY)%')]
        private readonly ?string $wordstatApiKey,
        #[Autowire('%env(default::LOCAL_LLM_URL)%')]
        private readonly ?string $localLlmUrl,
        #[Autowire('%env(default::QDRANT_URL)%')]
        private readonly ?string $qdrantUrl,
        #[Autowire('%env(default::QDRANT_API_KEY)%')]
        private readonly ?string $qdrantApiKey,
        #[Autowire('%env(default::SEARXNG_URL)%')]
        private readonly ?string $searxngUrl,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('report', null, InputOption::VALUE_NONE, 'Полная сводка в stdout + отправить в TG независимо от состояния');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // --- Все проверки: key → [ok, message] ---
        $checks = [
            'admin_chat_id'  => $this->checkEnv('ADMIN_TELEGRAM_CHAT_ID', $this->adminChatId),
            'bot_token'      => $this->checkEnv('TELEGRAM_BOT_TOKEN', $this->botToken),
            'wordstat_key'   => $this->checkEnv('WORDSTAT_API_KEY', $this->wordstatApiKey),
            'ollama'         => $this->checkOllama(),
            // 401 = сервис жив, но не тот/просроченный api-key — само по себе не признак падения (conversion-loop ask).
            'qdrant'         => $this->checkHttp('Qdrant', $this->qdrantUrl, '/collections',
                trim((string) $this->qdrantApiKey) !== '' ? ['api-key' => (string) $this->qdrantApiKey] : [], [200, 401]),
            'searxng'        => $this->checkHttp('SearXNG', $this->searxngUrl, '/'),
            'gsc_fresh'      => $this->checkSyncFreshness('GSC', 'SELECT MAX(day) FROM gsc_page_stats'),
            'yandex_fresh'   => $this->checkSyncFreshness('Яндекс', 'SELECT MAX(last_checked_at) FROM yandex_index_status'),
            // Health-loop: свежесть снимков советника (app:advisor:snapshot, крон 50 8 * * *) —
            // тот же класс «протухшего синка», что GSC/Яндекс выше.
            'advisor_fresh'  => $this->checkSyncFreshness('Советник', 'SELECT MAX(created_at) FROM state_snapshot'),
        ];

        // --- Сводка в stdout ---
        $lines = [];
        foreach ($checks as $key => [$ok, $msg]) {
            $lines[] = sprintf('%s %s — %s', $ok ? '✅' : '❌', $key, $msg);
        }
        $io->text($lines);

        $failed = array_filter($checks, static fn(array $c) => !$c[0]);

        // --- Анти-спам: алертим по каждой проверке не чаще 1 раза в сутки ---
        $stateFile = $this->projectDir . '/var/health_env_state.json';
        $state     = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
        $today     = (new \DateTime('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');

        $toAlert = [];
        foreach ($failed as $key => [, $msg]) {
            if (($state[$key] ?? '') !== $today) {
                $toAlert[$key] = $msg;
                $state[$key]   = $today;
            }
        }
        if ($toAlert !== []) {
            file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // --- Отправка в TG ---
        if ($input->getOption('report')) {
            // Ручная проверка: полная сводка независимо от состояния и анти-спама
            $this->sendAlert("<b>🩺 Health-check среды</b>\n\n" . implode("\n", $lines), $checks['admin_chat_id'][0]);
        } elseif ($toAlert !== []) {
            $alertLines = array_map(
                static fn(string $key, string $msg) => sprintf('❌ %s — %s', $key, $msg),
                array_keys($toAlert), $toAlert,
            );
            $this->sendAlert("<b>🚨 Health-check среды: сбои</b>\n\n" . implode("\n", $alertLines), $checks['admin_chat_id'][0]);
        }

        return $failed === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Обычный путь — AdminNotifier. Если ADMIN_TELEGRAM_CHAT_ID потерян (сам предмет алерта),
     * AdminNotifier — no-op, поэтому шлём напрямую на резервный чат.
     */
    private function sendAlert(string $html, bool $adminChatOk): void
    {
        if ($adminChatOk && $this->notifier->isEnabled()) {
            $this->notifier->send($html);
        } else {
            $this->telegram->send(self::FALLBACK_ADMIN_CHAT, $html);
        }
    }

    /** @return array{bool, string} */
    private function checkEnv(string $name, ?string $value): array
    {
        return trim((string) $value) !== ''
            ? [true, sprintf('%s задан', $name)]
            : [false, sprintf('%s пуст или не задан', $name)];
    }

    /**
     * ollama: LOCAL_LLM_URL содержит путь эндпоинта (…/api/chat), поэтому берём только
     * scheme://host:port и дёргаем /api/tags.
     * @return array{bool, string}
     */
    private function checkOllama(): array
    {
        $url = trim((string) $this->localLlmUrl);
        if ($url === '') {
            return [false, 'LOCAL_LLM_URL пуст или не задан'];
        }
        $p = parse_url($url);
        if (!isset($p['scheme'], $p['host'])) {
            return [false, sprintf('LOCAL_LLM_URL не парсится: %s', $url)];
        }
        $base = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');

        return $this->checkHttp('ollama', $base, '/api/tags');
    }

    /**
     * @param array<string, string> $headers
     * @param list<int> $okStatuses HTTP-коды, считающиеся «сервис жив» (по умолчанию только 200)
     * @return array{bool, string}
     */
    private function checkHttp(string $label, ?string $baseUrl, string $path, array $headers = [], array $okStatuses = [200]): array
    {
        $baseUrl = trim((string) $baseUrl);
        if ($baseUrl === '') {
            return [false, sprintf('%s: URL не задан в env', $label)];
        }
        $url = rtrim($baseUrl, '/') . $path;

        // Один повтор перед вердиктом «недоступен»: сервисы на LAN-риге под нагрузкой
        // (майнинг + резидентная gemma) отвечают дольше 5с, и одиночный таймаут не отличим
        // от упавшего процесса — ночью это давало ложные алерты по Qdrant/SearXNG,
        // которые к утру «сами лечились».
        $status = null;
        $error  = '';
        for ($attempt = 1; $attempt <= self::HTTP_ATTEMPTS; $attempt++) {
            try {
                $status = $this->httpClient->request('GET', $url, [
                    'headers' => $headers,
                    'timeout' => self::HTTP_TIMEOUT,
                ])->getStatusCode();
                break;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        if ($status === null) {
            return [false, sprintf('%s недоступен, %d попытки (%s)', $label, self::HTTP_ATTEMPTS, $error)];
        }

        return in_array($status, $okStatuses, true)
            ? [true, sprintf('%s отвечает (HTTP %d)', $label, $status)]
            : [false, sprintf('%s: HTTP %d от %s', $label, $status, $url)];
    }

    /** @return array{bool, string} */
    private function checkSyncFreshness(string $label, string $sql): array
    {
        try {
            $max = $this->db->fetchOne($sql);
        } catch (\Throwable $e) {
            return [false, sprintf('%s: запрос свежести упал (нет таблицы?): %s', $label, $e->getMessage())];
        }
        if (!$max) {
            return [false, sprintf('%s: таблица пуста — синк не отработал ни разу', $label)];
        }
        $ageDays = (new \DateTime())->diff(new \DateTime((string) $max))->days;

        return $ageDays <= self::SYNC_MAX_AGE_DAYS
            ? [true, sprintf('%s: последний синк %s (%d дн.)', $label, $max, $ageDays)]
            : [false, sprintf('%s: синк устарел — последний %s (%d дн. > %d)', $label, $max, $ageDays, self::SYNC_MAX_AGE_DAYS)];
    }
}
