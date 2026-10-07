<?php

namespace App\Tests\Service;

use App\Service\LlmService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LlmServiceRelayTest extends TestCase
{
    public function testRelayUsesOpenAiBodyAndBearer(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['url' => $url, 'options' => $options];

            return new MockResponse(json_encode(['choices' => [['message' => ['content' => '{"outfits":[]}']]]]));
        });
        $llm = new LlmService($client, '', 'm', 'http://ollama.local/api/chat', 'gemma', '', '', 'https://relay.test/llmq.php?action=submit', 'fake-token');

        $out = $llm->generate('hi', local: true, think: false, maxTokens: 450, temperature: 0.4, fastFail: true, timeout: 12);

        self::assertSame('{"outfits":[]}', $out);
        self::assertTrue($llm->usesRelay());
        self::assertSame('https://relay.test/llmq.php?action=submit', $captured['url']);
        self::assertContains('Authorization: Bearer fake-token', $captured['options']['headers']);
        $body = json_decode($captured['options']['body'], true);
        self::assertSame(450, $body['max_tokens']);
        self::assertSame(0.4, $body['temperature']);
        self::assertSame([['role' => 'user', 'content' => 'hi']], $body['messages']);
        self::assertArrayNotHasKey('stream', $body);
    }

    public function testRelayErrorBodyThrows(): void
    {
        $client = new MockHttpClient(new MockResponse('{"error":{"message":"ollama down","code":502}}'));
        $llm = new LlmService($client, '', 'm', '', '', '', '', 'https://relay.test/x', 't');

        $this->expectException(\RuntimeException::class);
        $llm->generate('hi', local: true, fastFail: true);
    }

    public function testRelayHttpErrorThrowsRuntime(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 504]));
        $llm = new LlmService($client, '', 'm', '', '', '', '', 'https://relay.test/x', 't');

        $this->expectException(\RuntimeException::class);
        $llm->generate('hi', local: true, fastFail: true);
    }

    public function testEmptyRelayKeepsNativeOllamaPath(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['url' => $url, 'options' => $options];

            return new MockResponse('{"message":{"content":"native"}}');
        });
        $llm = new LlmService($client, '', 'm', 'http://ollama.local/api/chat', 'gemma');

        self::assertSame('native', $llm->generate('hi', local: true));
        self::assertFalse($llm->usesRelay());
        self::assertSame('http://ollama.local/api/chat', $captured['url']);
        self::assertSame('gemma', json_decode($captured['options']['body'], true)['model']);
    }
}
