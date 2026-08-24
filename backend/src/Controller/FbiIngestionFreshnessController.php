<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FbiIngestion;
use App\Entity\Season;
use App\Repository\FbiIngestionRepository;
use App\Service\SeasonResolver;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/fbi-ingestions/latest — la FRAÎCHEUR du module matchs (RMM-4) : le
 * dernier dépôt du xlsx FBI du club+saison, pour servir « Dernier dépôt FBI : il
 * y a N jours » (PR-2 front). Lecture légère, patron des lectures du module
 * (LeagueMatchWindowsController) : ouverte au Membre (aucune garde management —
 * n'importe quel membre ouvre le module), tenant+saison résolus côté serveur.
 *
 * `latest` est `null` quand le club+saison n'a encore reçu aucun dépôt.
 */
#[AsController]
final class FbiIngestionFreshnessController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly FbiIngestionRepository $ingestionRepository,
    ) {}

    #[Route('/api/fbi-ingestions/latest', name: 'api_fbi_ingestions_latest', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $season = $this->seasonResolver->selectedOrCurrent($this->requestStack->getCurrentRequest(), $clubId);
        if (!$season instanceof Season) {
            return $this->json(['error' => 'No season in context.'], Response::HTTP_BAD_REQUEST);
        }

        // The tenant + season Doctrine filters already scope the query to the
        // current club+season; the repository only isolates the FBI channel.
        $latest = $this->ingestionRepository->latestXlsx();

        return $this->json(['latest' => $latest instanceof FbiIngestion ? [
            'depositedAt' => $latest->getDepositedAt()->format(DateTimeImmutable::ATOM),
            'source' => $latest->getSource()->value,
            'created' => $latest->getCreated(),
            'updated' => $latest->getUpdated(),
            'unchanged' => $latest->getUnchanged(),
            'deviationsCount' => $latest->getDeviationsCount(),
        ] : null]);
    }
}
