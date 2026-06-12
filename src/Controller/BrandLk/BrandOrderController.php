<?php

declare(strict_types=1);

namespace App\Controller\BrandLk;

use App\Entity\Notification;
use App\Entity\Order;
use App\Entity\OrderStatusHistory;
use App\Notification\NotificationDispatcher;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/brand/orders', name: 'brand_order')]
class BrandOrderController extends BrandDashboardController
{
    #[Route('', name: 's')]
    public function index(Request $request, OrderRepository $repo): Response
    {
        $brand  = $this->getActiveBrand();
        $status = $request->query->get('status');

        $criteria = ['brand' => $brand];
        if ($status) {
            $criteria['status'] = $status;
        }

        $orders = $repo->findBy($criteria, ['createdAt' => 'DESC']);

        return $this->render('brand_lk/orders/index.html.twig', [
            'brand'          => $brand,
            'orders'         => $orders,
            'current_status' => $status,
            'status_labels'  => Order::getStatusLabels(),
        ]);
    }

    #[Route('/{id}', name: '_show')]
    public function show(Order $order): Response
    {
        $brand = $this->getActiveBrand();
        $this->denyUnlessOwns($order, $brand);

        return $this->render('brand_lk/orders/show.html.twig', [
            'brand'            => $brand,
            'order'            => $order,
            'allowed_statuses' => Order::getBrandAllowedTransitions()[$order->getStatus()] ?? [],
        ]);
    }

    #[Route('/{id}/status', name: '_status', methods: ['POST'])]
    public function updateStatus(
        Order $order,
        Request $request,
        EntityManagerInterface $em,
        NotificationDispatcher $notifier,
    ): Response {
        $brand = $this->getActiveBrand();
        $this->denyUnlessOwns($order, $brand);

        if (!$this->isCsrfTokenValid('order_status', $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('brand_order_show', ['id' => $order->getId()]);
        }

        $newStatus = $request->request->get('status');
        $comment   = $request->request->get('comment');

        $allowed = Order::getBrandAllowedTransitions()[$order->getStatus()] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            $this->addFlash('error', 'Недопустимый переход статуса');
            return $this->redirectToRoute('brand_order_show', ['id' => $order->getId()]);
        }

        // Записываем историю
        $history = new OrderStatusHistory();
        $history->setOrder($order);
        $history->setFromStatus($order->getStatus());
        $history->setToStatus($newStatus);
        $history->setComment($comment ?: null);
        $history->setCreatedBy($this->getUser());

        $order->setStatus($newStatus);
        $em->persist($history);
        $em->flush();

        $this->sendOrderNotification($notifier, $order, $newStatus);

        $this->addFlash('success', 'Статус обновлён: ' . Order::getStatusLabels()[$newStatus]);
        return $this->redirectToRoute('brand_order_show', ['id' => $order->getId()]);
    }

    #[Route('/{id}/ship', name: '_ship', methods: ['POST'])]
    public function ship(
        Order $order,
        Request $request,
        EntityManagerInterface $em,
        NotificationDispatcher $notifier,
    ): Response {
        $brand = $this->getActiveBrand();
        $this->denyUnlessOwns($order, $brand);

        if (!$this->isCsrfTokenValid('ship_order', $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('brand_order_show', ['id' => $order->getId()]);
        }

        $tracking = trim($request->request->get('tracking_number', ''));
        if ($tracking) {
            $order->setTrackingNumber($tracking);
        }

        $history = new OrderStatusHistory();
        $history->setOrder($order);
        $history->setFromStatus($order->getStatus());
        $history->setToStatus(Order::STATUS_SHIPPED);
        $history->setComment($tracking ? "Трекинг: $tracking" : null);
        $history->setCreatedBy($this->getUser());

        $order->setStatus(Order::STATUS_SHIPPED);
        $em->persist($history);
        $em->flush();

        $this->sendOrderNotification($notifier, $order, Order::STATUS_SHIPPED);

        $this->addFlash('success', 'Заказ отмечен как отправленный');
        return $this->redirectToRoute('brand_order_show', ['id' => $order->getId()]);
    }

    /** ЗоЗПП: бренд подтверждает, что покупатель получил товар. */
    #[Route('/{id}/confirm-delivery', name: '_confirm_delivery', methods: ['POST'])]
    public function confirmDelivery(Order $order, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyUnlessOwns($order, $this->getActiveBrand());

        if (!$this->isCsrfTokenValid('confirm_delivery', $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('brand_order_show', ['id' => $order->getId()]);
        }

        $order->setSellerDeliveryConfirmedAt(new \DateTimeImmutable());
        $em->flush();

        $this->addFlash('success', 'Отмечено: покупатель получил товар');
        return $this->redirectToRoute('brand_order_show', ['id' => $order->getId()]);
    }

    /** ЗоЗПП: зафиксировать поступившее требование возврата предоплаты (старт 10 дней). */
    #[Route('/{id}/refund/request', name: '_refund_request', methods: ['POST'])]
    public function recordRefundRequest(Order $order, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyUnlessOwns($order, $this->getActiveBrand());

        if (!$this->isCsrfTokenValid('refund_request', $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('brand_order_show', ['id' => $order->getId()]);
        }

        $dateStr = trim((string) $request->request->get('requested_at', ''));
        try {
            $requestedAt = $dateStr !== '' ? new \DateTimeImmutable($dateStr) : new \DateTimeImmutable();
        } catch (\Exception) {
            $this->addFlash('error', 'Некорректная дата требования');
            return $this->redirectToRoute('brand_order_show', ['id' => $order->getId()]);
        }

        $order->setPrepaymentRefundRequestedAt($requestedAt);
        $em->flush();

        $this->addFlash('success', 'Требование возврата зафиксировано');
        return $this->redirectToRoute('brand_order_show', ['id' => $order->getId()]);
    }

    /** ЗоЗПП: отметить, что покупателю направлено подтверждение от продавца (гасит требование). */
    #[Route('/{id}/refund/confirmation-sent', name: '_refund_confirmation_sent', methods: ['POST'])]
    public function markRefundConfirmationSent(Order $order, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyUnlessOwns($order, $this->getActiveBrand());

        if (!$this->isCsrfTokenValid('refund_confirmation_sent', $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('brand_order_show', ['id' => $order->getId()]);
        }

        if ($order->getPrepaymentRefundRequestedAt() === null) {
            $this->addFlash('error', 'Сначала зафиксируйте требование возврата');
            return $this->redirectToRoute('brand_order_show', ['id' => $order->getId()]);
        }

        $order->setRefundConfirmationSentAt(new \DateTimeImmutable());
        $em->flush();

        $this->addFlash('success', 'Отмечено: подтверждение направлено покупателю');
        return $this->redirectToRoute('brand_order_show', ['id' => $order->getId()]);
    }

    /** Сохранить внутреннюю заметку по заказу (не видна покупателю). */
    #[Route('/{id}/note', name: '_note', methods: ['POST'])]
    public function saveNote(Order $order, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyUnlessOwns($order, $this->getActiveBrand());

        if (!$this->isCsrfTokenValid('order_note', $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('brand_order_show', ['id' => $order->getId()]);
        }

        $note = trim((string) $request->request->get('admin_note', ''));
        $order->setAdminNote($note !== '' ? $note : null);
        $em->flush();

        $this->addFlash('success', 'Заметка сохранена');
        return $this->redirectToRoute('brand_order_show', ['id' => $order->getId()]);
    }

    private function sendOrderNotification(NotificationDispatcher $notifier, Order $order, string $newStatus): void
    {
        $notifier->dispatch(
            $order->getCustomer(),
            $newStatus === Order::STATUS_SHIPPED ? Notification::TYPE_ORDER_SHIPPED : Notification::TYPE_ORDER_STATUS,
            $newStatus === Order::STATUS_SHIPPED
                ? 'Ваш заказ отправлен'
                : 'Статус заказа #' . $order->getOrderNumber() . ' изменён на ' . $order->getStatusLabel(),
            null,
            ['order_id' => $order->getId(), 'order_number' => $order->getOrderNumber(), 'status' => $newStatus],
            'order_status_changed',
            ['order' => $order],
        );
    }

    private function denyUnlessOwns(Order $order, \App\Entity\Brand $brand): void
    {
        if ($order->getBrand() !== $brand) {
            throw $this->createAccessDeniedException();
        }
    }
}
