<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\User;
use App\Entity\WardrobeWearEvent;
use App\Repository\WardrobeConsentRepository;
use App\Repository\WardrobeItemRepository;
use App\Repository\WardrobeWearEventRepository;
use App\Service\FamilyService;
use App\Service\Wardrobe\WardrobeConsentService;
use App\Service\Wardrobe\WardrobeImageSanitizer;
use App\Service\Wardrobe\WardrobeWearRecognitionService;
use App\Service\Wardrobe\WardrobeWearService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/account/wardrobe/wear', name: 'account_wardrobe_wear_')]
final class WardrobeWearController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        FamilyService $families,
        WardrobeItemRepository $items,
        WardrobeWearEventRepository $events,
        WardrobeWearService $wear,
        WardrobeWearRecognitionService $recognition,
        WardrobeConsentRepository $consents,
        WardrobeConsentService $consentService,
        WardrobeImageSanitizer $sanitizer,
        ValidatorInterface $validator,
        RateLimiterFactory $wardrobeAiLimiter,
    ): Response {
        /** @var User $actor */
        $actor = $this->getUser();
        $subject = $families->resolveMember($actor, $request->query->getInt('member') ?: null);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('wardrobe_wear_new_'.$subject->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Недействительный токен');
            }
            $rawDay = $request->request->getString('wornOn');
            $day = $rawDay === '' ? new \DateTimeImmutable('today') : \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDay);
            $dateErrors = $rawDay === '' ? false : \DateTimeImmutable::getLastErrors();
            if (!$day || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
                $this->addFlash('error', 'Укажите корректную дату');
                return $this->redirectToRoute('account_wardrobe_wear_index', $this->memberParams($actor, $subject));
            }
            if ($day > new \DateTimeImmutable('today')) {
                $this->addFlash('error', 'Дата носки не может быть в будущем');
                return $this->redirectToRoute('account_wardrobe_wear_index', $this->memberParams($actor, $subject));
            }

            $photo = $request->files->get('photo');
            $candidates = [];
            $sanitized = null;
            if ($photo instanceof UploadedFile) {
                $errors = $validator->validate($photo, new Image([
                    'maxSize' => '10M',
                    'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
                    'maxWidth' => 5000,
                    'maxHeight' => 5000,
                ]));
                if ($errors->count() > 0) {
                    $this->addFlash('error', (string) $errors->get(0)->getMessage());
                    return $this->redirectToRoute('account_wardrobe_wear_index', $this->memberParams($actor, $subject));
                }
                $consent = $consents->findForSubject($subject);
                if (!$consent?->isPhotoProcessingGranted()) {
                    if (!$request->request->getBoolean('photoConsent')) {
                        $this->addFlash('error', 'Подтвердите приватную обработку фото');
                        return $this->redirectToRoute('account_wardrobe_wear_index', $this->memberParams($actor, $subject));
                    }
                    $consentService->grantPhotoProcessing($actor, $subject);
                }
                if (!$wardrobeAiLimiter->create((string) $actor->getId())->consume()->isAccepted()) {
                    $this->addFlash('error', 'Лимит AI-распознаваний на сегодня');
                    return $this->redirectToRoute('account_wardrobe_wear_index', $this->memberParams($actor, $subject));
                }
                try {
                    $sanitized = $sanitizer->sanitize($photo);
                } catch (\InvalidArgumentException $exception) {
                    $this->addFlash('error', $exception->getMessage());
                    return $this->redirectToRoute('account_wardrobe_wear_index', $this->memberParams($actor, $subject));
                }
                $candidates = $recognition->candidates($sanitized->getPathname(), $subject);
            }

            $event = $wear->createReview($actor, $subject, $candidates, $day, $sanitized);
            return $this->redirectToRoute('account_wardrobe_wear_review', ['id' => $event->getId()] + $this->memberParams($actor, $subject));
        }

        return $this->privateResponse($this->render('account/wardrobe/wear/index.html.twig', [
            'currentMember' => $subject,
            'items' => $items->findActiveForUser($subject),
            'events' => $events->findRecentConfirmed($subject),
            'statistics' => $wear->statistics($subject),
            'hasPhotoConsent' => $consents->findForSubject($subject)?->isPhotoProcessingGranted() ?? false,
            'familyCaptureUrl' => $this->generateUrl('account_wardrobe_wear_index', $this->memberParams($actor, $subject)),
            'familyActiveSection' => 'capture',
        ]));
    }

    #[Route('/{id}', name: 'review', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function review(int $id, Request $request, FamilyService $families, WardrobeWearEventRepository $events, WardrobeItemRepository $items): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $event = $events->find($id);
        if (!$event instanceof WardrobeWearEvent || !$families->canManage($actor, $event->getProfileSubject())) {
            throw $this->createNotFoundException();
        }
        return $this->privateResponse($this->render('account/wardrobe/wear/review.html.twig', [
            'event' => $event,
            'items' => $items->findActiveForUser($event->getProfileSubject()),
            'currentMember' => $event->getProfileSubject(),
            'familyCaptureUrl' => $this->generateUrl('account_wardrobe_wear_index', $this->memberParams($actor, $event->getProfileSubject())),
            'familyActiveSection' => 'capture',
        ]));
    }

    #[Route('/{id}/confirm', name: 'confirm', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function confirm(int $id, Request $request, FamilyService $families, WardrobeWearEventRepository $events, WardrobeWearService $wear): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $event = $events->find($id);
        if (!$event instanceof WardrobeWearEvent || !$families->canManage($actor, $event->getProfileSubject())) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('wardrobe_wear_confirm_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Недействительный токен');
        }
        try {
            $wear->confirm(
                $actor,
                $event,
                array_map('intval', (array) $request->request->all('items')),
                $request->request->getString('type'),
                $request->request->getString('occasion'),
                $request->request->getString('comment'),
            );
            $this->addFlash('success', $event->isConfirmedWorn() ? 'Носка учтена' : 'Событие сохранено без увеличения носок');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
            return $this->redirectToRoute('account_wardrobe_wear_review', ['id' => $id] + $this->memberParams($actor, $event->getProfileSubject()));
        }
        return $this->redirectToRoute('account_wardrobe_wear_index', $this->memberParams($actor, $event->getProfileSubject()));
    }

    #[Route('/{id}/feedback', name: 'feedback', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function feedback(int $id, Request $request, FamilyService $families, WardrobeWearEventRepository $events, WardrobeWearService $wear): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $event = $events->find($id);
        if (!$event instanceof WardrobeWearEvent || !$families->canManage($actor, $event->getProfileSubject())) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('wardrobe_wear_feedback_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Недействительный токен');
        }
        try {
            $repeat = $request->request->has('repeat') ? $request->request->getBoolean('repeat') : null;
            $wear->addFeedback($actor, $event, $request->request->getString('comfort'), $repeat, $request->request->getString('comment'));
            $this->addFlash('success', 'Стилист запомнит эту обратную связь');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('account_wardrobe_wear_index', $this->memberParams($actor, $event->getProfileSubject()));
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request, FamilyService $families, WardrobeWearEventRepository $events, WardrobeWearService $wear): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $event = $events->find($id);
        if (!$event instanceof WardrobeWearEvent || !$families->canManage($actor, $event->getProfileSubject())) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('wardrobe_wear_delete_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Недействительный токен');
        }
        $subject = $event->getProfileSubject();
        $wear->delete($actor, $event);
        $this->addFlash('success', 'Событие удалено, статистика пересчитана');
        return $this->redirectToRoute('account_wardrobe_wear_index', $this->memberParams($actor, $subject));
    }

    /** @return array{member?:int} */
    private function memberParams(User $actor, User $subject): array
    {
        return $actor->getId() === $subject->getId() ? [] : ['member' => (int) $subject->getId()];
    }

    private function privateResponse(Response $response): Response
    {
        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-store');
        return $response;
    }
}
