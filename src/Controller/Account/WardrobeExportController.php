<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\User;
use App\Service\FamilyService;
use App\Service\Wardrobe\WardrobeExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/account/wardrobe/export', name: 'account_wardrobe_export_')]
class WardrobeExportController extends AbstractController
{
    public function __construct(
        private readonly FamilyService $familyService,
        private readonly WardrobeExportService $exporter,
    ) {}

    #[Route('/{format}', name: 'download', requirements: ['format' => 'json|csv'], methods: ['GET'])]
    public function download(string $format, Request $request): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $scope = $request->query->get('scope') === 'family' ? 'family' : 'member';

        if ($scope === 'family') {
            if (!$actor->isFamilyParent()) {
                throw $this->createAccessDeniedException('Экспортировать всю семью может только родитель');
            }
            $owners = $this->familyService->membersFor($actor);
        } else {
            $memberId = $request->query->has('member') ? $request->query->getInt('member') : null;
            $owners = [$this->familyService->resolveMember($actor, $memberId)];
        }

        $withArchive = $request->query->getBoolean('archive');
        $export = $this->exporter->export($owners, $withArchive);
        $filename = sprintf('wearbase-wardrobe-%s.%s', (new \DateTimeImmutable())->format('Y-m-d'), $format);

        if ($format === 'csv') {
            return new Response($this->exporter->toCsv($export), Response::HTTP_OK, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            ]);
        }

        return new Response(
            json_encode($export, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            ],
        );
    }
}
