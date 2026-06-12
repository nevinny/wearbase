<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;

/**
 * Режим просмотра от имени пользователя.
 *
 * Два разных firewall (admin / main) означают разные User-сущности,
 * поэтому Symfony switch_user не подходит. Используем подписанный токен:
 *
 * 1. Администратор нажимает "Войти как X" в /admin
 * 2. ImpersonateController::generate() создаёт HMAC-токен (TTL 60 сек)
 *    и редиректит на /impersonate/accept?token=...&uid=...
 * 3. ImpersonateController::accept() верифицирует подпись и TTL,
 *    логинит App\Entity\User через loginUser(), пишет флаг в сессию
 * 4. В base.html.twig показывается баннер "Вы смотрите как X"
 * 5. /impersonate/exit очищает флаг и возвращает на /admin
 */
class ImpersonateController extends AbstractController
{
    private const TOKEN_TTL = 60; // секунд

    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly UserAuthenticatorInterface  $userAuthenticator,
        #[Autowire(service: 'security.authenticator.form_login.main')]
        private readonly FormLoginAuthenticator      $authenticator,
        #[Autowire('%kernel.secret%')]
        private readonly string                      $secret,
    ) {}

    // ── Генерация токена (вызывается из /admin контекста) ────────────────────

    #[Route('/admin/impersonate/{id}', name: 'admin_impersonate', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function generate(User $user, UrlGeneratorInterface $urlGenerator): RedirectResponse
    {
        $ts    = time();
        $token = $this->sign((string) $user->getId(), $ts);

        $url = $urlGenerator->generate('impersonate_accept', [
            'uid'   => $user->getId(),
            'ts'    => $ts,
            'token' => $token,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return new RedirectResponse($url);
    }

    // ── Приём токена (уже в main firewall) ───────────────────────────────────

    #[Route('/impersonate/accept', name: 'impersonate_accept')]
    public function accept(Request $request): Response
    {
        $uid   = (int) $request->query->get('uid');
        $ts    = (int) $request->query->get('ts');
        $token = (string) $request->query->get('token');

        // Проверяем TTL
        if (time() - $ts > self::TOKEN_TTL) {
            throw $this->createAccessDeniedException('Ссылка устарела. Сгенерируйте новую.');
        }

        // Проверяем подпись
        if (!hash_equals($this->sign((string) $uid, $ts), $token)) {
            throw $this->createAccessDeniedException('Неверная подпись токена.');
        }

        $user = $this->em->find(User::class, $uid);
        if (!$user) {
            throw $this->createNotFoundException('Пользователь не найден');
        }

        // Логинимся как этот пользователь
        $response = $this->userAuthenticator->authenticateUser(
            $user,
            $this->authenticator,
            $request,
        );

        // Пишем флаг в сессию — для баннера
        $request->getSession()->set('_impersonating', [
            'user_id' => $user->getId(),
            'email'   => $user->getEmail(),
        ]);

        return $response ?? $this->redirectToRoute('account_dashboard');
    }

    // ── Выход из режима просмотра ─────────────────────────────────────────────

    #[Route('/impersonate/exit', name: 'impersonate_exit')]
    public function exit(Request $request): RedirectResponse
    {
        $request->getSession()->remove('_impersonating');

        // Разлогиниваем из main firewall и отправляем в admin
        $request->getSession()->invalidate();

        return $this->redirect('/admin');
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function sign(string $uid, int $ts): string
    {
        return hash_hmac('sha256', $uid . ':' . $ts, $this->secret);
    }
}
