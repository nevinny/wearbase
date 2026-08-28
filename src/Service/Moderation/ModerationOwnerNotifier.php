<?php

declare(strict_types=1);

namespace App\Service\Moderation;

use App\Entity\Brand;
use App\Entity\BrandModeration;
use App\Entity\BrandUser;
use App\Entity\Notification;
use App\Notification\NotificationDispatcher;
use App\Repository\BrandUserRepository;

/**
 * Сообщает владельцу самрег-бренда решение премодерации.
 *
 * До 28.08.2026 решение жило только в БД: BrandModerationController::decide()
 * менял статус и всё — ни письма, ни in-app. Владелец «Русского бренда АХ!»
 * месяц не знал, что от него ждут цену, ИНН и нормальный логотип.
 */
final class ModerationOwnerNotifier
{
    public function __construct(
        private readonly NotificationDispatcher $notifier,
        private readonly BrandUserRepository $brandUsers,
    ) {}

    public function notify(Brand $brand, BrandModeration $moderation): void
    {
        $owner = $this->brandUsers->findOneBy(['brand' => $brand, 'role' => BrandUser::ROLE_OWNER])?->getUser();
        if ($owner === null) {
            return; // каталожная карточка без владельца — некому писать
        }

        $title = match ($moderation->getStatus()) {
            BrandModeration::STATUS_APPROVED          => "Бренд «{$brand->getTitle()}» одобрен",
            BrandModeration::STATUS_CHANGES_REQUESTED => "Бренд «{$brand->getTitle()}»: нужно дополнить карточку",
            BrandModeration::STATUS_REJECTED          => "Бренд «{$brand->getTitle()}»: карточка отклонена",
            default                                   => null,
        };
        if ($title === null) {
            return; // queued/reviewed — решения ещё нет, владельцу писать не о чем
        }

        $this->notifier->dispatch(
            $owner,
            Notification::TYPE_SYSTEM,
            $title,
            $this->body($moderation),
            ['brand_id' => $brand->getId(), 'moderation_id' => $moderation->getId()],
            'brand_moderation_result',
            ['brand' => $brand, 'moderation' => $moderation],
            // Одно письмо на решение: повторный клик по той же кнопке не задваивает.
            sprintf('moderation:%d:%s', (int) $moderation->getId(), $moderation->getStatus()),
        );
    }

    private function body(BrandModeration $moderation): string
    {
        return match ($moderation->getStatus()) {
            BrandModeration::STATUS_APPROVED => 'Карточка встала в очередь публикации и появится в каталоге в течение часа.',
            BrandModeration::STATUS_REJECTED => $moderation->getAdminNote() ?? 'Карточка отклонена модератором.',
            default => 'Чтобы опубликовать карточку, дополните её: '
                . implode('; ', ModerationLabels::missing($moderation->getMissing())),
        };
    }
}
