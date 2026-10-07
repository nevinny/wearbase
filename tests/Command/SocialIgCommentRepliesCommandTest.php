<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\InstagramCommentReply;
use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Service\SecretCipher;
use App\Service\Social\InstagramComments;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * app:social:ig-comment-replies: IG замокан на уровне HTTP (MockHttpClient), чтобы проверить и
 * отбор комментариев, и реальный формат запроса Private Reply.
 */
class SocialIgCommentRepliesCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SocialPost $post;
    /** @var list<array{string, string, array<string, mixed>}> */
    private array $requests = [];
    /** @var list<array<string, string>> */
    private array $comments = [];
    private bool $commentsFail = false;
    /** @var callable(): MockResponse */
    private $onSend;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();

        // Сервис подменяется один раз (после инициализации контейнер не даёт его заменить), ответы — из полей теста.
        self::getContainer()->set(InstagramComments::class, new InstagramComments(new MockHttpClient(function (string $method, string $url, array $opt) {
            $this->requests[] = [$method, $url, $opt];
            if ($method === 'POST') {
                return ($this->onSend)();
            }
            if (str_contains($url, '/me')) {
                return new MockResponse('{"username":"wearbaseru","id":"1"}');
            }
            if ($this->commentsFail) {
                return new MockResponse('{"error":{"message":"boom","code":1}}', ['http_code' => 500]);
            }

            return new MockResponse(json_encode(['data' => $this->comments]));
        })));

        $channel = (new SocialChannel())
            ->setPlatform(SocialChannel::PLATFORM_IG)->setName('Test IG')->setTarget('17841400000000000')
            ->setTokenEnc(self::getContainer()->get(SecretCipher::class)->encrypt('ig-token'))
            ->setEnabled(true)->setEgressHost(SocialChannel::HOST_MAC);
        $this->em->persist($channel);
        $this->post = (new SocialPost())
            ->setChannel($channel)->setRubric('wardrobe_reels')->setMediaType(SocialPost::MEDIA_REELS)
            ->setStatus(SocialPost::STATUS_PUBLISHED)->setExternalId('18000000000000001')
            ->setScriptKey('wardrobe-templates-v1.t11-test-v1')
            ->setScriptJson(json_encode(['comment_keyword' => 'размер', 'comment_reply' => "Памятка.\nСсылка: {ссылка}"], JSON_UNESCAPED_UNICODE))
            ->setPublishedAt(new \DateTime('-1 day'));
        $this->em->persist($this->post);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    /** @param list<array<string, string>> $comments @param (callable(): MockResponse)|null $onSend */
    private function poll(array $comments, ?callable $onSend = null, array $options = []): string
    {
        $this->comments = $comments;
        $this->onSend = $onSend ?? static fn () => new MockResponse('{"recipient_id":"1","message_id":"m1"}');

        $tester = new CommandTester((new Application(self::$kernel))->find('app:social:ig-comment-replies'));
        self::assertSame(0, $tester->execute($options));

        return $tester->getDisplay();
    }

    /** @return list<array{string, string, array<string, mixed>}> */
    private function sentRequests(): array
    {
        return array_values(array_filter($this->requests, static fn ($r) => $r[0] === 'POST'));
    }

    /** @return array<string, string> */
    private function comment(string $id, string $text, string $user = 'mama_anna'): array
    {
        return ['id' => $id, 'text' => $text, 'username' => $user, 'timestamp' => (new \DateTime('-1 hour'))->format('Y-m-d\TH:i:sO')];
    }

    private function row(string $commentId): ?InstagramCommentReply
    {
        return $this->em->getRepository(InstagramCommentReply::class)->findOneBy(['commentId' => $commentId]);
    }

    public function testRepliesOnlyToKeywordCommentsAndSkipsOwn(): void
    {
        $this->poll([
            $this->comment('c1', 'Размер, пожалуйста!'),
            $this->comment('c2', 'красиво'),
            $this->comment('c3', 'размер', 'WearbaseRu'),
        ]);

        $sent = $this->sentRequests();
        self::assertCount(1, $sent, 'только c1: c2 без слова, c3 — свой комментарий');
        self::assertSame('https://graph.instagram.com/v22.0/17841400000000000/messages', $sent[0][1]);
        $body = json_decode($sent[0][2]['body'], true);
        self::assertSame(['comment_id' => 'c1'], $body['recipient']);
        self::assertStringContainsString('Ссылка: https://wearbase.ru/ru/wardrobe?utm_source=instagram&utm_medium=dm&utm_campaign=wardrobe-templates-v1.t11-test-v1', $body['message']['text']);
        self::assertStringNotContainsString('{ссылка}', $body['message']['text']);
        self::assertContains('Authorization: Bearer ig-token', $sent[0][2]['headers']);

        self::assertSame(InstagramCommentReply::STATUS_SENT, $this->row('c1')->getStatus());
        self::assertNull($this->row('c3'));
    }

    public function testSecondRunDoesNotReplyAgain(): void
    {
        $comments = [$this->comment('c1', 'размер')];
        $this->poll($comments);
        $this->requests = [];
        $this->poll($comments);

        self::assertSame([], $this->sentRequests(), 'дедуп по comment_id');
    }

    public function testApiErrorDoesNotCrashAndIsRecorded(): void
    {
        $out = $this->poll(
            [$this->comment('c1', 'размер'), $this->comment('c2', 'размер')],
            static fn () => new MockResponse('{"error":{"message":"outside of allowed window","code":10}}', ['http_code' => 400]),
        );

        self::assertStringContainsString('ошибок: 2', $out);
        $row = $this->row('c1');
        self::assertSame(InstagramCommentReply::STATUS_REJECTED, $row->getStatus());
        self::assertStringContainsString('outside of allowed window', (string) $row->getError());

        $this->requests = [];
        $this->poll([$this->comment('c1', 'размер')]);
        self::assertSame([], $this->sentRequests(), 'постоянная ошибка не повторяется');
    }

    public function testTransientErrorIsRetriedOnlyAfterPause(): void
    {
        $this->poll([$this->comment('c1', 'размер')], static fn () => new MockResponse('{"error":{"message":"rate limit","code":4}}', ['http_code' => 400]));
        self::assertSame(InstagramCommentReply::STATUS_FAILED, $this->row('c1')->getStatus());

        $this->requests = [];
        $this->poll([$this->comment('c1', 'размер')]);
        self::assertSame([], $this->sentRequests(), 'сразу не повторяем — без спама');

        $this->em->getConnection()->executeStatement('UPDATE instagram_comment_reply SET updated_at = ?', [(new \DateTime('-20 minutes'))->format('Y-m-d H:i:s')]);
        $this->em->clear();
        $this->poll([$this->comment('c1', 'размер')]);
        self::assertCount(1, $this->sentRequests());
        $row = $this->row('c1');
        self::assertSame(InstagramCommentReply::STATUS_SENT, $row->getStatus());
        self::assertSame(2, $row->getAttempts());
    }

    public function testDryRunSendsAndWritesNothing(): void
    {
        $out = $this->poll([$this->comment('c1', 'размер')], null, ['--dry-run' => true]);

        self::assertStringContainsString('dry-run', $out);
        self::assertSame([], $this->sentRequests());
        self::assertNull($this->row('c1'));
    }

    public function testMaxRepliesCapsRun(): void
    {
        $this->poll([$this->comment('c1', 'размер'), $this->comment('c2', 'размер'), $this->comment('c3', 'размер')], null, ['--max-replies' => 2]);

        self::assertCount(2, $this->sentRequests());
    }

    public function testCommentsReadFailureSkipsPostWithoutCrash(): void
    {
        $this->commentsFail = true;

        $out = $this->poll([]);

        self::assertStringContainsString('чтение комментариев', $out);
        self::assertSame([], $this->sentRequests());
    }
}
