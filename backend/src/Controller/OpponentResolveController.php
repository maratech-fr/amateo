<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Season;
use App\Entity\User;
use App\Repository\FixtureRepository;
use App\Service\Basketball\OpponentLocationResolver;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P2-54 RMM-9 — le RATTRAPAGE de l'annuaire adverse : localise les adversaires des
 * fixtures AWAY du club+saison encore absents (ou seulement au niveau ville) du
 * global. Management-gated (SEC-07). Cap dur AVANT tout appel réseau (au-delà de
 * MAX_DISTINCT adversaires distincts → 422 parlant, aucun jeton brûlé). Rate-limit
 * dédié PAR UTILISATEUR (la route déclenche une rafale d'appels sortants FFBB/BAN).
 *
 * N'écrit QUE la table GLOBALE `opponent_directory` — aucune donnée club/saison,
 * donc pas de garde d'écriture saison. Best-effort intégral côté résolveur : une
 * panne réseau ne casse jamais la réponse, l'adversaire reste juste non localisé.
 */
#[AsController]
final class OpponentResolveController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    /** Borne dure sur les adversaires distincts d'un rattrapage (avant tout réseau). */
    private const int MAX_DISTINCT = 60;

    public function __construct(
        private readonly OpponentLocationResolver $resolver,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SeasonResolver $seasonResolver,
        private readonly FixtureRepository $fixtures,
        private readonly RequestStack $requestStack,
        private readonly RateLimiterFactory $opponentResolveLimiter,
    ) {}

    #[Route('/api/opponents/resolve', name: 'api_opponents_resolve', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // SEC-07 first so 403 wins over the 422/429.
        $this->managementAccessGuard->assertManager();

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        $season = null === $clubId ? null : $this->seasonResolver->selectedOrCurrent($request, $clubId);
        if (null === $clubId || !$season instanceof Season) {
            return $this->json(['error' => 'Club ou saison introuvable dans le contexte.'], Response::HTTP_BAD_REQUEST);
        }

        // Cap dur AVANT tout appel réseau : `buildFixtureObservations` ne lit que la
        // base (fixtures AWAY du club+saison), aucun réseau. Un 422-cap ne brûle donc
        // pas un jeton du limiteur (consommé seulement après, avant le vrai travail).
        $observations = $this->resolver->buildFixtureObservations($this->fixtures->findAwayBySeason($season->getId()));
        if (\count($observations) > self::MAX_DISTINCT) {
            return $this->json([
                'error' => \sprintf(
                    'Trop d\'adversaires à localiser en une fois (%d, maximum %d). Réessayez après avoir réduit le nombre de rencontres à traiter.',
                    \count($observations),
                    self::MAX_DISTINCT,
                ),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->getUser();
        if ($user instanceof User && !$this->opponentResolveLimiter->create($user->getId())->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de résolutions d\'adversaires — réessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return $this->json($this->resolver->resolveObservations($observations));
    }
}
