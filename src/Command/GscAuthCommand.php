<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Одноразовая OAuth-авторизация GSC (когда орг-политика запрещает SA-ключи):
 * Desktop-клиент OAuth → согласие в браузере → ловим redirect на localhost →
 * меняем code на refresh_token → пишем authorized_user-JSON для GscClient.
 *
 *   php bin/console app:gsc:auth config/secrets/gsc-oauth-client.json
 *
 * Где взять client JSON: Cloud Console → APIs & Services → Credentials →
 * Create credentials → OAuth client ID → Desktop app → Download JSON.
 * Согласие давать аккаунтом, у которого есть доступ к property в Search Console.
 */
#[AsCommand(
    name: 'app:gsc:auth',
    description: 'GSC: одноразовый OAuth (refresh_token) вместо запрещённого SA-ключа',
)]
class GscAuthCommand extends Command
{
    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const PORT  = 8765;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('client-json', InputArgument::REQUIRED, 'Путь к OAuth client JSON (Desktop app)')
            ->addArgument('output', InputArgument::OPTIONAL, 'Куда писать креды', 'config/secrets/gsc-sa.json')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $clientPath = (string) $input->getArgument('client-json');
        $client = json_decode((string) @file_get_contents($clientPath), true);
        $cfg = $client['installed'] ?? $client['web'] ?? null;
        if (!is_array($cfg) || empty($cfg['client_id']) || empty($cfg['client_secret'])) {
            $io->error("Не читается OAuth client JSON: {$clientPath} (нужен Desktop-клиент, ключи installed.client_id/client_secret)");
            return Command::FAILURE;
        }

        $redirect = 'http://localhost:' . self::PORT;
        $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $cfg['client_id'],
            'redirect_uri'  => $redirect,
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',   // иначе refresh_token не выдадут
            'prompt'        => 'consent',   // форсим выдачу refresh_token даже при повторном согласии
        ]);

        $io->title('GSC · OAuth-авторизация');
        $io->text('1. Открой в браузере (тем аккаунтом, у которого есть доступ к property wearbase.ru):');
        $io->newLine();
        $io->writeln("   <href={$authUrl}>{$authUrl}</>");
        $io->newLine();
        $io->text('2. Дай согласие — браузер перекинет на localhost, я его поймаю…');

        // Мини-HTTP-сервер: ловим единственный redirect с ?code=
        $server = @stream_socket_server('tcp://127.0.0.1:' . self::PORT, $errno, $errstr);
        if ($server === false) {
            $io->error("Не удалось слушать порт " . self::PORT . ": {$errstr}");
            return Command::FAILURE;
        }

        $code = null;
        $deadline = time() + 300; // 5 минут на согласие
        while (time() < $deadline) {
            $conn = @stream_socket_accept($server, 5);
            if ($conn === false) {
                continue;
            }
            $request = (string) fread($conn, 4096);
            if (preg_match('~GET /\?([^ ]+) HTTP~', $request, $m)) {
                parse_str($m[1], $params);
                $body = isset($params['code'])
                    ? 'Готово! Возвращайся в консоль.'
                    : 'Ошибка: ' . htmlspecialchars((string) ($params['error'] ?? 'нет code'));
                fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<h2>{$body}</h2>");
                fclose($conn);
                if (isset($params['code'])) {
                    $code = (string) $params['code'];
                    break;
                }
            } else {
                fclose($conn); // favicon и прочий мусор
            }
        }
        fclose($server);

        if ($code === null) {
            $io->error('Code не получен за 5 минут.');
            return Command::FAILURE;
        }

        // code → refresh_token
        $response = $this->httpClient->request('POST', 'https://oauth2.googleapis.com/token', [
            'body' => [
                'client_id'     => $cfg['client_id'],
                'client_secret' => $cfg['client_secret'],
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $redirect,
            ],
        ]);
        $token = $response->toArray(false);
        if (empty($token['refresh_token'])) {
            $io->error('Google не вернул refresh_token: ' . json_encode($token, JSON_UNESCAPED_UNICODE));
            return Command::FAILURE;
        }

        $outPath = (string) $input->getArgument('output');
        $outAbs  = str_starts_with($outPath, '/') ? $outPath : $this->projectDir . '/' . $outPath;
        @mkdir(dirname($outAbs), 0775, true);
        file_put_contents($outAbs, json_encode([
            'type'          => 'authorized_user',
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'refresh_token' => $token['refresh_token'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($outAbs, 0600);

        $io->success("Креды записаны: {$outAbs}");
        $io->text("Пропиши в .env.local: GSC_CREDENTIALS_PATH={$outAbs}");

        return Command::SUCCESS;
    }
}
