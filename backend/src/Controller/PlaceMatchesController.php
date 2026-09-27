<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Service\EngineClient;
use App\Service\ManagementAccessGuard;
use App\Service\MatchPlacementLock;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\MatchPlacementResultApplier;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use App\Service\SocleGuard;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
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
 */
#[AsController]
final class PlaceMatchesController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    private const LOCK_TTL_SECONDS = 120;
    private const HTTP_TIMEOUT_SECONDS = 90;

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

        $token = $this->lock->acquire($clubId, self::LOCK_TTL_SECONDS);
        if (null === $token) {
            return $this->json(['error' => 'Un placement est déjà en cours — réessayez dans un instant.'], Response::HTTP_CONFLICT);
        }

        try {
            $season = $this->seasonResolver->selectedOrCurrent($request, $clubId);
            $built = $this->payloadBuilder->build($club, $season?->getId());
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
}
