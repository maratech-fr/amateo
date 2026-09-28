<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Entity\Season;
use App\Service\EngineClient;
use App\Service\ManagementAccessGuard;
use App\Service\MatchPlacementLock;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\MatchPlacementResultApplier;
use App\Service\PlanEntitlements;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use App\Service\SocleGuard;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * POST /api/fixtures/place — « Placer automatiquement » (P1-4 PR D, ADR-0003).
 * SYNCHRONOUS by decision: the problem is tiny for CP-SAT (seconds), so no
 * Messenger message, no status row, no Mercure topic — click → spinner →
 * grid filled. Guard sequence mirrors the other match writes (SEC-07 →
 * socle 409 → archived season 409), plus the per-club anti-double-click lock.
 *
 * A non-placeable match is NOT an error: it comes back in `unplaced` with its
 * named reason — the ask-your-derogation-early signal.
 *
 * P4-240 ④ — an OPTIONAL body `{from, to}` (dates AAAA-MM-JJ) restricts the placement
 * to that calendar window (« Placer ce week-end »); no body = the whole club, unchanged
 * to the byte. In the Découverte credit régime (restricted, non-demo, pool > 0) the
 * automatic placement is week-end by week-end ONLY: a call with no window, or a window
 * wider than a week, is refused 403 — the server defence behind the disabled global
 * button (1 click = 1 credit is enforced by CreditBudgetSubscriber, not here).
 */
#[AsController]
final class PlaceMatchesController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    private const LOCK_TTL_SECONDS = 120;
    private const HTTP_TIMEOUT_SECONDS = 90;

    /**
     * Largeur MAX (en jours d'écart from→to) d'une fenêtre acceptée en mode crédit :
     * 6 = une semaine Lun→Dim (7 jours calendaires inclus). Au-delà, le petit bouton
     * « Placer ce week-end » contournerait la règle week-end-par-week-end → 403.
     */
    private const int MAX_WINDOW_DAYS = 6;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly SocleGuard $socleGuard,
        private readonly MatchPlacementLock $lock,
        private readonly MatchPlacementPayloadBuilder $payloadBuilder,
        private readonly EngineClient $engineClient,
        private readonly MatchPlacementResultApplier $applier,
        private readonly PlanEntitlements $planEntitlements,
        private readonly LoggerInterface $logger,
    ) {}

    // priority > 0: the static path must win over API Platform's /api/fixtures/{id}.
    #[Route('/api/fixtures/place', name: 'api_fixtures_place', methods: ['POST'], priority: 10)]
    public function __invoke(Request $request): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        // SEC-07 first so 403 wins over the 409s (import idiom).
        $this->managementAccessGuard->assertManager();
        $this->seasonAccessGuard->assertWritable($request);
        $this->socleGuard->assertSeasonPlanChosen($request->attributes->get('_season_id') ?? $request->headers->get('X-Season-Id'));

        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        if (!$club instanceof Club) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        // P4-240 ④ — fenêtre de placement optionnelle {from, to} (dates AAAA-MM-JJ).
        $raw = trim($request->getContent());
        $window = null;
        $windowDays = 0;
        if ('' !== $raw) {
            $decoded = json_decode($raw, true);
            if (!\is_array($decoded)) {
                return $this->json(['error' => 'Le corps de la requête doit être un objet JSON.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $from = $decoded['from'] ?? null;
            $to = $decoded['to'] ?? null;
            if (null !== $from || null !== $to) {
                $fromDate = \is_string($from) ? $this->asDate($from) : null;
                $toDate = \is_string($to) ? $this->asDate($to) : null;
                if (!$fromDate instanceof DateTimeImmutable || !$toDate instanceof DateTimeImmutable) {
                    return $this->json(['error' => 'Semaine invalide : indiquez deux dates au format AAAA-MM-JJ.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                if ($fromDate > $toDate) {
                    return $this->json(['error' => 'Semaine invalide : la date de début est postérieure à la date de fin.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $window = ['from' => $fromDate->format('Y-m-d'), 'to' => $toDate->format('Y-m-d')];
                $windowDays = (int) $fromDate->diff($toDate)->days;
            }
        }

        // Mode crédit (offre Découverte effective, club non démo, pool > 0) : le placement
        // automatique se fait week-end par week-end. Défense SERVEUR — le front désactive le
        // bouton global, mais un appel direct sans fenêtre (ou d'une fenêtre trop large) doit
        // être refusé. Hors mode restreint, la fenêtre est libre. La saison est résolue ici
        // (avant le verrou), réutilisée pour le build.
        $season = $this->seasonResolver->selectedOrCurrent($request, $clubId);
        if ($season instanceof Season
            && $this->planEntitlements->outputBudget($club, $season)['restricted']
            && (null === $window || $windowDays > self::MAX_WINDOW_DAYS)) {
            throw new AccessDeniedHttpException('En offre Découverte, le placement automatique se fait week-end par week-end — sélectionnez un week-end.');
        }

        $token = $this->lock->acquire($clubId, self::LOCK_TTL_SECONDS);
        if (null === $token) {
            return $this->json(['error' => 'Un placement est déjà en cours — réessayez dans un instant.'], Response::HTTP_CONFLICT);
        }

        try {
            $built = $this->payloadBuilder->build($club, $season?->getId(), $window);
            if (0 === $built['toPlaceCount']) {
                return $this->json([
                    'placed' => 0,
                    'skipped' => 0,
                    'unplaced' => [],
                    'diagnostics' => $built['infoDiagnostics'],
                    'message' => 'Aucun match à placer.',
                ]);
            }

            try {
                $result = $this->engineClient->placeMatches($built['payload'], self::HTTP_TIMEOUT_SECONDS);
            } catch (HttpClientExceptionInterface $e) {
                // Timeout/transport/4xx-5xx: the gesture is harmless to retry —
                // nothing was written (the applier only runs on success).
                $this->logger->error('Match placement engine call failed', ['clubId' => $clubId, 'exception' => $e]);

                return $this->json(['error' => 'Le solveur n\'a pas répondu — réessayez.'], Response::HTTP_BAD_GATEWAY);
            }

            /** @var list<array{matchId: string, venueId: string, kickoff: string}> $placements */
            $placements = $result['placements'] ?? [];
            /** @var list<array{matchId: string, reason: string, message: string}> $unplaced */
            $unplaced = $result['unplaced'] ?? [];
            $outcome = $this->applier->apply($placements);

            return $this->json([
                'placed' => $outcome['applied'],
                'skipped' => $outcome['skipped'],
                'unplaced' => $unplaced,
                'diagnostics' => array_merge($built['infoDiagnostics'], $result['diagnostics'] ?? []),
                'metrics' => $result['metrics'] ?? null,
            ]);
        } finally {
            $this->lock->release($clubId, $token);
        }
    }

    /**
     * Parse STRICTE d'une date AAAA-MM-JJ (le `!` remet l'heure à 00:00, le round-trip
     * rejette « 2026-13-40 », « 2026-2-3 », une chaîne à rallonge…). null = invalide.
     */
    private function asDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
