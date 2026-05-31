<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Brand;
use App\Entity\BrandClaim;
use App\Entity\BrandUser;
use App\Entity\Notification;
use App\Entity\User;
use App\Notification\NotificationDispatcher;
use App\Repository\BrandClaimRepository;
use App\Repository\BrandUserRepository;
use App\Service\SubscriptionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/brand-claim', name: 'brand_claim_')]
class BrandClaimController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly BrandClaimRepository    $claimRepo,
        private readonly BrandUserRepository     $brandUserRepo,
        private readonly SubscriptionFactory     $subscriptionFactory,
        private readonly NotificationDispatcher  $notifier,
    ) {}

    // ── Форма заявки ─────────────────────────────────────────────────────────

    #[Route('/{id}', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Brand $brand, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Уже владелец/менеджер — в ЛК
        $existing = $this->brandUserRepo->findOneBy(['brand' => $brand, 'user' => $user]);
        if ($existing) {
            $this->addFlash('info', 'Вы уже являетесь участником команды этого бренда');
            return $this->redirectToRoute('brand_dashboard');
        }

        // Уже есть активная заявка
        $activeClaim = $this->claimRepo->findPendingByBrandAndUser($brand, $user);
        if ($activeClaim) {
            return $this->render('brand_claim/pending.html.twig', [
                'brand' => $brand,
                'claim' => $activeClaim,
            ]);
        }

        if ($request->isMethod('POST')) {
            $comment = trim((string) $request->request->get('comment', ''));

            $claim = new BrandClaim();
            $claim->setBrand($brand);
            $claim->setUser($user);
            $claim->setComment($comment ?: null);

            // Автоматическая верификация по домену email
            $emailMatch = $this->checkEmailDomain($user->getEmail() ?? '', $brand);
            $claim->setEmailDomainMatch($emailMatch);

            if ($emailMatch) {
                $claim->setStatus(BrandClaim::STATUS_EMAIL_VERIFIED);
            }

            $this->em->persist($claim);
            $this->em->flush();

            // Уведомление администратора
            $this->notifyAdmin($claim);

            return $this->render('brand_claim/submitted.html.twig', [
                'brand'       => $brand,
                'claim'       => $claim,
                'emailMatch'  => $emailMatch,
            ]);
        }

        // Подсказка по верификации домена
        $userDomain  = $this->extractDomain($user->getEmail() ?? '');
        $brandDomain = $this->extractDomain($brand->getEmail() ?? '');
        $willAutoVerify = $userDomain && $brandDomain && $userDomain === $brandDomain;

        return $this->render('brand_claim/form.html.twig', [
            'brand'          => $brand,
            'willAutoVerify' => $willAutoVerify,
            'userDomain'     => $userDomain,
        ]);
    }

    // ── Статус заявки ─────────────────────────────────────────────────────────

    #[Route('/status/{id}', name: 'status')]
    #[IsGranted('ROLE_USER')]
    public function status(BrandClaim $claim): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($claim->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('brand_claim/status.html.twig', [
            'brand' => $claim->getBrand(),
            'claim' => $claim,
        ]);
    }

    // ── Admin: одобрить ───────────────────────────────────────────────────────

    #[Route('/admin/approve/{id}', name: 'approve', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function approve(BrandClaim $claim, Request $request): Response
    {
        if (!$claim->isPending() && $claim->getStatus() !== BrandClaim::STATUS_EMAIL_VERIFIED) {
            $this->addFlash('error', 'Заявка уже обработана');
            return $this->redirectToRoute('admin_brand_claims');
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $note  = trim((string) $request->request->get('admin_note', ''));

        // Создаём BrandUser
        $brandUser = new BrandUser();
        $brandUser->setBrand($claim->getBrand());
        $brandUser->setUser($claim->getUser());
        $brandUser->setRole(BrandUser::ROLE_OWNER);
        $brandUser->setAcceptedAt(new \DateTimeImmutable());
        $this->em->persist($brandUser);

        // Назначаем роли
        $claimUser = $claim->getUser();
        $newRoles = $claimUser->getRoles();
        if (!in_array('ROLE_BRAND_MANAGER', $newRoles, true)) {
            $newRoles[] = 'ROLE_BRAND_MANAGER';
        }
        if (!in_array('ROLE_BRAND_OWNER', $newRoles, true)) {
            $newRoles[] = 'ROLE_BRAND_OWNER';
        }
        $claimUser->setRoles(array_values(array_unique($newRoles)));

        $this->subscriptionFactory->createFreeTrial($claim->getBrand());

        $claim->setStatus(BrandClaim::STATUS_APPROVED);
        $claim->setAdminNote($note ?: null);
        $claim->setReviewedBy($admin instanceof User ? $admin : null);
        $claim->setReviewedAt(new \DateTimeImmutable());

        $this->em->flush();

        // Email пользователю
        $this->notifyUser($claim, approved: true);

        $this->addFlash('success', sprintf(
            'Заявка одобрена. %s теперь владелец бренда «%s»',
            $claim->getUser()->getEmail(),
            $claim->getBrand()->getTitle()
        ));

        return $this->redirectToRoute('admin_brand_claims');
    }

    // ── Admin: отклонить ──────────────────────────────────────────────────────

    #[Route('/admin/reject/{id}', name: 'reject', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reject(BrandClaim $claim, Request $request): Response
    {
        /** @var User $admin */
        $admin = $this->getUser();
        $note  = trim((string) $request->request->get('admin_note', ''));

        $claim->setStatus(BrandClaim::STATUS_REJECTED);
        $claim->setAdminNote($note ?: null);
        $claim->setReviewedBy($admin instanceof User ? $admin : null);
        $claim->setReviewedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->notifyUser($claim, approved: false);

        $this->addFlash('success', 'Заявка отклонена');
        return $this->redirectToRoute('admin_brand_claims');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function checkEmailDomain(string $userEmail, Brand $brand): bool
    {
        $userDomain  = $this->extractDomain($userEmail);
        $brandDomain = $this->extractDomain($brand->getEmail() ?? '');

        if (!$userDomain || !$brandDomain) {
            return false;
        }

        return $userDomain === $brandDomain;
    }

    private function extractDomain(string $email): string
    {
        $parts = explode('@', $email);
        return strtolower(trim($parts[1] ?? ''));
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
                : ($claim->getAdminNote()
                    ? "Причина: {$claim->getAdminNote()}"
                    : 'К сожалению, ваша заявка отклонена.'),
            ['brand_id' => $brand->getId(), 'claim_id' => $claim->getId()],
            $approved ? 'brand_claim_approved' : 'brand_claim_rejected',
            ['claim' => $claim],
        );
    }
}
