<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\Brand;
use App\Entity\BrandUser;
use App\Entity\User;
use App\Form\Auth\BrandRegistrationFormType;
use App\Form\Auth\RegistrationFormType;
use App\Notification\EmailNotifier;
use App\Service\SubscriptionFactory;
use Doctrine\ORM\EntityManagerInterface;
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
                $em->persist($brand);

                $brandUser = new BrandUser();
                $brandUser->setUser($user);
                $brandUser->setBrand($brand);
                $brandUser->setRole(BrandUser::ROLE_OWNER);
                $brandUser->setAcceptedAt(new \DateTimeImmutable());
                $em->persist($brandUser);

                $subscriptionFactory->createFreeTrial($brand);
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

            return $userAuthenticator->authenticateUser(
                $user,
                $authenticator,
                $request,
            );
        }

        return $this->render(
            $isBrand ? 'auth/register_brand.html.twig' : 'auth/register.html.twig',
            ['form' => $form, 'isBrand' => $isBrand]
        );
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
