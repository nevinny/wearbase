<?php

declare(strict_types=1);

namespace App\Controller\Look;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeItemPhoto;
use App\Entity\WardrobeOutfitShare;
use App\Repository\WardrobeOutfitShareRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use App\Service\Look\LookShareOgCardRenderer;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Гостевой лендинг лука «Поделиться луком» (_docs/outfit-sharing-spec.md §2).
 *
 * Публичный префикс /l/ вне firewall-правила ^/account. Никаких персональных данных,
 * кроме разрешённого набора §4.4: title, explanation, фото обложки, категория/цвет.
 * Бренды скрыты (решение PO №4), имена владельцев/детей не рендерятся никогда.
 */
#[Route('/l', name: 'look_shared_')]
final class LookShareController extends AbstractController
{
    /** UI-превью/боты/скрипты — не реальные просмотры (паттерн SocialIngestClicksCommand::BOT_UA_RE). */
    private const BOT_UA_RE = '/bot|spider|crawl|preview|vkshare|whatsapp|curl|wget|python|go-http|facebookexternalhit|snippet/i';

    public function __construct(
        private readonly WardrobeOutfitShareRepository $shares,
        private readonly EntityManagerInterface $em,
        private readonly LookShareOgCardRenderer $ogRenderer,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    #[Route('/{token}', name: 'show', requirements: ['token' => '[0-9a-f]{64}'], methods: ['GET'])]
    public function show(Request $request, string $token, RateLimiterFactory $lookShareLimiter): Response
    {
        if (!$lookShareLimiter->create($this->limiterKey($request))->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        // Истёкшая/отозванная/pending_parent ссылка → нейтральный 410 + no-store:
        // без утечки факта существования лука и без кэширования ответа (§1.4).
        $share = $this->shares->findByToken($token);
        if ($share === null || !$share->isViewable()) {
            return $this->gone();
        }

        // ?ref={shareToken} — referral-хук (§7): держим связку в сессии до регистрации.
        $ref = (string) $request->query->get('ref');
        if (preg_match('/^[0-9a-f]{64}$/', $ref) === 1 && $this->shares->findByToken($ref)?->isViewable() === true) {
            $request->getSession()->set('look_share_ref', $ref);
            // Возврат после регистрации/логина: LoginSuccessHandler приоритетно чтит target_path.
            $request->getSession()->set('_security.main.target_path', $this->generateUrl('look_shared_show', ['token' => $token]));
        }

        $guestItems = $this->guestItems($share);

        $response = $this->render('look/shared.html.twig', [
            'outfitTitle' => $share->getOutfit()->getTitle(),
            'explanation' => $share->getOutfit()->getExplanation(),
            'token' => $token,
            'items' => $guestItems,
            'isGuestLoggedIn' => $this->getUser() instanceof User,
            'canonicalUrl' => $this->generateUrl('look_shared_show', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
            'sharePath' => $this->generateUrl('look_shared_show', ['token' => $token]),
            'ogDescription' => $this->ogDescription($share->getOutfit()->getExplanation()),
        ]);

        // Счётчик просмотров §6: быстрый атомарный UPDATE при сборке ответа, боты отсечены
        // по User-Agent; contention низкий (одна ссылка = один канал), транзакции не нужны.
        if (!preg_match(self::BOT_UA_RE, (string) $request->headers->get('User-Agent'))) {
            $this->em->getConnection()->executeStatement(
                'UPDATE wardrobe_outfit_share SET view_count = view_count + 1, last_viewed_at = ? WHERE id = ?',
                [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $share->getId()],
            );
        }

        return $this->harden($response);
    }

    /**
     * Фото вещи из снапшота лука. Прямое переиспользование account_wardrobe_media_* невозможно
     * (тот за firewall'ом); здесь авторизация — сам shareToken: photoId обязан принадлежать вещи
     * из ЭТОГО share (чек-лист утечек §4.3 — никакого перебора голых photoId).
     */
    #[Route('/media/{token}/{photoId}', name: 'media', requirements: ['token' => '[0-9a-f]{64}', 'photoId' => '\\d+'], methods: ['GET'])]
    public function media(string $token, int $photoId, StorageInterface $storage): Response
    {
        $share = $this->shares->findByToken($token);
        if ($share === null || !$share->isViewable()) {
            throw $this->createNotFoundException();
        }

        $photo = $this->em->find(WardrobeItemPhoto::class, $photoId);
        if ($photo === null || $photo->isDeleted() || !$this->isPhotoInShare($share, $photo)) {
            throw $this->createNotFoundException();
        }

        return $this->mediaResponse($storage->resolvePath($photo, 'file'), $photo->getFilePath());
    }

    /**
     * OG-карта для мессенджеров (§3): ленивая генерация в приватное хранилище + раздача.
     * Публичный кэш допустим: секрет — сам URL; ревок отдаёт 404 (превью в переписке
     * всё равно остаётся, §3.3).
     */
    #[Route('/{token}/og.png', name: 'og', requirements: ['token' => '[0-9a-f]{64}'], methods: ['GET'])]
    public function og(string $token): Response
    {
        $share = $this->shares->findByToken($token);
        if ($share === null || !$share->isViewable()) {
            throw $this->createNotFoundException();
        }

        $path = $this->ogRenderer->renderFor($share);
        if ($path === null || !is_file($path)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->setPublic();
        $response->setMaxAge(3600);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /** Краткая og:description: explanation одной строкой, обрезка ~160 символов. */
    private function ogDescription(?string $explanation): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $explanation) ?? '');
        if ($text === '') {
            return 'Образ из гардероба WEARBASE — собери свой из вещей российских брендов';
        }

        return mb_strlen($text) > 160 ? mb_substr($text, 0, 159).'…' : $text;
    }

    /** @return list<array{id:int,category:?string,color:?string,coverPhotoId:?int}> */
    private function guestItems(WardrobeOutfitShare $share): array
    {
        $owner = $share->getOutfit()->getWardrobeOwner();
        $snapshotIds = [];
        foreach ($share->getOutfit()->getItems() as $item) {
            if (isset($item['id'])) {
                $snapshotIds[(int) $item['id']] = $item; // ключ = id: дедупликация снапшота
            }
        }
        if ($snapshotIds === []) {
            return [];
        }

        $photos = $this->em->getRepository(WardrobeItemPhoto::class)->findBy(['id' => array_keys($snapshotIds)]);
        $coverByItemId = [];
        foreach ($photos as $photo) {
            $itemId = $photo->getItem()?->getId();
            // Фотография обязана принадлежать вещи из гардероба того же владельца (§2.2).
            if (!$photo->isDeleted() && $itemId !== null && $photo->getItem()?->getUser()?->getId() === $owner?->getId()) {
                $coverByItemId[$itemId] ??= $photo->getId(); // первая (минимальный id) как обложка
            }
        }

        $items = $this->em->getRepository(WardrobeItem::class)->findBy(['id' => array_keys($snapshotIds)]);
        $result = [];
        foreach ($snapshotIds as $id => $entry) {
            foreach ($items as $item) {
                if ($item->getId() !== $id || $item->getUser()?->getId() !== $owner?->getId()) {
                    continue;
                }
                $result[] = [
                    'category' => isset($entry['category']) ? (string) $entry['category'] : null,
                    'color' => isset($entry['color']) ? (string) $entry['color'] : null,
                    'coverPhotoId' => $coverByItemId[$id] ?? null,
                ];
                break;
            }
        }

        return $result;
    }

    private function isPhotoInShare(WardrobeOutfitShare $share, WardrobeItemPhoto $photo): bool
    {
        $owner = $share->getOutfit()->getWardrobeOwner();
        $item = $photo->getItem();

        return $item !== null
            && $item->getUser()?->getId() === $owner?->getId()
            && in_array($item->getId(), array_map(static fn (array $entry): int => (int) $entry['id'], $share->getOutfit()->getItems()), true);
    }

    private function gone(): Response
    {
        return $this->harden($this->render('look/gone.html.twig', [], new Response('', Response::HTTP_GONE)));
    }

    /** Персональная страница: noindex,follow (§5), no-referrer (§4.3), no-store. */
    private function harden(Response $response): Response
    {
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, follow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function limiterKey(Request $request): string
    {
        return ($request->getClientIp() ?? 'unknown').'|'.(string) $request->attributes->get('token', '');
    }

    private function mediaResponse(?string $path, ?string $legacyName): BinaryFileResponse
    {
        if (($path === null || !is_file($path)) && $legacyName !== null) {
            $root = realpath($this->projectDir.'/public_html/images/wardrobe');
            if ($root !== false && basename($legacyName) === $legacyName) {
                $relativePaths = [
                    $legacyName,
                    mb_substr($legacyName, 0, 2).'/'.mb_substr($legacyName, 2, 2).'/'.$legacyName,
                ];
                foreach ($relativePaths as $relativePath) {
                    $legacyPath = realpath($root.'/'.$relativePath);
                    if ($legacyPath !== false && str_starts_with($legacyPath, $root.DIRECTORY_SEPARATOR)) {
                        $path = $legacyPath;
                        break;
                    }
                }
            }
        }
        if ($path === null || !is_file($path)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
