<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Brand;
use App\Entity\BrandModeration;
use App\Entity\BrandUser;
use App\Entity\OfferDocument;
use App\Entity\Order;
use App\Entity\User;
use App\Repository\BrandModerationRepository;
use App\Repository\BrandUserRepository;
use App\Repository\NotificationRepository;
use App\Repository\OfferAcceptanceRepository;
use App\Repository\OfferDocumentRepository;
use App\Repository\OrderRepository;
use App\Service\Moderation\ModerationLabels;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Счётчики для навигации ЛК бренда:
 *   {{ brand_new_orders_count() }}            → новых заказов активного бренда
 *   {{ brand_unread_notifications_count() }}  → непрочитанных уведомлений пользователя
 *   {{ brand_moderation() }}                  → заявка на премодерацию активного бренда (или null)
 *   {{ moderation_missing(mod.missing) }}     → коды премодерации человеческим языком
 *
 * Резолвят активный бренд/пользователя сами (через Security), поэтому работают
 * в layout без проброса данных из каждого контроллера. Кешируют на запрос.
 */
class BrandLkExtension extends AbstractExtension
{
    private bool $resolved = false;
    private ?Brand $brand = null;

    public function __construct(
        private readonly Security $security,
        private readonly BrandUserRepository $brandUsers,
        private readonly BrandModerationRepository $moderations,
        private readonly OrderRepository $orders,
        private readonly NotificationRepository $notifications,
        private readonly OfferDocumentRepository $offers,
        private readonly OfferAcceptanceRepository $acceptances,
        private readonly RequestStack $requestStack,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('brand_new_orders_count', [$this, 'newOrdersCount']),
            new TwigFunction('brand_unread_notifications_count', [$this, 'unreadNotificationsCount']),
            new TwigFunction('brand_seller_offer_pending', [$this, 'sellerOfferPending']),
            new TwigFunction('brand_moderation', [$this, 'moderation']),
            new TwigFunction('moderation_missing', [ModerationLabels::class, 'missing']),
            new TwigFunction('moderation_flags', [ModerationLabels::class, 'flags']),
        ];
    }

    /**
     * Заявка на премодерацию активного бренда — чтобы ЛК показывал владельцу,
     * на какой стадии его карточка и что от него нужно. Null, если бренд
     * заведён не через самрег (у каталожных карточек заявки нет).
     */
    public function moderation(): ?BrandModeration
    {
        $brand = $this->activeBrand();

        return $brand !== null ? $this->moderations->findOneBy(['brand' => $brand]) : null;
    }

    /** Должен ли владелец принять действующую оферту продавца (не принял текущую редакцию). */
    public function sellerOfferPending(): bool
    {
        $user = $this->currentUser();
        if ($user === null) {
            return false;
        }
        // Принимает только владелец — менеджеров не дёргаем
        if ($this->brandUsers->findOneBy(['user' => $user])?->getRole() !== BrandUser::ROLE_OWNER) {
            return false;
        }

        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'ru';
        $offer = $this->offers->findCurrentPublished(OfferDocument::TYPE_SELLER_OFFER, $locale)
            ?? $this->offers->findCurrentPublished(OfferDocument::TYPE_SELLER_OFFER, 'ru');

        return $offer !== null && !$this->acceptances->hasAccepted($user, $offer);
    }

    public function newOrdersCount(): int
    {
        $brand = $this->activeBrand();

        return $brand !== null
            ? $this->orders->count(['brand' => $brand, 'status' => Order::STATUS_NEW])
            : 0;
    }

    public function unreadNotificationsCount(): int
    {
        $user = $this->currentUser();

        return $user !== null ? $this->notifications->countUnread($user) : 0;
    }

    private function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    private function activeBrand(): ?Brand
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $user = $this->currentUser();
            $this->brand = $user !== null
                ? $this->brandUsers->findOneBy(['user' => $user])?->getBrand()
                : null;
        }

        return $this->brand;
    }
}
