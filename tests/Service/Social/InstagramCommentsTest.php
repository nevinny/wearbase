<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\InstagramApiException;
use App\Service\Social\InstagramComments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class InstagramCommentsTest extends TestCase
{
    public function testPrivateReplyRequestFormat(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = [$method, $url, $options];

            return new MockResponse('{"recipient_id":"526","message_id":"aWdf"}');
        });

        $id = (new InstagramComments($client))->sendPrivateReply('17841400000000000', '1789', 'Привет', 'tok');

        self::assertSame('aWdf', $id);
        [$method, $url, $options] = $captured;
        self::assertSame('POST', $method);
        self::assertSame('https://graph.instagram.com/v22.0/17841400000000000/messages', $url);
        self::assertSame(['recipient' => ['comment_id' => '1789'], 'message' => ['text' => 'Привет']], json_decode($options['body'], true));
        self::assertContains('Authorization: Bearer tok', $options['headers']);
        self::assertStringNotContainsString('tok', $url, 'токен не в URL');
    }

    public function testTextOverByteLimitIsPermanentAndNeverSent(): void
    {
        $client = new MockHttpClient(static fn () => throw new \LogicException('не должно быть запроса'));

        try {
            (new InstagramComments($client))->sendPrivateReply('1', '2', str_repeat('я', 501), 'tok');
            self::fail('ожидалось исключение');
        } catch (InstagramApiException $e) {
            self::assertTrue($e->isPermanent());
        }
    }

    #[DataProvider('errors')]
    public function testErrorClassification(int $http, string $body, bool $permanent): void
    {
        $client = new MockHttpClient(new MockResponse($body, ['http_code' => $http]));

        try {
            (new InstagramComments($client))->sendPrivateReply('1', '2', 'x', 'tok');
            self::fail('ожидалось исключение');
        } catch (InstagramApiException $e) {
            self::assertSame($permanent, $e->isPermanent(), $e->getMessage());
            self::assertStringNotContainsString('tok', $e->getMessage());
        }
    }

    /** @return iterable<string, array{int, string, bool}> */
    public static function errors(): iterable
    {
        yield 'окно истекло — постоянная' => [400, '{"error":{"message":"This message is sent outside of allowed window.","code":10}}', true];
        yield 'лимит — временная' => [400, '{"error":{"message":"rate limit","code":4}}', false];
        yield '500 без тела — временная' => [500, '', false];
    }

    public function testCommentsPaginatesAndStopsAtWindow(): void
    {
        $urls = [];
        $pages = [
            '{"data":[{"id":"1","text":"размер","username":"a","timestamp":"2026-10-07T10:00:00+0000"},{"id":"2","text":"привет","username":"b","timestamp":"2026-10-06T10:00:00+0000"}],"paging":{"cursors":{"after":"CUR"},"next":"https://x"}}',
            '{"data":[{"id":"3","text":"ещё","username":"c","timestamp":"2026-10-05T10:00:00+0000"},{"id":"4","text":"старый","username":"d","timestamp":"2026-09-01T10:00:00+0000"},{"id":"5","text":"совсем старый","username":"e","timestamp":"2026-08-01T10:00:00+0000"}],"paging":{"cursors":{"after":"CUR2"},"next":"https://y"}}',
        ];
        $client = new MockHttpClient(function (string $method, string $url) use (&$urls, &$pages) {
            $urls[] = $url;

            return new MockResponse(array_shift($pages));
        });

        $comments = (new InstagramComments($client))->comments('999', 'tok', new \DateTimeImmutable('2026-10-01'));

        self::assertSame(['1', '2', '3'], array_column($comments, 'id'));
        self::assertCount(2, $urls, 'после комментария старше окна страницы не читаются');
        self::assertStringContainsString('/999/comments?fields=id%2Ctext%2Cusername%2Ctimestamp', $urls[0]);
        self::assertStringContainsString('after=CUR', $urls[1]);
    }
}
