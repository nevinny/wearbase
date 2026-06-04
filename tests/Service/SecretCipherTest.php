<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SecretCipher;
use PHPUnit\Framework\TestCase;

class SecretCipherTest extends TestCase
{
    private function cipher(): SecretCipher
    {
        return new SecretCipher(base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    }

    public function testRoundTrip(): void
    {
        $cipher = $this->cipher();
        $plain = 'test_secret_key_value_42';

        $stored = $cipher->encrypt($plain);

        $this->assertNotSame($plain, $stored, 'Шифртекст не должен совпадать с открытым текстом');
        $this->assertSame($plain, $cipher->decrypt($stored));
    }

    public function testEachEncryptionUsesFreshNonce(): void
    {
        $cipher = $this->cipher();

        $this->assertNotSame(
            $cipher->encrypt('same'),
            $cipher->encrypt('same'),
            'Разные nonce → разный шифртекст для одного и того же значения',
        );
    }

    public function testIsConfigured(): void
    {
        $this->assertFalse((new SecretCipher(''))->isConfigured());
        $this->assertFalse((new SecretCipher('short'))->isConfigured());
        $this->assertTrue($this->cipher()->isConfigured());
    }

    public function testEmptyKeyFailsLoudOnEncrypt(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SecretCipher(''))->encrypt('secret');
    }

    public function testWrongKeyCannotDecrypt(): void
    {
        $stored = $this->cipher()->encrypt('secret');

        $this->expectException(\RuntimeException::class);
        $this->cipher()->decrypt($stored); // другой случайный ключ
    }
}
