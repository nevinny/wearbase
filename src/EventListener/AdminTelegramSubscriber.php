<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\LandingLead;
use App\Entity\Order;
use App\Entity\Subscription;
use App\Entity\User;
use App\Notification\AdminNotifier;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Шлёт админу проекта Telegram-уведомления о новых сущностях
 * (регистрации, лиды, заказы, подписки).
 *
 * BrandClaim здесь НЕ обрабатывается: строка заявки создаётся при открытии формы
 * (GET), а не при подаче — пинг на вставку означал «зашёл на страницу». О реальной
 * подаче админа уведомляет BrandClaimController::notifyAdmin().
 *
 * onFlush — собираем вставки; postFlush — отправляем после коммита,
 * чтобы HTTP-вызов не происходил внутри транзакции.
 */
#[AsDoctrineListener(Events::onFlush)]
#[AsDoctrineListener(Events::postFlush)]
class AdminTelegramSubscriber
{
    /** @var string[] */
    private array $pending = [];

    public function __construct(private readonly AdminNotifier $admin) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->admin->isEnabled()) {
            return;
        }

        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $message = $this->buildMessage($entity);
            if ($message !== null) {
                $this->pending[] = $message;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pending === []) {
            return;
        }

        $messages = $this->pending;
        $this->pending = [];
        foreach ($messages as $message) {
            $this->admin->send($message);
        }
    }

    public function buildMessage(object $entity): ?string
    {
        return match (true) {
            $entity instanceof User => "🆕 <b>Новая регистрация</b>\n" . $this->e((string) $entity->getEmail()),

            $entity instanceof LandingLead => "📨 <b>Лид с лендинга</b>\n"
                . $this->e($entity->getEmail())
                . ($entity->getSource() ? ' (' . $this->e((string) $entity->getSource()) . ')' : '')
                . ($entity->getBrandName() ? "\nБренд: " . $this->e((string) $entity->getBrandName()) : '')
                . ($entity->getWebsite() ? "\nСайт: " . $this->e((string) $entity->getWebsite()) : ''),

            $entity instanceof Order => "🛒 <b>Новый заказ</b> " . $this->e((string) $entity->getOrderNumber())
                . "\nСумма: " . $this->e($entity->getTotalAmount()) . ' ₽',

            $entity instanceof Subscription => "💳 <b>Новая подписка</b>\n"
                . 'Бренд «' . $this->e((string) $entity->getBrand()?->getTitle()) . '», тариф '
                . $this->e((string) $entity->getTariff()?->getCode()),

            default => null,
        };
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
