<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AioRemediation;
use App\Entity\BrandFaq;
use App\Entity\User;
use App\Notification\AdminNotifier;
use App\Notification\TelegramNotifier;
use App\Service\Agent\BrandUnpublisher;
use App\Service\Telegram\WardrobeDialogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/telegram', name: 'telegram_')]
class TelegramController extends AbstractController
{
    #[Route('/webhook', name: 'webhook', methods: ['POST'])]
    public function webhook(
        Request $request,
        EntityManagerInterface $em,
        TelegramNotifier $telegramNotifier,
        AdminNotifier $adminNotifier,
        BrandUnpublisher $unpublisher,
        WardrobeDialogService $wardrobeDialog,
        \Psr\Log\LoggerInterface $telegramLogger,
    ): Response {
        $body = json_decode($request->getContent(), true);

        // Нажатие inline-кнопки (напр. «🚫 Скрыть с публикации» под уведомлением о публикации).
        if (is_array($body) && isset($body['callback_query'])) {
            return $this->handleCallback($body['callback_query'], $em, $telegramNotifier, $adminNotifier, $unpublisher, $wardrobeDialog, $telegramLogger);
        }

        if (!$body || !isset($body['message'])) {
            return new JsonResponse(['ok' => false]);
        }

        $message  = $body['message'];
        $chatId   = (string) ($message['chat']['id'] ?? '');
        $text     = trim($message['text'] ?? '');
        $username = $message['from']['username'] ?? '';
        $isAdmin  = $adminNotifier->isAdminChat($chatId);

        // Контакт-воронка, обратная сторона: админ отвечает REPLY на пересланное
        // сообщение визитёра (с меткой [#chatId]) → бот релеит ответ визитёру.
        // Личный аккаунт админа не раскрывается — визитёр видит только бота.
        if ($isAdmin && $text !== '' && isset($message['reply_to_message']['text'])
            && preg_match('/\[#(-?\d+)\]/', (string) $message['reply_to_message']['text'], $mm)) {
            $telegramNotifier->send($mm[1], "💬 <b>Ответ от WEARBASE</b>\n\n" . htmlspecialchars($text));
            $telegramNotifier->send($chatId, '✅ Отправлено визитёру.');
            return new JsonResponse(['ok' => true]);
        }

        // «Мой гардероб»: у привязанного пользователя сообщение сперва предлагаем диалогу.
        // false = не его (нет активного черновика и не /wardrobe|/cancel) → обычная логика ниже.
        $linkedUser = $chatId !== '' ? $em->getRepository(User::class)->findOneBy(['telegramChatId' => $chatId]) : null;
        if ($linkedUser !== null && $wardrobeDialog->handle($linkedUser, $message, (int) ($body['update_id'] ?? 0))) {
            return new JsonResponse(['ok' => true]);
        }

        if (str_starts_with($text, '/start ')) {
            $token = substr($text, 7);
            $user = $em->getRepository(User::class)->findOneBy(['telegramLinkToken' => $token]);
            if ($user) {
                $user->setTelegramChatId($chatId);
                $user->setTelegramLinkToken(null);
                $em->flush();
                $telegramNotifier->send($chatId, "✅ Telegram привязан к вашему аккаунту WEARBASE!\n\n📦 Команда /wardrobe — вести учёт своего гардероба прямо здесь.");
            } else {
                $telegramNotifier->send($chatId, '❌ Ссылка устарела. Сгенерируйте новую в настройках безопасности на сайте.');
            }
        } elseif ($text === '/start') {
            $telegramNotifier->send($chatId, 'WEARBASE — каталог российских брендов одежды. 👋

Просто напишите сюда сообщение — мы получим его и ответим здесь же. Без звонков.

Если вы бренд и хотите привязать аккаунт для уведомлений — это в настройках безопасности на сайте.');
        } elseif (!$isAdmin && $text !== '' && !str_starts_with($text, '/')) {
            // Контакт-воронка, входящая сторона: любое сообщение от не-админа →
            // пересылаем админу (с меткой для reply-релея) + подтверждаем визитёру.
            $from  = $message['from'] ?? [];
            $name  = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
            $uname = $username !== '' ? '@' . $username : 'без username';
            $adminNotifier->send(sprintf(
                "✉️ <b>Сообщение в бот WEARBASE</b>\nОт: %s (%s)\n[#%s]\n\n%s\n\n<i>Ответьте reply на это сообщение — бот перешлёт ответ визитёру.</i>",
                htmlspecialchars($name !== '' ? $name : 'аноним'),
                htmlspecialchars($uname),
                $chatId,
                htmlspecialchars(mb_substr($text, 0, 3000)),
            ));
            $telegramNotifier->send($chatId, 'Спасибо! Получили ваше сообщение — ответим здесь же. Можно ничего больше не писать. 🙌');
        }

        return new JsonResponse(['ok' => true]);
    }

    /** Обработка нажатия inline-кнопки. «unpub:<id>» — скрыть бренд (admin-only); «aioapply:/aioreject:<id>» — ремедиация AIO-утечки (admin-only); «wl:*»/«wa:*» — гардероб (по привязке chatId→User). */
    private function handleCallback(
        array $cq,
        EntityManagerInterface $em,
        TelegramNotifier $telegram,
        AdminNotifier $adminNotifier,
        BrandUnpublisher $unpublisher,
        WardrobeDialogService $wardrobeDialog,
        \Psr\Log\LoggerInterface $telegramLogger,
    ): Response {
        $cqId    = (string) ($cq['id'] ?? '');
        $data    = (string) ($cq['data'] ?? '');
        $chatId  = (string) ($cq['message']['chat']['id'] ?? $cq['from']['id'] ?? '');
        $msgId   = $cq['message']['message_id'] ?? null;
        $msgText = (string) ($cq['message']['text'] ?? '');

        $telegramLogger->info('TG callback', ['chat' => $chatId, 'data' => $data, 'admin' => $adminNotifier->isAdminChat($chatId)]);

        // Кнопки гардероба (wl:*/wa:*): авторизация — привязка chatId→User, admin-чат не требуется.
        if (str_starts_with($data, 'wl:') || str_starts_with($data, 'wa:')) {
            $user = $chatId !== '' ? $em->getRepository(User::class)->findOneBy(['telegramChatId' => $chatId]) : null;
            if ($user === null) {
                $telegramLogger->warning('TG wl: callback от непривязанного чата', ['chat' => $chatId, 'data' => $data]);
                $telegram->answerCallbackQuery($cqId, 'Не авторизовано');
                return new JsonResponse(['ok' => true]);
            }
            $wardrobeDialog->handleCallback($user, $data, $chatId);
            $telegram->answerCallbackQuery($cqId, '');
            return new JsonResponse(['ok' => true]);
        }

        // Безопасность: скрывать бренды может ТОЛЬКО наш админ-чат (вебхук публичный).
        if (!$adminNotifier->isAdminChat($chatId)) {
            $telegramLogger->warning('TG callback от не-админ чата отклонён', ['chat' => $chatId, 'data' => $data]);
            $telegram->answerCallbackQuery($cqId, 'Не авторизовано');
            return new JsonResponse(['ok' => true]);
        }

        if (str_starts_with($data, 'unpub:')) {
            $res = $unpublisher->hide((int) substr($data, 6));
            $telegramLogger->info('TG hide-кнопка', ['data' => $data, 'ok' => $res['ok'], 'title' => $res['title'] ?? '', 'result' => $res['message']]);
            $telegram->answerCallbackQuery($cqId, $res['ok'] ? '🚫 Скрыт' : 'Не удалось');
            if ($msgId !== null) {
                $telegram->editMessageText($chatId, (int) $msgId, $msgText . "\n\n🚫 <b>Скрыт:</b> {$res['message']}");
            }
        } elseif (str_starts_with($data, 'aioapply:') || str_starts_with($data, 'aioreject:')) {
            $apply = str_starts_with($data, 'aioapply:');
            $id    = (int) substr($data, $apply ? 9 : 10);
            $note  = $this->handleAioRemediationCallback($em, $id, $apply);
            $telegramLogger->info('TG aio-ремедиация', ['data' => $data, 'note' => $note]);
            $telegram->answerCallbackQuery($cqId, $note !== '' ? $note : ($apply ? 'Применено' : 'Отклонено'));
            if ($msgId !== null && $note !== '') {
                $telegram->editMessageText($chatId, (int) $msgId, $msgText . "\n\n" . $note);
            }
        } else {
            $telegram->answerCallbackQuery($cqId, '');
        }

        return new JsonResponse(['ok' => true]);
    }

    /**
     * «aioapply:<id>» / «aioreject:<id>» (app:seo:aio-remediate, admin-only, вызывающая сторона
     * уже проверила isAdminChat). Apply создаёт brand_faq из предложенной пары и бампает
     * contentChangedAt пайплайна бренда (свежесть для доставки на прод, как FAQ_DONE в
     * GenerateBrandFaqCommand) — сам aio_remediation НИКОГДА не пишется автоматически,
     * только по этому клику. @return string короткая заметка для ответа/правки сообщения
     */
    private function handleAioRemediationCallback(EntityManagerInterface $em, int $id, bool $apply): string
    {
        $remediation = $em->find(AioRemediation::class, $id);
        if ($remediation === null || $remediation->getStatus() !== AioRemediation::STATUS_PENDING) {
            return 'Кандидат не найден или уже обработан';
        }

        if (!$apply) {
            $remediation->setStatus(AioRemediation::STATUS_REJECTED);
            $em->flush();
            return '❌ Отклонено';
        }

        $brand = $remediation->getBrand();
        if ($brand === null) {
            return 'Бренд не найден — применить нельзя';
        }

        /** @var \App\Repository\BrandFaqRepository $faqRepo */
        $faqRepo = $em->getRepository(BrandFaq::class);
        $position = count($faqRepo->findByBrandOrdered($brand));

        $em->persist((new BrandFaq())
            ->setBrand($brand)
            ->setQuestion($remediation->getProposedQuestion())
            ->setAnswer($remediation->getProposedAnswer())
            ->setPosition($position)
            ->setSource(BrandFaq::SOURCE_LLM));

        $remediation->setStatus(AioRemediation::STATUS_APPLIED)->setAppliedAt(new \DateTime());

        // Свежесть для прод-доставки — тот же маркер, что и обычная генерация FAQ (FAQ_DONE).
        $pipeline = $em->getRepository(\App\Entity\BrandRagPipeline::class)->getOrCreate($brand);
        $pipeline->setContentChangedAt(new \DateTime());

        $em->flush();

        return '✅ Применено в FAQ бренда «' . htmlspecialchars((string) $brand->getTitle()) . '»';
    }

    #[Route('/link', name: 'link')]
    public function link(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('telegram/link.html.twig', [
            'user' => $user,
        ]);
    }
}
