<?php

declare(strict_types=1);

/**
 * tg.php — fallback-реле Telegram для WEARBASE (советник + контакт-воронка).
 *
 * Ставится на ЗАГРАНИЧНЫЙ PHP-хост (публичный URL + чистый доступ к api.telegram.org).
 * Роль — тупой буфер, вся логика/«мозг» на Mac:
 *   1) WEBHOOK  — принимает апдейты от Telegram, кладёт в SQLite-очередь (ничего не теряется).
 *   2) ?pull    — Mac забирает накопленные апдейты (по токену), помечает выданными.
 *   3) ?send    — прокси отправки в Bot API (fallback исходящих, если Mac→TG споткнётся).
 *   4) ?health  — глубина очереди (для мониторинга).
 *
 * Зависимостей нет. PHP 7.4+, расширения: pdo_sqlite, curl.
 *
 * БЕЗОПАСНОСТЬ:
 *  - Заполни три секрета ниже. RELAY_TOKEN — длинный (openssl rand -hex 32).
 *  - WEBHOOK_SECRET передай в setWebhook (secret_token=...) — тогда чужой POST отсекается.
 *  - ⚠️ DB_PATH держи ВНЕ docroot, либо закрой доступ (рядом лежит .htaccess с deny).
 */

// ===================== КОНФИГ (заполнить) =====================
const BOT_TOKEN      = '';   // токен @wearbase_bot — нужен только для ?send-прокси
const RELAY_TOKEN    = '';   // общий секрет для ?pull/?send/?health (сгенерируй длинный)
const WEBHOOK_SECRET = '';   // секрет вебхука (тот же в setWebhook secret_token); пусто = не проверять
const DB_PATH        = __DIR__ . '/.tg_queue.sqlite'; // лучше вынести за пределы docroot
const MAX_BODY       = 262144;      // 256 KB лимит тела вебхука
const PURGE_DAYS     = 7;           // чистка выданных апдейтов старше N дней
// =============================================================

header('Content-Type: application/json; charset=utf-8');

function out(array $a, int $code = 200)
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS updates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            payload TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            delivered INTEGER NOT NULL DEFAULT 0
        )');
    }
    return $pdo;
}

function authed(): bool
{
    $t = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
    return RELAY_TOKEN !== '' && hash_equals(RELAY_TOKEN, $t);
}

$action = (string) ($_GET['action'] ?? '');

// ---------- 1. PULL: Mac забирает очередь ----------
if ($action === 'pull') {
    if (!authed()) {
        out(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
    $rows = db()->query(
        "SELECT id, payload FROM updates WHERE delivered = 0 ORDER BY id ASC LIMIT {$limit}"
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($rows) {
        $ids = implode(',', array_map(static fn($r) => (int) $r['id'], $rows));
        db()->exec("UPDATE updates SET delivered = 1 WHERE id IN ({$ids})");
    }
    out([
        'ok'      => true,
        'updates' => array_map(
            static fn($r) => ['id' => (int) $r['id'], 'update' => json_decode($r['payload'], true)],
            $rows
        ),
    ]);
}

// ---------- 2. SEND: прокси отправки (fallback исходящих) ----------
if ($action === 'send') {
    if (!authed()) {
        out(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    if (BOT_TOKEN === '') {
        out(['ok' => false, 'error' => 'no bot token'], 500);
    }
    $chat = (string) ($_POST['chat_id'] ?? '');
    $text = (string) ($_POST['text'] ?? '');
    if ($chat === '' || $text === '') {
        out(['ok' => false, 'error' => 'chat_id and text required'], 400);
    }
    $payload = ['chat_id' => $chat, 'text' => $text];
    foreach (['parse_mode', 'reply_markup'] as $opt) {
        if (!empty($_POST[$opt])) {
            $payload[$opt] = $_POST[$opt];
        }
    }
    if (!empty($_POST['reply_to_message_id'])) {
        $payload['reply_to_message_id'] = (int) $_POST['reply_to_message_id'];
    }

    $ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        out(['ok' => false, 'error' => 'curl: ' . $err], 502);
    }
    out(json_decode($resp, true) ?: ['ok' => false, 'error' => 'bad tg response']);
}

// ---------- 3. HEALTH ----------
if ($action === 'health') {
    if (!authed()) {
        out(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    $pending = (int) db()->query('SELECT COUNT(*) FROM updates WHERE delivered = 0')->fetchColumn();
    out(['ok' => true, 'pending' => $pending]);
}

// ---------- 4. WEBHOOK (POST от Telegram, действие по умолчанию) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (WEBHOOK_SECRET !== '') {
        $hdr = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
        if (!hash_equals(WEBHOOK_SECRET, $hdr)) {
            out(['ok' => false], 403);
        }
    }
    $raw = (string) file_get_contents('php://input', false, null, 0, MAX_BODY + 1);
    if (strlen($raw) > MAX_BODY) {
        out(['ok' => false, 'error' => 'too large'], 413);
    }
    json_decode($raw);
    if (json_last_error() !== JSON_ERROR_NONE) {
        out(['ok' => false, 'error' => 'bad json'], 400);
    }

    db()->prepare('INSERT INTO updates (payload, created_at) VALUES (?, ?)')
        ->execute([$raw, time()]);

    // Лёгкая чистка старых выданных (раз в ~50 апдейтов, чтобы не на каждый запрос).
    if (random_int(1, 50) === 1) {
        db()->prepare('DELETE FROM updates WHERE delivered = 1 AND created_at < ?')
            ->execute([time() - PURGE_DAYS * 86400]);
    }

    out(['ok' => true]);
}

out(['ok' => false, 'error' => 'unknown action'], 404);
