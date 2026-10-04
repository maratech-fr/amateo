<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MatchPlacementRun;
use App\Repository\MatchPlacementRunRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/fixtures/placement-run — le DERNIER run de placement du club+saison courants.
 * À l'ouverture de l'écran de matchs : y a-t-il un placement en cours, et quel est le
 * résultat d'un run déjà terminé ? Lecture seule, ouverte à tout membre (le GESTE de
 * placement, lui, est management-only). Scopé tenant+saison par les filtres Doctrine + RLS :
 * un autre club ne voit jamais ce run — il reçoit `{run: null}`, jamais la donnée d'autrui.
 */
#[AsController]
final class PlacementRunController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly MatchPlacementRunRepository $runRepository,
    ) {}

    // priority > 0: the static path must win over API Platform's /api/fixtures/{id}.
    #[Route('/api/fixtures/placement-run', name: 'api_fixtures_placement_run', methods: ['GET'], priority: 10)]
    public function __invoke(): JsonResponse
    {
        if (null === $this->resolveCurrentClubId($this->requestStack)) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $run = $this->runRepository->latestForCurrentScope();

        return $this->json(['run' => $run instanceof MatchPlacementRun ? $this->serialize($run) : null]);
    }

    /** @return array<string, mixed> */
    private function serialize(MatchPlacementRun $run): array
    {
        return [
            'id' => $run->getId(),
            'status' => $run->getStatus()->value,
            'createdAt' => $run->getCreatedAt()->format(\DATE_ATOM),
            'startedAt' => $run->getStartedAt()?->format(\DATE_ATOM),
            'finishedAt' => $run->getFinishedAt()?->format(\DATE_ATOM),
            'result' => $run->getResultData(),
        ];
    }
}
