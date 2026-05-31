<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\VkVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class VkVerifierTest extends TestCase
{
    private function verifier(string $id = '', string $secret = ''): VkVerifier
    {
        return new VkVerifier($this->createMock(HttpClientInterface::class), $id, $secret);
    }

    public function testIsConfigured(): void
    {
        $this->assertFalse($this->verifier()->isConfigured());
        $this->assertFalse($this->verifier('123', '')->isConfigured());
        $this->assertTrue($this->verifier('123', 'secret')->isConfigured());
    }

    public function testCodeVerifierIsUrlSafeAndLongEnough(): void
    {
        $v = $this->verifier()->generateCodeVerifier();
        $this->assertGreaterThanOrEqual(43, strlen($v));
        $this->assertLessThanOrEqual(128, strlen($v));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_]+$/', $v, 'base64url без padding');
    }

    public function testBuildAuthorizeUrlContainsPkceAndState(): void
    {
        $svc = $this->verifier('42', 'secret');
        $verifier = 'test-code-verifier-1234567890-abcdefghijklmnop';
        $url = $svc->buildAuthorizeUrl('https://app.test/cb', 'st4te', $verifier);

        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->assertStringStartsWith('https://id.vk.com/authorize?', $url);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('42', $q['client_id']);
        $this->assertSame('https://app.test/cb', $q['redirect_uri']);
        $this->assertSame('st4te', $q['state']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame($expectedChallenge, $q['code_challenge']);
    }
}
