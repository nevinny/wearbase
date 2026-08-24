<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Service\FamilyService;
use App\Tests\Controller\AuthenticatedWebTestCase;
use App\Tests\Controller\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class NativeDeviceAuthControllerTest extends AuthenticatedWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    public function testLoginIssuesOpaqueTokensAndBearerAccessKeepsSessionAuthCompatible(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), $this->email('login'));

        $tokens = $this->login($client, $user);
        self::assertSame(43, strlen($tokens['accessToken']));
        self::assertSame(64, strlen($tokens['refreshToken']));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));

        $connection = static::getContainer()->get('doctrine.orm.entity_manager')->getConnection();
        $row = $connection->fetchAssociative('SELECT access_hash FROM native_device_session WHERE user_id = ?', [$user->getId()]);
        $matchingRefreshHashes = $connection->fetchOne('SELECT COUNT(*) FROM native_refresh_token WHERE token_hash = ?', [hash('sha256', $tokens['refreshToken'])]);
        self::assertSame(hash('sha256', $tokens['accessToken']), $row['access_hash']);
        self::assertSame(1, (int) $matchingRefreshHashes);
        self::assertNotSame($tokens['accessToken'], $row['access_hash']);

        $this->bearer($client, $tokens['accessToken'], '/api/v1/wardrobe-app/bootstrap');
        self::assertResponseIsSuccessful();
        self::assertSame($user->getId(), $this->json($client)['user']['id']);

        $client->restart();
        $client->loginUser($user);
        $client->request('GET', '/api/v1/wardrobe-app/bootstrap');
        self::assertResponseIsSuccessful();
    }

    public function testMissingInvalidAndExpiredAccessAreRejected(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), $this->email('access'));
        $tokens = $this->login($client, $user);

        $client->request('GET', '/api/v1/wardrobe-app/bootstrap');
        self::assertResponseStatusCodeSame(401);
        $this->bearer($client, 'not-a-token', '/api/v1/wardrobe-app/bootstrap');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('invalid_access_token', $this->json($client)['error']);

        static::getContainer()->get('doctrine.orm.entity_manager')->getConnection()->executeStatement(
            'UPDATE native_device_session SET access_expires_at = ? WHERE access_hash = ?',
            [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), hash('sha256', $tokens['accessToken'])],
        );
        $this->bearer($client, $tokens['accessToken'], '/api/v1/wardrobe-app/bootstrap');
        self::assertResponseStatusCodeSame(401);
    }

    public function testDisablingUserImmediatelyInvalidatesExistingAccess(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), $this->email('disabled-access'));
        $tokens = $this->login($client, $user);
        static::getContainer()->get('doctrine.orm.entity_manager')->getConnection()
            ->executeStatement('UPDATE client SET status = ? WHERE id = ?', ['disabled', $user->getId()]);

        $this->bearer($client, $tokens['accessToken'], '/api/v1/wardrobe-app/bootstrap');
        self::assertResponseStatusCodeSame(401);
    }

    public function testInvalidCredentialsDoNotRevealWhetherEmailExists(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), $this->email('credentials'));

        $client->jsonRequest('POST', '/api/v1/wardrobe-app/auth/login', [
            'email' => $user->getEmail(), 'password' => 'wrong', 'deviceId' => 'iphone-invalid-1',
        ]);
        self::assertResponseStatusCodeSame(401);
        $existing = $this->json($client);
        $client->jsonRequest('POST', '/api/v1/wardrobe-app/auth/login', [
            'email' => 'missing-'.bin2hex(random_bytes(4)).'@test.local', 'password' => 'wrong', 'deviceId' => 'iphone-invalid-2',
        ]);
        self::assertResponseStatusCodeSame(401);
        self::assertSame($existing, $this->json($client));

        static::getContainer()->get('doctrine.orm.entity_manager')->getConnection()
            ->executeStatement('UPDATE client SET status = ? WHERE id = ?', ['disabled', $user->getId()]);
        $client->jsonRequest('POST', '/api/v1/wardrobe-app/auth/login', [
            'email' => $user->getEmail(), 'password' => UserFactory::PASSWORD, 'deviceId' => 'iphone-disabled-1',
        ]);
        self::assertResponseStatusCodeSame(401);
        self::assertSame($existing, $this->json($client));
    }

    public function testSecondLoginForSameDeviceRevokesPreviousCredentials(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), $this->email('same-device'));
        $first = $this->login($client, $user, 'stable-ios-installation');
        $second = $this->login($client, $user, 'stable-ios-installation');

        $this->bearer($client, $first['accessToken'], '/api/v1/wardrobe-app/bootstrap');
        self::assertResponseStatusCodeSame(401);
        $client->jsonRequest('POST', '/api/v1/wardrobe-app/auth/refresh', ['refreshToken' => $first['refreshToken']]);
        self::assertResponseStatusCodeSame(401);
        $this->bearer($client, $second['accessToken'], '/api/v1/wardrobe-app/bootstrap');
        self::assertResponseIsSuccessful();
    }

    public function testRefreshRotatesBothTokensAndReplayRevokesDeviceSession(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), $this->email('rotation'));
        $first = $this->login($client, $user);

        $client->jsonRequest('POST', '/api/v1/wardrobe-app/auth/refresh', ['refreshToken' => $first['refreshToken']]);
        self::assertResponseIsSuccessful();
        $second = $this->json($client);
        self::assertNotSame($first['accessToken'], $second['accessToken']);
        self::assertNotSame($first['refreshToken'], $second['refreshToken']);
        $this->bearer($client, $first['accessToken'], '/api/v1/wardrobe-app/bootstrap');
        self::assertResponseStatusCodeSame(401);
        $this->bearer($client, $second['accessToken'], '/api/v1/wardrobe-app/bootstrap');
        self::assertResponseIsSuccessful();

        $client->jsonRequest('POST', '/api/v1/wardrobe-app/auth/refresh', ['refreshToken' => $first['refreshToken']]);
        self::assertResponseStatusCodeSame(401);
        $this->bearer($client, $second['accessToken'], '/api/v1/wardrobe-app/bootstrap');
        self::assertResponseStatusCodeSame(401);
        $client->jsonRequest('POST', '/api/v1/wardrobe-app/auth/refresh', ['refreshToken' => $second['refreshToken']]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testExpiredRefreshAndPerDeviceRevokeAreRejected(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), $this->email('revoke'));
        $expired = $this->login($client, $user, 'iphone-expired-refresh');
        static::getContainer()->get('doctrine.orm.entity_manager')->getConnection()->executeStatement(
            'UPDATE native_refresh_token SET expires_at = ? WHERE token_hash = ?',
            [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), hash('sha256', $expired['refreshToken'])],
        );
        $client->jsonRequest('POST', '/api/v1/wardrobe-app/auth/refresh', ['refreshToken' => $expired['refreshToken']]);
        self::assertResponseStatusCodeSame(401);

        $tokens = $this->login($client, $user, 'iphone-current-device');
        $this->bearer($client, $tokens['accessToken'], '/api/v1/wardrobe-app/auth/revoke', 'POST');
        self::assertResponseIsSuccessful();
        $this->bearer($client, $tokens['accessToken'], '/api/v1/wardrobe-app/bootstrap');
        self::assertResponseStatusCodeSame(401);
    }

    public function testRevokeAllInvalidatesEveryDevice(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), $this->email('revoke-all'));
        $first = $this->login($client, $user, 'iphone-first-device');
        $second = $this->login($client, $user, 'ipad-second-device');

        $this->bearer($client, $second['accessToken'], '/api/v1/wardrobe-app/auth/revoke-all', 'POST');
        self::assertResponseIsSuccessful();
        $this->bearer($client, $first['accessToken'], '/api/v1/wardrobe-app/bootstrap');
        self::assertResponseStatusCodeSame(401);
        $this->bearer($client, $second['accessToken'], '/api/v1/wardrobe-app/bootstrap');
        self::assertResponseStatusCodeSame(401);
    }

    public function testNativeChildTokenCannotReadParentSiblingOrForeignWardrobe(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), $this->email('family-parent'));
        $families = static::getContainer()->get(FamilyService::class);
        $child = $families->createChild($parent, 'Лиза');
        $sibling = $families->createChild($parent, 'Маша');
        $foreign = UserFactory::withEmail(static::getContainer(), $this->email('foreign'));
        $password = 'Native-child-password-2026';
        $child->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($child, $password));
        static::getContainer()->get('doctrine.orm.entity_manager')->flush();
        $tokens = $this->login($client, $child, 'iphone-child-device', $password);

        foreach ([$parent, $sibling, $foreign] as $forbidden) {
            $this->bearer($client, $tokens['accessToken'], '/api/v1/wardrobe-app/items?member='.$forbidden->getId());
            self::assertResponseStatusCodeSame(403);
            self::assertSame('member_forbidden', $this->json($client)['error']);
        }
    }

    public function testDeviceListUsesOpaqueIdsAllowlistedLabelsAndTracksLastUse(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), $this->email('device-list'));
        $first = $this->login($client, $user, 'iphone-list-one', UserFactory::PASSWORD, 'iphone');
        $second = $this->login($client, $user, 'ipad-list-two', UserFactory::PASSWORD, 'ipad');

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $first['device']['publicId']);
        self::assertSame('iphone', $first['device']['label']);
        self::assertNotSame((string) $user->getId(), $first['device']['publicId']);
        $this->bearer($client, $second['accessToken'], '/api/v1/wardrobe-app/auth/devices');
        self::assertResponseIsSuccessful();
        $devices = $this->json($client)['devices'];
        self::assertCount(2, $devices);
        $byId = array_column($devices, null, 'publicId');
        self::assertFalse($byId[$first['device']['publicId']]['current']);
        self::assertTrue($byId[$second['device']['publicId']]['current']);
        self::assertSame('ipad', $byId[$second['device']['publicId']]['label']);
        self::assertNotNull($byId[$second['device']['publicId']]['lastUsedAt']);

        $client->jsonRequest('POST', '/api/v1/wardrobe-app/auth/login', [
            'email' => $user->getEmail(), 'password' => UserFactory::PASSWORD,
            'deviceId' => 'invalid-label-device', 'deviceLabel' => 'Anna iPhone',
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_device_label', $this->json($client)['error']);
    }

    public function testOwnerCanRevokeOtherAndCurrentDevice(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), $this->email('device-revoke'));
        $other = $this->login($client, $user, 'revoke-other-device');
        $current = $this->login($client, $user, 'revoke-current-device');

        $this->bearer($client, $current['accessToken'], '/api/v1/wardrobe-app/auth/devices/'.$other['device']['publicId'], 'DELETE');
        self::assertResponseIsSuccessful();
        $this->bearer($client, $other['accessToken'], '/api/v1/wardrobe-app/bootstrap');
        self::assertResponseStatusCodeSame(401);
        $client->jsonRequest('POST', '/api/v1/wardrobe-app/auth/refresh', ['refreshToken' => $other['refreshToken']]);
        self::assertResponseStatusCodeSame(401);

        $this->bearer($client, $current['accessToken'], '/api/v1/wardrobe-app/auth/devices/'.$current['device']['publicId'], 'DELETE');
        self::assertResponseIsSuccessful();
        $this->bearer($client, $current['accessToken'], '/api/v1/wardrobe-app/bootstrap');
        self::assertResponseStatusCodeSame(401);
    }

    public function testDeviceRevokeDoesNotExposeOrModifyForeignSession(): void
    {
        $client = static::createClient();
        $owner = UserFactory::withEmail(static::getContainer(), $this->email('device-owner'));
        $attacker = UserFactory::withEmail(static::getContainer(), $this->email('device-attacker'));
        $victim = $this->login($client, $owner, 'victim-device');
        $foreign = $this->login($client, $attacker, 'attacker-device');

        $this->bearer($client, $foreign['accessToken'], '/api/v1/wardrobe-app/auth/devices/'.$victim['device']['publicId'], 'DELETE');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('device_not_found', $this->json($client)['error']);
        $this->bearer($client, $victim['accessToken'], '/api/v1/wardrobe-app/bootstrap');
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> */
    private function login(KernelBrowser $client, User $user, string $deviceId = 'iphone-test-device', string $password = UserFactory::PASSWORD, string $deviceLabel = 'other'): array
    {
        $client->jsonRequest('POST', '/api/v1/wardrobe-app/auth/login', [
            'email' => $user->getEmail(), 'password' => $password, 'deviceId' => $deviceId, 'deviceLabel' => $deviceLabel,
        ]);
        self::assertResponseIsSuccessful();

        return $this->json($client);
    }

    private function bearer(KernelBrowser $client, string $token, string $path, string $method = 'GET'): void
    {
        $client->request($method, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function email(string $prefix): string { return $prefix.'-'.bin2hex(random_bytes(5)).'@test.local'; }
}
