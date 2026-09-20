<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\Brand;
use App\Entity\BrandModeration;
use App\Entity\BrandUser;
use App\Entity\User;
use App\EventListener\SignupAttributionListener;
use App\Form\Auth\BrandRegistrationFormType;
use App\Form\Auth\RegistrationFormType;
use App\Notification\AdminNotifier;
use App\Notification\EmailNotifier;
use App\Service\Look\LookShareReferralService;
use App\Service\Referral\ReferralRewardService;
use App\Service\SubscriptionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;
use Symfony\Component\String\Slugger\SluggerInterface;

class RegisterController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
        UserAuthenticatorInterface $userAuthenticator,
        SluggerInterface $slugger,
        #[Autowire(service: 'security.authenticator.form_login.main')]
        FormLoginAuthenticator $authenticator,
        EmailNotifier $emailNotifier,
        SubscriptionFactory $subscriptionFactory,
        AdminNotifier $adminNotifier,
        LookShareReferralService $lookShareReferrals,
        ReferralRewardService $referralRewards,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute(
                $this->isGranted('ROLE_BRAND_MANAGER') ? 'brand_dashboard' : 'account_dashboard'
            );
        }

        $isBrand = (bool) $request->query->get('brand');

        $user = new User();
        $form = $this->createForm(
            $isBrand ? BrandRegistrationFormType::class : RegistrationFormType::class,
            $user
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword(
                $passwordHasher->hashPassword($user, $form->get('plainPassword')->getData())
            );

            if ($isBrand) {
                // Brand owner registration
                $user->setRoles(['ROLE_BRAND_MANAGER']);
                $em->persist($user);

                $brandTitle = trim((string) $form->get('brandTitle')->getData());
                $brand = new Brand();
                $brand->setTitle($brandTitle);
                $brand->setSlug($this->generateUniqueSlug($slugger, $em, $brandTitle));
                // Премодерация: карточка НЕ публикуется по факту регистрации. Дефолт трейта Status —
                // Active, то есть раньше бренд с одним лишь названием мгновенно попадал в каталог и
                // sitemap, минуя ниша-гейт и origin-гейт (docs/foreign_brands_policy.md). Владелец
                // сразу работает в ЛК, а публикацию открывает админ (publish_pending → app:publish-tick).
                $brand->setStatus(Statuses::New);
                $em->persist($brand);

                $brandUser = new BrandUser();
                $brandUser->setUser($user);
                $brandUser->setBrand($brand);
                $brandUser->setRole(BrandUser::ROLE_OWNER);
                $brandUser->setAcceptedAt(new \DateTimeImmutable());
                $em->persist($brandUser);

                $subscriptionFactory->createFreeTrial($brand);

                // Ставим в очередь авто-премодерации (app:brand:moderate-tick разберёт на Mac).
                $moderation = new BrandModeration();
                $moderation->setBrand($brand);
                $moderation->setSource(BrandModeration::SOURCE_SELF_REGISTER);
                $em->persist($moderation);

                // Карточка ждёт модерации — владелец об этом видит баннер в ЛК, а мы узнаём в TG.
                $adminNotifier->send(sprintf(
                    "\xF0\x9F\x86\x95 <b>Самостоятельная регистрация бренда</b>\nБренд: %s\nВладелец: %s\nСтатус: на модерации (не опубликован)",
                    htmlspecialchars($brandTitle, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars((string) $user->getEmail(), ENT_QUOTES, 'UTF-8'),
                ));
            } else {
                // Regular customer registration
                $user->setRoles(['ROLE_CUSTOMER']);
                $em->persist($user);
            }

            $this->applySignupAttribution($user, $request);

            // Generate email verification token
            $token = bin2hex(random_bytes(32));
            $user->setEmailVerificationToken($token);

            $em->flush();

            $emailNotifier->send(
                $user,
                'Подтвердите email — WEARBASE',
                'verify_email',
                ['token' => $token],
            );


            // Referral-хук «Поделиться луком» (спец §7): одно событие атрибуции после
            // успешной регистрации; возврат на лук — через target_path сессии (LoginSuccessHandler).
            $referralEvent = $lookShareReferrals->recordFromSession($request, $user);
            // Welcome-награда приглашённому (решение PO №2): +10/день×30д сразу при регистрации.
            if ($referralEvent !== null) {
                $referralRewards->grantOnWelcome($referralEvent);
            }
            $lookShareTarget = $this->validatedLookShareTarget($request);
            if ($lookShareTarget !== null) {
                $request->getSession()->set('_security.main.target_path', $lookShareTarget);
            }
            $response = $userAuthenticator->authenticateUser(
                $user,
                $authenticator,
                $request,
            );

            // Цель «регистрация» для Метрики отслеживается по посещению URL — дописываем
            // маркер в Location редиректа (не трогая уже возможный target_path из look-share).
            if ($response instanceof RedirectResponse) {
                $response->setTargetUrl(
                    $this->appendSignupQueryParam($response->getTargetUrl(), $isBrand ? 'brand' : 'customer')
                );
            }

            return $response;
        }

        return $this->render(
            $isBrand ? 'auth/register_brand.html.twig' : 'auth/register.html.twig',
            [
                'form' => $form,
                'isBrand' => $isBrand,
                // Скрытые поля CTA лендинга: переживают POST при ошибках валидации формы.
                'look_share_ref' => $this->lookShareParam($request),
                'look_share_target' => $this->validatedLookShareTarget($request),
            ]
        );
    }

    /** ?ref= / скрытое поле ref: сырая строка для проброса через форму. */
    private function lookShareParam(Request $request): string
    {
        return (string) ($request->request->get('ref') ?? $request->query->get('ref') ?? '');
    }

    /**
     * CTA-параметр target: принимаем только абсолютный путь на гостевую страницу лука
     * (анти-open-redirect, паттерн LoginSuccessHandler).
     */
    private function validatedLookShareTarget(Request $request): ?string
    {
        $target = (string) ($request->request->get('target') ?? $request->query->get('target') ?? '');

        return preg_match('#^/l/[0-9a-f]{64}$#', $target) === 1 ? $target : null;
    }

    private function generateUniqueSlug(SluggerInterface $slugger, EntityManagerInterface $em, string $title): string
    {
        $base = strtolower((string) $slugger->slug($title));
        $slug = $base;
        $i    = 1;

        while ($em->getRepository(Brand::class)->findOneBy(['slug' => $slug])) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    private function appendSignupQueryParam(string $url, string $kind): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . 'signup=' . $kind;
    }

    /**
     * Заполняет signup_* поля User из куки wb_src (SignupAttributionListener) —
     * первое касание визитёра до регистрации (docs/registration_sources_2026_09.md).
     * Нет куки (заблокирована/устарела/прямой заход без запроса) — считаем прямым заходом.
     */
    private function applySignupAttribution(User $user, Request $request): void
    {
        $raw = $request->cookies->get(SignupAttributionListener::COOKIE_NAME);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($data)) {
            $user->setSignupSource('direct');
            $user->setSignupFirstSeenAt(new \DateTimeImmutable());
            return;
        }

        $utm = array_filter([
            'utm_source'   => $data['utm_source'] ?? null,
            'utm_medium'   => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
        ]);

        $user->setSignupSource($this->classifySignupSource($data));
        $user->setSignupUtm($utm === [] ? null : mb_substr((string) json_encode($utm, JSON_UNESCAPED_UNICODE), 0, 255));
        $user->setSignupReferrer(isset($data['ref']) && is_string($data['ref']) ? mb_substr($data['ref'], 0, 255) : null);
        $user->setSignupLanding(isset($data['lp']) && is_string($data['lp']) ? mb_substr($data['lp'], 0, 255) : null);

        $firstSeen = isset($data['ts']) && is_string($data['ts'])
            ? \DateTimeImmutable::createFromFormat(DATE_ATOM, $data['ts'])
            : false;
        $user->setSignupFirstSeenAt($firstSeen instanceof \DateTimeImmutable ? $firstSeen : new \DateTimeImmutable());

        $ymUid = $request->cookies->get('_ym_uid');
        $user->setSignupYmUid(is_string($ymUid) ? mb_substr($ymUid, 0, 32) : null);
    }

    /**
     * Классификатор канала из сырых полей куки wb_src. Раздельно от applySignupAttribution,
     * чтобы позже можно было переклассифицировать существующих пользователей по тем же
     * сырым signup_referrer/signup_utm без повторного визита.
     */
    private function classifySignupSource(array $data): string
    {
        $utmSource = trim((string) ($data['utm_source'] ?? ''));
        if ($utmSource !== '') {
            return mb_substr('utm:' . $utmSource, 0, 50);
        }

        $ref = isset($data['ref']) && is_string($data['ref']) ? $data['ref'] : '';
        $host = strtolower(explode('/', $ref, 2)[0]);
        $hasYsclid = !empty($data['ysclid']);

        if ($host === '' && !$hasYsclid) {
            return 'direct';
        }

        if ($this->hostIsOrSubdomainOf($host, 'alice.yandex.ru')) {
            return 'alice';
        }
        if ($hasYsclid || $this->hostIsOrSubdomainOf($host, 'ya.ru') || $this->hostIsBrandDomain($host, 'yandex')) {
            return 'yandex_organic';
        }
        if ($this->hostIsBrandDomain($host, 'google')) {
            return 'google_organic';
        }
        if ($this->hostIsOrSubdomainOf($host, 'chatgpt.com')) {
            return 'chatgpt';
        }
        if ($this->hostIsOrSubdomainOf($host, 'perplexity.ai')) {
            return 'perplexity';
        }
        if ($this->hostIsOrSubdomainOf($host, 'dzen.ru')) {
            return 'dzen';
        }
        if ($this->hostIsOrSubdomainOf($host, 't.me')) {
            return 'telegram';
        }
        if ($this->hostIsOrSubdomainOf($host, 'vk.com') || $this->hostIsOrSubdomainOf($host, 'instagram.com')) {
            return 'social';
        }

        return 'referral';
    }

    private function hostIsOrSubdomainOf(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, '.' . $domain);
    }

    /** yandex./google. — несколько TLD (yandex.ru, yandex.com, google.ru, google.com…). */
    private function hostIsBrandDomain(string $host, string $brand): bool
    {
        return (bool) preg_match('/(^|\.)' . preg_quote($brand, '/') . '\.[a-z]+$/i', $host);
    }
}
