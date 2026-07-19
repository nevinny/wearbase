<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Brand;
use App\Entity\BrandClaim;
use App\Entity\Notification;
use App\Entity\User;
use App\Notification\AdminNotifier;
use App\Notification\EmailNotifier;
use App\Notification\NotificationDispatcher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use App\Repository\BrandClaimRepository;
use App\Repository\BrandUserRepository;
use App\Service\BrandClaimService;
use App\Service\VkVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/brand-claim', name: 'brand_claim_')]
class BrandClaimController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BrandClaimRepository   $claimRepo,
        private readonly BrandUserRepository    $brandUserRepo,
        private readonly BrandClaimService      $claimService,
        private readonly VkVerifier             $vkVerifier,
        private readonly NotificationDispatcher $notifier,
        private readonly EmailNotifier          $emailNotifier,
        private readonly AdminNotifier          $adminNotifier,
        #[Autowire('%env(default::ADMIN_EMAIL)%')]
        private readonly ?string                $adminEmail,
    ) {}

    // ── Форма заявки (выбор метода) ──────────────────────────────────────────

    #[Route('/{id}', name: 'new', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function new(Brand $brand): Response
    {
        $this->denyIfForeign($brand);

        /** @var User $user */
        $user = $this->getUser();

        if ($this->brandUserRepo->findOneBy(['brand' => $brand, 'user' => $user])) {
            $this->addFlash('info', 'Вы уже являетесь участником команды этого бренда');
            return $this->redirectToRoute('brand_dashboard');
        }

        $claim = $this->getOrCreateClaim($brand, $user);

        return $this->renderForm($brand, $claim);
    }

    // ── Метод: код на email бренда ───────────────────────────────────────────

    #[Route('/{id}/email/send', name: 'email_send', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function emailSend(Brand $brand, Request $request): Response
    {
        $this->denyIfForeign($brand);

        if (!$this->isCsrfTokenValid('brand_claim_email', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('brand_claim_new', ['id' => $brand->getId()]);
        }

        /** @var User $user */
        $user  = $this->getUser();
        $claim = $this->getOrCreateClaim($brand, $user);

        $result = $this->claimService->startEmailCode($claim);
        match ($result) {
            'sent'     => $this->addFlash('success', 'Код отправлен на email бренда. Проверьте почту бренда и введите код ниже.'),
            'cooldown' => $this->addFlash('error', 'Код уже отправлен. Повторная отправка возможна через минуту.'),
            'limit'    => $this->addFlash('error', 'Превышен лимит отправок кода. Используйте другой способ или обратитесь в поддержку.'),
            'no_email' => $this->addFlash('error', 'У бренда не указан email — выберите другой способ подтверждения.'),
            default    => null,
        };

        return $this->redirectToRoute('brand_claim_new', ['id' => $brand->getId()]);
    }

    #[Route('/{id}/email/verify', name: 'email_verify', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function emailVerify(Brand $brand, Request $request): Response
    {
        $this->denyIfForeign($brand);

        if (!$this->isCsrfTokenValid('brand_claim_email', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('brand_claim_new', ['id' => $brand->getId()]);
        }

        /** @var User $user */
        $user  = $this->getUser();
        $claim = $this->getOrCreateClaim($brand, $user);

        $code   = trim((string) $request->request->get('code', ''));
        $result = $this->claimService->checkEmailCode($claim, $code);

        if ($result !== 'ok') {
            $this->addFlash('error', match ($result) {
                'mismatch' => 'Неверный код.',
                'expired'  => 'Срок действия кода истёк — запросите новый.',
                'too_many' => 'Слишком много попыток. Запросите новый код.',
                default    => 'Сначала запросите код.',
            });
            return $this->redirectToRoute('brand_claim_new', ['id' => $brand->getId()]);
        }

        return $this->finishVerification($claim, BrandClaim::METHOD_EMAIL_CODE, 'email_code');
    }

    // ── Метод: VK-админ (подтверждение через VK ID) ───────────────────────────

    #[Route('/{id}/vk/start', name: 'vk_start', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function vkStart(Brand $brand, Request $request): Response
    {
        $this->denyIfForeign($brand);

        if (!$this->vkVerifier->isConfigured()) {
            $this->addFlash('error', 'Подтверждение через VK временно недоступно. Используйте другой способ.');
            return $this->redirectToRoute('brand_claim_new', ['id' => $brand->getId()]);
        }
        if ($this->claimService->brandVkGroup($brand) === null) {
            $this->addFlash('error', 'У бренда не указана группа VK.');
            return $this->redirectToRoute('brand_claim_new', ['id' => $brand->getId()]);
        }

        /** @var User $user */
        $user  = $this->getUser();
        $claim = $this->getOrCreateClaim($brand, $user);

        $verifier = $this->vkVerifier->generateCodeVerifier();
        $state    = bin2hex(random_bytes(16));
        $redirect = $this->generateUrl('brand_claim_vk_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);

        // state — анти-CSRF/replay; verifier — PKCE; всё транзиентно в сессии
        $request->getSession()->set('brand_claim_vk', [
            'claim_id' => $claim->getId(),
            'verifier' => $verifier,
            'state'    => $state,
            'redirect' => $redirect,
        ]);

        return $this->redirect($this->vkVerifier->buildAuthorizeUrl($redirect, $state, $verifier));
    }

    #[Route('/vk/callback', name: 'vk_callback', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function vkCallback(Request $request): Response
    {
        $session = $request->getSession();
        $stored  = $session->get('brand_claim_vk');
        $session->remove('brand_claim_vk');

        $code     = (string) $request->query->get('code', '');
        $state    = (string) $request->query->get('state', '');
        $deviceId = (string) $request->query->get('device_id', '');

        if (!is_array($stored) || $code === '' || !hash_equals((string) ($stored['state'] ?? ''), $state)) {
            $this->addFlash('error', 'Не удалось подтвердить через VK (неверный ответ). Попробуйте ещё раз.');
            return $this->redirectToRoute('brand_index');
        }

        $claim = $this->claimRepo->find($stored['claim_id'] ?? 0);
        if (!$claim || $claim->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        $brand = $claim->getBrand();

        $token    = $this->vkVerifier->exchangeCode($code, $deviceId, (string) $stored['verifier'], (string) $stored['redirect']);
        $groupRef = $this->claimService->brandVkGroup($brand);

        if (!$token || !$groupRef || !$this->vkVerifier->isAdminOfGroup($token, $groupRef)) {
            $this->addFlash('error', 'Не удалось подтвердить: вы не администратор группы VK этого бренда.');
            return $this->redirectToRoute('brand_claim_new', ['id' => $brand->getId()]);
        }

        return $this->finishVerification($claim, BrandClaim::METHOD_VK_ADMIN, 'vk_admin');
    }

    // ── Ручная заявка (комментарий + документы — проверка админом) ────────────

    #[Route('/{id}/manual', name: 'manual', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function manual(Brand $brand, Request $request): Response
    {
        $this->denyIfForeign($brand);

        if (!$this->isCsrfTokenValid('brand_claim_manual', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('brand_claim_new', ['id' => $brand->getId()]);
        }

        /** @var User $user */
        $user  = $this->getUser();
        $claim = $this->getOrCreateClaim($brand, $user);

        $claim->setComment(trim((string) $request->request->get('comment', '')) ?: null);
        $claim->setMethod(BrandClaim::METHOD_MANUAL);

        // Пассивная подсказка админу: совпал ли домен email
        $emailMatch = $this->checkEmailDomain($user->getEmail() ?? '', $brand);
        $claim->setEmailDomainMatch($emailMatch);
        if ($emailMatch) {
            $claim->setStatus(BrandClaim::STATUS_EMAIL_VERIFIED);
        }
        $this->em->flush();

        $this->notifyAdmin($claim);

        return $this->render('brand_claim/submitted.html.twig', [
            'brand'      => $brand,
            'claim'      => $claim,
            'emailMatch' => $emailMatch,
        ]);
    }

    // ── Статус заявки ─────────────────────────────────────────────────────────

    #[Route('/status/{id}', name: 'status', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function status(BrandClaim $claim): Response
    {
        if ($claim->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('brand_claim/status.html.twig', [
            'brand' => $claim->getBrand(),
            'claim' => $claim,
        ]);
    }

    // ── Admin: одобрить / отклонить ────────────────────────────────────────────

    #[Route('/admin/approve/{id}', name: 'approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function approve(BrandClaim $claim, Request $request): Response
    {
        if (!in_array($claim->getStatus(), [BrandClaim::STATUS_PENDING, BrandClaim::STATUS_EMAIL_VERIFIED], true)) {
            $this->addFlash('error', 'Заявка уже обработана');
            return $this->redirectToRoute('admin_brand_claims');
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $claim->setAdminNote(trim((string) $request->request->get('admin_note', '')) ?: null);
        $this->claimService->grantOwnership($claim, $admin instanceof User ? $admin : null, 'admin');

        $this->addFlash('success', sprintf(
            'Заявка одобрена. %s теперь владелец бренда «%s»',
            $claim->getUser()->getEmail(),
            $claim->getBrand()->getTitle(),
        ));

        return $this->redirectToRoute('admin_brand_claims');
    }

    #[Route('/admin/reject/{id}', name: 'reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reject(BrandClaim $claim, Request $request): Response
    {
        /** @var User $admin */
        $admin = $this->getUser();
        $claim->setStatus(BrandClaim::STATUS_REJECTED);
        $claim->setAdminNote(trim((string) $request->request->get('admin_note', '')) ?: null);
        $claim->setReviewedBy($admin instanceof User ? $admin : null);
        $claim->setReviewedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->notifyUser($claim, approved: false);

        $this->addFlash('success', 'Заявка отклонена');
        return $this->redirectToRoute('admin_brand_claims');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Иностранным брендам claim жёстко отключён (docs/foreign_brands_policy.md):
     * коммерческая платформа + «заберите страницу Nike» = претензионный риск.
     * 404 — не раскрываем механику; баннер/CTA в шаблоне тоже скрыты.
     */
    private function denyIfForeign(Brand $brand): void
    {
        if ($brand->isForeignOrigin()) {
            throw $this->createNotFoundException('Заявка на владение недоступна для этого бренда');
        }
    }

    private function getOrCreateClaim(Brand $brand, User $user): BrandClaim
    {
        $claim = $this->claimRepo->findPendingByBrandAndUser($brand, $user);
        if ($claim) {
            return $claim;
        }

        $claim = (new BrandClaim())->setBrand($brand)->setUser($user);
        $this->em->persist($claim);
        $this->em->flush();

        return $claim;
    }

    /**
     * Завершение self-serve верификации: либо авто-выдача доступа,
     * либо отправка на ручную проверку (если бренд уже занят / авто-выдача off).
     */
    private function finishVerification(BrandClaim $claim, string $method, string $via): Response
    {
        $brand = $claim->getBrand();
        /** @var User $user */
        $user = $claim->getUser();

        // Бренд уже принадлежит другому пользователю → только ручная проверка
        if ($this->claimService->brandHasOtherOwner($brand, $user)) {
            $claim->setMethod($method);
            $claim->setVerifiedVia($via);
            $claim->setStatus(BrandClaim::STATUS_PENDING);
            $claim->setAdminNote('Владение подтверждено, но у бренда уже есть владелец — требуется ручная проверка.');
            $this->em->flush();
            $this->notifyAdmin($claim);

            $this->addFlash('info', 'Владение подтверждено, но у бренда уже есть владелец. Заявка отправлена на проверку администратору.');
            return $this->render('brand_claim/submitted.html.twig', ['brand' => $brand, 'claim' => $claim, 'emailMatch' => false]);
        }

        if ($this->claimService->isAutoGrant($method)) {
            $this->claimService->grantOwnership($claim, null, $via);
            $this->addFlash('success', "Владение подтверждено! Вы стали владельцем бренда «{$brand->getTitle()}».");
            return $this->redirectToRoute('brand_dashboard');
        }

        // Авто-выдача отключена → verified, ждёт админа
        $claim->setMethod($method);
        $claim->setVerifiedVia($via);
        $claim->setStatus(BrandClaim::STATUS_EMAIL_VERIFIED);
        $this->em->flush();
        $this->notifyAdmin($claim);

        $this->addFlash('success', 'Владение подтверждено. Заявка отправлена администратору на финальное одобрение.');
        return $this->render('brand_claim/submitted.html.twig', ['brand' => $brand, 'claim' => $claim, 'emailMatch' => false]);
    }

    private function renderForm(Brand $brand, BrandClaim $claim): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $userDomain  = $this->extractDomain($user->getEmail() ?? '');
        $brandDomain = $this->extractDomain($brand->getEmail() ?? '');

        $now = new \DateTimeImmutable();
        $codePending = $claim->getMethod() === BrandClaim::METHOD_EMAIL_CODE
            && $claim->getCodeExpiresAt() !== null
            && $claim->getCodeExpiresAt() > $now;

        return $this->render('brand_claim/form.html.twig', [
            'brand'         => $brand,
            'claim'         => $claim,
            'methods'       => $this->claimService->availableMethods($brand),
            'codePending'   => $codePending,
            'brandEmailHint'=> $this->maskEmail($brand->getEmail()),
            'userDomain'    => $userDomain,
            'willAutoVerify'=> $userDomain && $brandDomain && $userDomain === $brandDomain,
        ]);
    }

    private function checkEmailDomain(string $userEmail, Brand $brand): bool
    {
        $userDomain  = $this->extractDomain($userEmail);
        $brandDomain = $this->extractDomain($brand->getEmail() ?? '');

        return $userDomain !== '' && $userDomain === $brandDomain;
    }

    private function extractDomain(string $email): string
    {
        $parts = explode('@', $email);
        return strtolower(trim($parts[1] ?? ''));
    }

    private function maskEmail(?string $email): ?string
    {
        if (!$email || !str_contains($email, '@')) {
            return null;
        }
        [$name, $domain] = explode('@', $email, 2);
        $masked = mb_substr($name, 0, 1) . str_repeat('•', max(1, mb_strlen($name) - 1));
        return $masked . '@' . $domain;
    }

    private function notifyAdmin(BrandClaim $claim): void
    {
        $brand = $claim->getBrand();
        $user  = $claim->getUser();
        $label = $claim->isEmailDomainMatch() ? '✅ домен совпадает' : '⚠️ требуется проверка';

        $this->notifier->dispatch(
            $user,
            Notification::TYPE_SYSTEM,
            "Новая заявка на бренд «{$brand->getTitle()}» — {$label}",
            "Пользователь {$user->getEmail()} подал заявку на владение брендом. Комментарий: " . ($claim->getComment() ?? '—'),
            ['brand_id' => $brand->getId(), 'claim_id' => $claim->getId()],
            'brand_claim_admin',
            ['claim' => $claim],
        );
        // dispatch только persist'ит in-app — коммитим
        $this->em->flush();

        // ⚠️ dispatch выше адресован ЗАЯВИТЕЛЮ (in-app след в его кабинете). Админ до 03.07.2026
        // не узнавал о заявках вообще (только заглянув в /admin). Шлём письмо на ADMIN_EMAIL
        // (soft-fail внутри send) + TG в админ-чат (мгновеннее, не зависит от почтового транспорта).
        if (trim((string) $this->adminEmail) !== '') {
            $this->emailNotifier->send(
                (string) $this->adminEmail,
                "Заявка на бренд «{$brand->getTitle()}» — {$label}",
                'brand_claim_admin',
                ['claim' => $claim],
            );
        }

        $this->adminNotifier->send(sprintf(
            "📝 <b>Новая заявка на бренд «%s»</b> — %s\nОт: %s\nКомментарий: %s",
            htmlspecialchars((string) $brand->getTitle(), ENT_QUOTES, 'UTF-8'),
            $label,
            htmlspecialchars((string) $user->getEmail(), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($claim->getComment() ?? '—', ENT_QUOTES, 'UTF-8'),
        ));
    }

    private function notifyUser(BrandClaim $claim, bool $approved): void
    {
        $brand = $claim->getBrand();
        $user  = $claim->getUser();

        $this->notifier->dispatch(
            $user,
            Notification::TYPE_SYSTEM,
            $approved
                ? "Заявка на бренд «{$brand->getTitle()}» одобрена!"
                : "Заявка на бренд «{$brand->getTitle()}» отклонена",
            $approved
                ? "Поздравляем! Вы стали владельцем бренда «{$brand->getTitle()}»."
                : ($claim->getAdminNote() ? "Причина: {$claim->getAdminNote()}" : 'К сожалению, ваша заявка отклонена.'),
            ['brand_id' => $brand->getId(), 'claim_id' => $claim->getId()],
            $approved ? 'brand_claim_approved' : 'brand_claim_rejected',
            ['claim' => $claim],
        );
        $this->em->flush();
    }
}
