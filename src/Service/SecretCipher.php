<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Симметричное шифрование секретов платёжных шлюзов (libsodium secretbox).
 * Ключ — base64 от ровно 32 байт, из env PAYMENT_SECRET_KEY.
 *
 * Формат хранения: base64(nonce ‖ ciphertext). Nonce случайный на каждое шифрование.
 * Пустой/некорректный ключ — громкая ошибка: секрет в открытом виде не сохраняем.
 */
readonly class SecretCipher
{
    public function __construct(
        private string $key,
    ) {}

    public function isConfigured(): bool
    {
        $decoded = base64_decode($this->key, true);
        return $decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
    }

    public function encrypt(string $plaintext): string
    {
        $key = $this->binaryKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $stored): string
    {
        $key = $this->binaryKey();
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Невозможно расшифровать секрет: повреждённые данные.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if ($plaintext === false) {
            throw new \RuntimeException('Невозможно расшифровать секрет: неверный ключ или подделка.');
        }

        return $plaintext;
    }

    private function binaryKey(): string
    {
        $decoded = base64_decode($this->key, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(
                'PAYMENT_SECRET_KEY не задан или неверной длины. Сгенерируйте: '
                . 'php -r "echo base64_encode(random_bytes(32));"'
            );
        }

        return $decoded;
    }
}
