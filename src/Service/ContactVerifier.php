<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Верифицирует контактные данные перед сохранением.
 * Цель: не пускать в БД галлюцинации LLM.
 */
class ContactVerifier
{
    /** Таймаут HTTP-проверки в секундах */
    private const URL_TIMEOUT = 10;

    /** Допустимые HTTP-коды для "живого" URL */
    private const OK_CODES = [200, 201, 301, 302, 303, 307, 308];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {}

    /**
     * Проверяет, что URL отвечает (2xx / 3xx).
     * Использует HEAD; если сервер не поддерживает HEAD — делает GET с ограничением буфера.
     */
    public function verifyUrl(string $url): bool
    {
        $url = $this->normalizeUrl($url);
        if ($url === null) {
            return false;
        }

        foreach (['HEAD', 'GET'] as $method) {
            try {
                $response = $this->httpClient->request($method, $url, [
                    'timeout'      => self::URL_TIMEOUT,
                    'max_redirects' => 5,
                    'headers'      => [
                        'User-Agent' => 'Mozilla/5.0 (compatible; WearbaseBot/1.0)',
                    ],
                    // Для GET ограничиваем загрузку тела — нам важен лишь код
                    'buffer' => false,
                ]);

                $statusCode = $response->getStatusCode();

                // Отменяем поток чтобы не ждать весь ответ
                $response->cancel();

                if (in_array($statusCode, self::OK_CODES, true)) {
                    return true;
                }

                // 405 Method Not Allowed — HEAD не поддерживается, пробуем GET
                if ($method === 'HEAD' && $statusCode === 405) {
                    continue;
                }

                return false;
            } catch (TransportExceptionInterface) {
                // Сеть недоступна / SSL-ошибка / таймаут
                return false;
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    /**
     * Добавляет схему если отсутствует; возвращает null для явно невалидных значений.
     */
    public function normalizeUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || $url === 'null' || $url === 'N/A') {
            return null;
        }

        // Убираем возможный markdown вида [текст](url)
        if (preg_match('/\[.*?\]\((https?:\/\/[^)]+)\)/', $url, $m)) {
            $url = $m[1];
        }

        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = 'https://' . $url;
        }

        // Базовая санитизация
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $url;
    }

    /**
     * Базовая проверка формата email (без SMTP).
     */
    public function validateEmail(string $email): bool
    {
        $email = trim($email);
        if ($email === '' || $email === 'null' || $email === 'N/A') {
            return false;
        }

        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Базовая проверка формата российского/международного номера.
     */
    public function validatePhone(string $phone): bool
    {
        $phone = trim($phone);
        if ($phone === '' || $phone === 'null' || $phone === 'N/A') {
            return false;
        }

        // Допускаем: +7..., 8..., +375..., международный формат
        $digits = preg_replace('/\D/', '', $phone);

        return $digits !== null && strlen($digits) >= 7 && strlen($digits) <= 15;
    }
}
