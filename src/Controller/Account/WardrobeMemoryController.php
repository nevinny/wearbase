<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\User;
use App\Repository\WardrobeConsentRepository;
use App\Service\FamilyService;
use App\Service\Wardrobe\WardrobeMemoryFactService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/account/wardrobe/memory', name: 'account_wardrobe_memory_')]
final class WardrobeMemoryController extends AbstractController
{
    public function __construct(
        private readonly FamilyService $families,
        private readonly WardrobeMemoryFactService $memory,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, WardrobeConsentRepository $consents): Response
    {
        [$actor, $subject] = $this->scope($request);
        return $this->render('account/wardrobe/memory.html.twig', [
            'currentMember' => $subject,
            'facts' => $this->memory->list($actor, $subject),
            'personalizationGranted' => $consents->isPersonalizationGranted($subject),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function edit(int $id, Request $request): Response
    {
        [$actor, $subject] = $this->scope($request);
        $this->csrf($request, 'wardrobe_memory_'.$id);
        try {
            $this->memory->edit($actor, $subject, $id, (string) $request->request->get('fact'));
        } catch (\OutOfBoundsException) {
            throw $this->createNotFoundException();
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirect($this->indexUrl($subject, $actor));
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        [$actor, $subject] = $this->scope($request);
        $this->csrf($request, 'wardrobe_memory_'.$id);
        try {
            $this->memory->delete($actor, $subject, $id);
        } catch (\OutOfBoundsException) {
            throw $this->createNotFoundException();
        }
        return $this->redirect($this->indexUrl($subject, $actor));
    }

    #[Route('/delete-all', name: 'delete_all', methods: ['POST'])]
    public function deleteAll(Request $request): Response
    {
        [$actor, $subject] = $this->scope($request);
        $this->csrf($request, 'wardrobe_memory_delete_all');
        $this->memory->deleteAll($actor, $subject);
        return $this->redirect($this->indexUrl($subject, $actor));
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        [$actor, $subject] = $this->scope($request);
        $response = $this->json([
            'format' => 'wearbase.wardrobe-memory',
            'version' => 1,
            'profile_subject_id' => $subject->getId(),
            'facts' => $this->memory->export($actor, $subject),
        ], headers: [
            'Content-Disposition' => 'attachment; filename="wearbase-memory.json"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        return $response;
    }

    /** @return array{User,User} */
    private function scope(Request $request): array
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $subject = $this->families->resolveMember($actor, $request->query->has('member') ? $request->query->getInt('member') : null);
        return [$actor, $subject];
    }

    private function csrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Недействительный токен');
        }
    }

    private function indexUrl(User $subject, User $actor): string
    {
        return $this->generateUrl('account_wardrobe_memory_index', $subject->getId() === $actor->getId() ? [] : ['member' => $subject->getId()]);
    }
}
