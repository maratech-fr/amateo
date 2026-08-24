<?php

declare(strict_types=1);

namespace App\Controller\Basketball;

use App\Controller\ResolvesCurrentClubTrait;
use App\Entity\Season;
use App\Repository\ClubRepository;
use App\Service\Basketball\FfbbRencontreReconciler;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use App\Service\SocleGuard;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * The FFBB-API reconciliation channel (RMM-4 PR-3). FBI reste le focus — the API
 * « c'est pour plus de commodité » : croiser les deux sources.
 *
 * GET  /api/ffbb/rencontres        — fetch the club's published rencontres on
 *   demand, match them against the app and return the diff (deviations, same
 *   shape as the xlsx analyze) PLUS the rencontres with no matching fixture
 *   (`creatable`, the amicaux), proposed for creation. Never a promise of
 *   coverage: « ce que la FFBB publie à cet instant ».
 * POST /api/ffbb/rencontres/apply  — RE-FETCHES server-side (never the client's
 *   values), applies the per-écart decisions via the SAME engine as the xlsx
 *   import, and creates the chosen rencontres (idempotent). Writes a dated
 *   FFBB_API ingestion (never a freshness deposit, never a trace).
 *
 * Management-gated (SEC-07) + socle chosen (match-module writes) + season writable
 * on apply. Best-effort on the FFBB side: 502, never a broken gesture. A concurrent
 * create colliding on the partial unique index surfaces as a clean 409.
 */
#[AsController]
final class FfbbRencontresController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    private const string UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly ClubRepository $clubRepository,
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly SocleGuard $socleGuard,
        private readonly FfbbRencontreReconciler $reconciler,
    ) {}

    #[Route('/api/ffbb/rencontres', name: 'api_ffbb_rencontres', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        [$clubCode, $seasonYear, $seasonId, $error] = $this->context($request);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        \assert(null !== $clubCode && null !== $seasonYear);
        $this->socleGuard->assertSeasonPlanChosen($seasonId);

        try {
            $result = $this->reconciler->analyze($clubCode, $seasonYear);
        } catch (Throwable) {
            return $this->json(['error' => 'FFBB indisponible, réessayez plus tard.'], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json($result);
    }

    #[Route('/api/ffbb/rencontres/apply', name: 'api_ffbb_rencontres_apply', methods: ['POST'])]
    public function apply(Request $request): JsonResponse
    {
        // SEC-07 first so 403 wins over the 409s (import idiom): mgmt → archived
        // (409) → context → socle (409).
        $this->managementAccessGuard->assertManager();
        $this->seasonAccessGuard->assertWritable($request);

        [$clubCode, $seasonYear, $seasonId, $error] = $this->context($request);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        \assert(!\in_array(null, [$clubCode, $seasonYear, $seasonId], true));
        $this->socleGuard->assertSeasonPlanChosen($seasonId);

        $clubId = $this->resolveCurrentClubId($this->requestStack) ?? '';

        $decisions = $this->parseDecisions($request);
        if ($decisions instanceof JsonResponse) {
            return $decisions;
        }
        $creations = $this->parseCreations($request);
        if ($creations instanceof JsonResponse) {
            return $creations;
        }

        try {
            $result = $this->reconciler->apply($clubCode, $seasonYear, $clubId, $seasonId, $decisions, $creations);
        } catch (UniqueConstraintViolationException) {
            // Two simultaneous applies creating the same rencontre: the partial
            // unique index wins → a clean retryable 409 instead of a raw 500.
            return $this->json(['error' => 'Une vérification concurrente a créé les mêmes rencontres — réessayez.'], Response::HTTP_CONFLICT);
        } catch (Throwable) {
            return $this->json(['error' => 'FFBB indisponible, réessayez plus tard.'], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json([
            'created' => $result['created'],
            'updated' => $result['updated'],
            'unresolvedDeviations' => $result['unresolvedDeviations'],
            'depositedAt' => $result['depositedAt'],
        ]);
    }

    /**
     * The per-écart verdicts (same shape as the xlsx import): a JSON list of
     * {fixtureId, field, choice}. Absent = no decision (every écart stays
     * unresolved and is reported, never overwritten).
     *
     * @return list<array{fixtureId: string, field: string, choice: string}>|JsonResponse
     */
    private function parseDecisions(Request $request): array|JsonResponse
    {
        /** @var mixed $payload */
        $payload = json_decode($request->getContent(), true);
        $raw = \is_array($payload) ? ($payload['decisions'] ?? []) : [];
        if (!\is_array($raw) || !array_is_list($raw)) {
            return $this->json(['error' => 'Champ « decisions » invalide (liste JSON attendue).'], Response::HTTP_BAD_REQUEST);
        }

        $decisions = [];
        foreach ($raw as $entry) {
            if (!\is_array($entry)
                || !\is_string($entry['fixtureId'] ?? null) || 1 !== preg_match(self::UUID_RE, $entry['fixtureId'])
                || !\in_array($entry['field'] ?? null, ['date', 'kickoff', 'venue'], true)
                || !\in_array($entry['choice'] ?? null, ['keep_app', 'take_file'], true)
            ) {
                return $this->json(['error' => 'Champ « decisions » invalide (entrées {fixtureId, field: date|kickoff|venue, choice: keep_app|take_file} attendues).'], Response::HTTP_BAD_REQUEST);
            }
            $decisions[] = ['fixtureId' => $entry['fixtureId'], 'field' => $entry['field'], 'choice' => $entry['choice']];
        }

        return $decisions;
    }

    /**
     * The chosen creations: a JSON list of {rencontreId, teamId}. Absent = create
     * nothing (a non-selected creatable line is never created).
     *
     * @return list<array{rencontreId: string, teamId: string}>|JsonResponse
     */
    private function parseCreations(Request $request): array|JsonResponse
    {
        /** @var mixed $payload */
        $payload = json_decode($request->getContent(), true);
        $raw = \is_array($payload) ? ($payload['creations'] ?? []) : [];
        if (!\is_array($raw) || !array_is_list($raw)) {
            return $this->json(['error' => 'Champ « creations » invalide (liste JSON attendue).'], Response::HTTP_BAD_REQUEST);
        }

        $creations = [];
        foreach ($raw as $entry) {
            if (!\is_array($entry)
                || !\is_string($entry['rencontreId'] ?? null) || '' === $entry['rencontreId'] || mb_strlen($entry['rencontreId']) > 64
                || !\is_string($entry['teamId'] ?? null) || 1 !== preg_match(self::UUID_RE, $entry['teamId'])
            ) {
                return $this->json(['error' => 'Champ « creations » invalide (entrées {rencontreId, teamId} attendues).'], Response::HTTP_BAD_REQUEST);
            }
            $creations[] = ['rencontreId' => $entry['rencontreId'], 'teamId' => $entry['teamId']];
        }

        return $creations;
    }

    /** @return array{0: string|null, 1: int|null, 2: string|null, 3: JsonResponse|null} */
    private function context(Request $request): array
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return [null, null, null, $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST)];
        }
        $club = $this->clubRepository->find($clubId);
        $clubCode = $club?->getFfbbClubCode();
        if (null === $clubCode || '' === $clubCode) {
            return [null, null, null, $this->json(['error' => 'Le club n\'a pas de code FFBB.'], Response::HTTP_UNPROCESSABLE_ENTITY)];
        }
        $season = $this->seasonResolver->selectedOrCurrent($request, $clubId);
        if (!$season instanceof Season) {
            return [null, null, null, $this->json(['error' => 'No season in context.'], Response::HTTP_BAD_REQUEST)];
        }

        return [$clubCode, SeasonResolver::seasonYear($season->getStartDate()), $season->getId(), null];
    }
}
