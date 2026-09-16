<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Season;
use App\Entity\User;
use App\Repository\FixtureRepository;
use App\Service\Basketball\OpponentLocationResolver;
use App\Service\Geo\OpponentTravelResolver;
use App\Service\Geo\OpponentVenueAutoLocator;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonResolver;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * P2-54 PR-2b — l'ORCHESTRATEUR « Mettre à jour les adversaires » : un seul appel
 * serveur enchaîne les trois passes best-effort qui, ensemble, mettent à jour la
 * localisation ET le trajet de tous les adversaires AWAY du club+saison :
 *
 *   (a) rattrapage des CODES fédéraux ({@see OpponentLocationResolver::resolveObservations},
 *       comme `POST /api/opponents/resolve` — annuaire global + estampille des rencontres) ;
 *   (b) auto-localisation des gymnases depuis le libellé du FICHIER FBI
 *       ({@see OpponentVenueAutoLocator} — grain équipe, surcharge AUTO tenant) ;
 *   (c) recalcul des TRAJETS AUTO ({@see OpponentTravelResolver::resolve} — le MANUAL préservé).
 *
 * Un contrôleur DÉDIÉ (plutôt qu'une action de plus dans `OpponentTravelController`) :
 * il compose trois services que ce dernier ne connaît pas et n'a aucune donnée en
 * commun avec le CRUD de trajet ; le garder à part évite d'alourdir son constructeur.
 *
 * `assertManager()` d'abord (403 souverain). Cap dur AVANT tout réseau (au-delà de
 * {@see MAX_DISTINCT} adversaires distincts → 422 parlant, aucun jeton brûlé) ; puis un
 * limiteur PAR UTILISATEUR dédié (`opponent_refresh`, la route déclenche une rafale
 * d'appels sortants FFBB/BAN/IGN). Chaque passe est INDÉPENDANTE : l'échec de l'une
 * (loggé) n'annule jamais les autres. Les routes fines (`/opponents/resolve`,
 * `/opponents/travel/resolve`) restent en place (compat).
 */
#[AsController]
final class OpponentRefreshController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    /** Borne dure sur les adversaires distincts d'une mise à jour (avant tout réseau). */
    private const int MAX_DISTINCT = 200;

    public function __construct(
        private readonly OpponentLocationResolver $locationResolver,
        private readonly OpponentVenueAutoLocator $venueAutoLocator,
        private readonly OpponentTravelResolver $travelResolver,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SeasonResolver $seasonResolver,
        private readonly FixtureRepository $fixtures,
        private readonly RequestStack $requestStack,
        private readonly RateLimiterFactory $opponentRefreshLimiter,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/api/opponents/refresh', name: 'api_opponents_refresh', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // SEC-07 first so 403 wins over the 422/429.
        $this->managementAccessGuard->assertManager();

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        $season = null === $clubId ? null : $this->seasonResolver->selectedOrCurrent($request, $clubId);
        if (null === $clubId || !$season instanceof Season) {
            return $this->json(['error' => 'Club ou saison introuvable dans le contexte.'], Response::HTTP_BAD_REQUEST);
        }

        // Cap dur AVANT tout appel réseau : la lecture ne touche que la base (fixtures
        // AWAY du club+saison). Un 422-cap ne brûle donc pas un jeton du limiteur.
        $awayFixtures = $this->fixtures->findAwayBySeason($season->getId());
        $observations = $this->locationResolver->buildFixtureObservations($awayFixtures);
        if (\count($observations) > self::MAX_DISTINCT) {
            return $this->json([
                'error' => \sprintf(
                    'Trop d\'adversaires à mettre à jour en une fois (%d, maximum %d). Réessayez après avoir réduit le nombre de rencontres à traiter.',
                    \count($observations),
                    self::MAX_DISTINCT,
                ),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->getUser();
        if ($user instanceof User && !$this->opponentRefreshLimiter->create($user->getId())->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de mises à jour des adversaires — réessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Trois passes INDÉPENDANTES : chacune est isolée pour qu'un échec (réseau, FFBB
        // muet) n'annule jamais les suivantes. L'ordre compte : (a) estampille les codes
        // dont (b) et (c) ont besoin pour joindre l'adversaire.
        $seasonId = $season->getId();
        $codes = $this->step(
            'codes',
            fn (): array => $this->locationResolver->resolveObservations($observations, $awayFixtures),
            ['resolved' => 0, 'unresolved' => [], 'skipped' => 0, 'stamped' => 0],
        );
        $autoLocated = $this->step(
            'auto-locate',
            fn (): array => $this->venueAutoLocator->locate($clubId, $seasonId),
            ['located' => 0, 'ambiguous' => 0, 'unmatched' => 0, 'skipped' => 0],
        );
        $travel = $this->step(
            'travel',
            fn (): array => $this->travelResolver->resolve($clubId, $seasonId),
            ['resolved' => 0, 'unresolved' => [], 'skippedManual' => 0],
        );

        return $this->json([
            'codes' => $codes,
            'autoLocated' => $autoLocated,
            'travel' => $travel,
        ]);
    }

    /**
     * Exécute une passe best-effort : sur exception, journalise et retombe sur le
     * résultat NEUTRE (tout à zéro) pour que la réponse garde sa forme et que les
     * passes suivantes s'exécutent quand même.
     *
     * @param callable(): array<string, mixed> $run
     * @param array<string, mixed>             $neutral
     *
     * @return array<string, mixed>
     */
    private function step(string $label, callable $run, array $neutral): array
    {
        try {
            return $run();
        } catch (Throwable $e) {
            $this->logger->warning('Opponent refresh: step failed, continuing', ['step' => $label, 'error' => $e->getMessage()]);

            return $neutral;
        }
    }
}
