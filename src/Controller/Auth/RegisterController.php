<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\Brand;
use App\Entity\BrandModeration;
use App\Entity\BrandUser;
use App\Entity\User;
use App\Form\Auth\BrandRegistrationFormType;
use App\Form\Auth\RegistrationFormType;
use App\Notification\AdminNotifier;
use App\Notification\EmailNotifier;
use App\Service\Look\LookShareReferralService;
use App\Service\SubscriptionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
            $lookShareReferrals->recordFromSession($request, $user);
            $lookShareTarget = $this->validatedLookShareTarget($request);
            if ($lookShareTarget !== null) {
                $request->getSession()->set('_security.main.target_path', $lookShareTarget);
            }
            return $userAuthenticator->authenticateUser(
                $user,
                $authenticator,
                $request,
            );
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
}
