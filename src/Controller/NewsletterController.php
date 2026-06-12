<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\NewsletterSubscriber;
use App\Notification\EmailNotifier;
use App\Repository\NewsletterSubscriberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class NewsletterController extends AbstractController
{
    #[Route('/newsletter/subscribe', name: 'newsletter_subscribe', methods: ['POST'])]
    public function subscribe(
        Request $request,
        NewsletterSubscriberRepository $subscribers,
        EntityManagerInterface $em,
        EmailNotifier $notifier,
    ): Response {
        $back = $request->headers->get('referer', '/');

        if (!$this->isCsrfTokenValid('newsletter_subscribe', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Сессия истекла, попробуйте ещё раз');

            return $this->redirect($back);
        }

        $email = mb_strtolower(trim((string) $request->request->get('email', '')));
        $source = trim((string) $request->request->get('source', 'footer-subscribe'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Введите корректный email');

            return $this->redirect($back);
        }

        $subscriber = $subscribers->findOneBy(['email' => $email]);

        if ($subscriber && $subscriber->isActive()) {
            $this->addFlash('info', 'Вы уже подписаны на нашу рассылку');

            return $this->redirect($back);
        }

        if ($subscriber) {
            // Был отписан или не подтвердил — новый цикл double opt-in
            $subscriber->restartOptIn();
        } else {
            $subscriber = (new NewsletterSubscriber())
                ->setEmail($email)
                ->setSource($source);
            $em->persist($subscriber);
        }

        $em->flush();

        $notifier->send(
            $email,
            'Подтвердите подписку на новости WEARBASE',
            'newsletter_confirm',
            ['subscriber' => $subscriber],
        );

        $this->addFlash('success', 'Почти готово! Мы отправили письмо — подтвердите подписку по ссылке в нём.');

        return $this->redirect($back);
    }

    #[Route('/newsletter/confirm/{token}', name: 'newsletter_confirm', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function confirm(
        string $token,
        NewsletterSubscriberRepository $subscribers,
        EntityManagerInterface $em,
    ): Response {
        $subscriber = $subscribers->findOneBy(['confirmToken' => $token]);

        if (!$subscriber) {
            $this->addFlash('error', 'Ссылка подтверждения недействительна или устарела');

            return $this->redirectToRoute('home_hub', ['_locale' => 'ru']);
        }

        $subscriber->confirm();
        $em->flush();

        $this->addFlash('success', 'Подписка подтверждена! Будем присылать новые бренды и скидки.');

        return $this->redirectToRoute('home_hub', ['_locale' => 'ru']);
    }

    #[Route('/newsletter/unsubscribe/{token}', name: 'newsletter_unsubscribe', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function unsubscribe(
        string $token,
        NewsletterSubscriberRepository $subscribers,
        EntityManagerInterface $em,
    ): Response {
        $subscriber = $subscribers->findOneBy(['unsubscribeToken' => $token]);

        if (!$subscriber) {
            $this->addFlash('error', 'Ссылка отписки недействительна');

            return $this->redirectToRoute('home_hub', ['_locale' => 'ru']);
        }

        $subscriber->unsubscribe();
        $em->flush();

        $this->addFlash('info', 'Вы отписаны от рассылки. Передумаете — подписка в подвале сайта.');

        return $this->redirectToRoute('home_hub', ['_locale' => 'ru']);
    }
}
