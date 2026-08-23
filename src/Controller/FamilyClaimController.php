<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\FamilyInviteRepository;
use App\Repository\UserRepository;
use App\Service\FamilyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Публичные страницы семейного гардероба:
 * - /family/claim/{token} — «ребёнок дорос»: managed-аккаунт получает свои email+пароль;
 * - /family/invite/{token} — акцепт приглашения в семью (для юзеров со своей почтой).
 */
class FamilyClaimController extends AbstractController
{
    #[Route('/family/claim/{token}', name: 'family_claim', methods: ['GET', 'POST'])]
    public function claim(
        string $token,
        Request $request,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
    ): Response {
        $member = $userRepo->findOneBy(['familyClaimToken' => $token]);
        if ($member === null) {
            throw $this->createNotFoundException();
        }

        $form = $this->createFormBuilder()
            ->add('email', EmailType::class, [
                'label'       => 'Email',
                'constraints' => [
                    new NotBlank(['message' => 'Введите email']),
                    new Email(['message' => 'Некорректный email']),
                    new Regex([
                        'pattern' => '/@' . preg_quote(User::MANAGED_EMAIL_DOMAIN, '/') . '$/i',
                        'match'   => false,
                        'message' => 'Этот email нельзя использовать',
                    ]),
                ],
            ])
            ->add('password', PasswordType::class, [
                'label'       => 'Пароль',
                'attr'        => ['autocomplete' => 'new-password'],
                'constraints' => [
                    new NotBlank(['message' => 'Введите пароль']),
                    new Length(['min' => 8, 'minMessage' => 'Пароль должен быть не менее {{ limit }} символов']),
                ],
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data  = $form->getData();
            $email = $data['email'];

            if ($userRepo->findOneBy(['email' => $email]) !== null) {
                $form->get('email')->addError(new FormError('Этот email уже зарегистрирован'));
            } else {
                $member->setEmail($email);
                $member->setPassword($passwordHasher->hashPassword($member, $data['password']));
                $member->setClaimedAt(new \DateTimeImmutable());
                $member->setFamilyClaimToken(null);
                $em->flush();

                $this->addFlash('success', 'Аккаунт активирован — войдите с новым email и паролем');
                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('family/claim.html.twig', [
            'form'       => $form,
            'memberName' => $member->getFirstName() ?? $member->getFullName(),
        ]);
    }

    #[Route('/family/invite/{token}', name: 'family_invite_accept', methods: ['GET', 'POST'])]
    public function inviteAccept(
        string $token,
        Request $request,
        FamilyInviteRepository $inviteRepo,
        FamilyService $familyService,
    ): Response {
        $invite = $inviteRepo->findOneBy(['token' => $token]);
        if ($invite === null) {
            throw $this->createNotFoundException();
        }

        /** @var ?User $user */
        $user = $this->getUser();

        $unavailable = !$invite->isUsable();

        if ($request->isMethod('POST')) {
            if ($unavailable) {
                $response = $this->render('family/invite_accept.html.twig', [
                    'invite' => $invite,
                    'inviterName' => $invite->getFamily()->getOwner()->getFullName(),
                    'unavailable' => true,
                ], new Response(status: Response::HTTP_GONE));

                return $this->secureInviteResponse($response);
            }
            if ($user === null) {
                $this->addFlash('error', 'Войдите, чтобы принять приглашение');
                return $this->redirectToRoute('app_login');
            }
            if (!$this->isCsrfTokenValid('family_invite_accept', $request->request->get('_token'))) {
                $this->addFlash('error', 'Недействительный токен');
                return $this->redirectToRoute('family_invite_accept', ['token' => $token]);
            }

            try {
                $familyService->acceptInvite($user, $invite);
                $this->addFlash('success', 'Вы присоединились к семье');
                if ($invite->getRole() === User::FAMILY_ROLE_CHILD) {
                    return $this->redirectToRoute('account_family_profile');
                }
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('account_family_index');
        }

        if ($user === null) {
            // Стандартный механизм target_path: после логина LoginSuccessHandler вернёт сюда же
            $request->getSession()->set('_security.main.target_path', $request->getUri());
        }

        $response = $this->render('family/invite_accept.html.twig', [
            'invite'      => $invite,
            'inviterName' => $invite->getFamily()->getOwner()->getFullName(),
            'unavailable' => $unavailable,
        ]);

        if ($unavailable) {
            $response->setStatusCode(Response::HTTP_GONE);
        }
        return $this->secureInviteResponse($response);
    }

    private function secureInviteResponse(Response $response): Response
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
