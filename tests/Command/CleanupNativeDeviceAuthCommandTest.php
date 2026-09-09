<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Tests\Controller\AuthenticatedWebTestCase;
use App\Tests\Controller\UserFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class CleanupNativeDeviceAuthCommandTest extends AuthenticatedWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    public function testCleanupRemovesOnlyFinishedCredentialsAndIsIdempotentWithoutLeakingTokens(): void
    {
        $client = static::createClient();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:native-auth:cleanup'));
        $tester->execute([]);
        $user = UserFactory::withEmail(static::getContainer(), 'native-cleanup-'.bin2hex(random_bytes(5)).'@test.local');
        $revoked = $this->login($client, $user->getEmail(), 'cleanup-revoked');
        $expired = $this->login($client, $user->getEmail(), 'cleanup-expired');
        $live = $this->login($client, $user->getEmail(), 'cleanup-live');
        $connection = static::getContainer()->get('doctrine.orm.entity_manager')->getConnection();
        $past = (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');
        $connection->executeStatement('UPDATE native_device_session SET revoked_at = ? WHERE public_id = ?', [$past, $revoked['device']['publicId']]);
        $connection->executeStatement('UPDATE native_device_session SET access_expires_at = ? WHERE public_id IN (?, ?)', [$past, $expired['device']['publicId'], $live['device']['publicId']]);
        $connection->executeStatement('UPDATE native_refresh_token SET expires_at = ? WHERE session_id = (SELECT id FROM native_device_session WHERE public_id = ?)', [$past, $expired['device']['publicId']]);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('1 expired refresh receipt(s), 1 revoked session(s), 1 expired session(s)', $tester->getDisplay());
        self::assertStringNotContainsString($revoked['accessToken'], $tester->getDisplay());
        self::assertStringNotContainsString($expired['refreshToken'], $tester->getDisplay());
        self::assertFalse((bool) $connection->fetchOne('SELECT COUNT(*) FROM native_device_session WHERE public_id IN (?, ?)', [$revoked['device']['publicId'], $expired['device']['publicId']]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM native_device_session WHERE public_id = ?', [$live['device']['publicId']]));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('0 expired refresh receipt(s), 0 revoked session(s), 0 expired session(s)', $tester->getDisplay());
    }

    /** @return array<string, mixed> */
    private function login($client, string $email, string $deviceId): array
    {
        $client->jsonRequest('POST', '/api/v1/wardrobe-app/auth/login', [
            'email' => $email, 'password' => UserFactory::PASSWORD, 'deviceId' => $deviceId,
        ]);
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
