<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Entity\FbiIngestion;
use App\Entity\Season;
use App\Repository\FbiIngestionRepository;
use App\Service\ClubDay;
use App\Service\SeasonResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
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
 * `latest` est `null` quand le club+saison n'a encore reçu aucun dépôt. `latest.depositedDate`
 * (`Y-m-d`, jour civil du club) date le titre « Matchs - (Dernier import FBI jj/mm/aaaa) ».
 */
#[AsController]
final class FbiIngestionFreshnessController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly FbiIngestionRepository $ingestionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubDay $clubDay,
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
        if (!$latest instanceof FbiIngestion) {
            return $this->json(['latest' => null]);
        }

        // Le jour civil du dépôt AU FUSEAU DU CLUB (titre du module) : `depositedAt` porte
        // l'offset du serveur, le front ne connaît pas celui du club.
        $depositedAt = $latest->getDepositedAt();
        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        $clubDeposit = $club instanceof Club ? $depositedAt->setTimezone($this->clubDay->timezoneFor($club)) : $depositedAt;

        return $this->json(['latest' => [
            'depositedAt' => $depositedAt->format(DateTimeImmutable::ATOM),
            'depositedDate' => $clubDeposit->format('Y-m-d'),
            'source' => $latest->getSource()->value,
            'created' => $latest->getCreated(),
            'updated' => $latest->getUpdated(),
            'unchanged' => $latest->getUnchanged(),
            'deviationsCount' => $latest->getDeviationsCount(),
        ]]);
    }
}
