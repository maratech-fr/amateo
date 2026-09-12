<?php

declare(strict_types=1);

namespace App\Controller\Basketball;

use App\Controller\ResolvesCurrentClubTrait;
use App\Entity\Competition;
use App\Entity\Season;
use App\Entity\Team;
use App\Enum\CompetitionType;
use App\Repository\ClubRepository;
use App\Service\Basketball\FbiDivisionSignature;
use App\Service\Basketball\FfbbEngagementReader;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use App\Service\SocleGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * FFBB pairing (P1-4 PR F, appariement §3 — « on ré-apparie à chaque phase,
 * assumé : 1 clic contre un calendrier fiable »).
 *
 * GET  /api/ffbb/engagements        — the club's engagements of the CURRENT
 *   season, on demand (no cache, no cron — closed legal decision), each with a
 *   pre-fill suggestion and its `suggestionSource`: a Competition already
 *   carrying this ffbbCompetitionId (`pairing`), else a strict normalized match
 *   on the canonical competition name (`canonical`), else the FBI-signature
 *   bridge to a competition mapped at xlsx import (`fbi`), else nothing (null).
 * POST /api/ffbb/engagements/confirm — writes the refs on each paired team's
 *   Competition (an optional `competitionId` lands them ON the mapped xlsx
 *   competition; else reused by (teamId, canonical name) or created), freezing
 *   expectedMatchdays = 2×(N−1) and the poule's opponent club list (the import
 *   guard's OFFLINE data). Poule size and opponents come from a server-side
 *   re-read — never from the client. Not pairing a row = not sending it: the
 *   absence of a link IS the state (nothing modelled).
 *
 * Management-gated (SEC-07) + season writable + socle chosen (match-module
 * writes). Best-effort on the FFBB side: 502, never a broken gesture.
 *
 * @phpstan-import-type Signature from FbiDivisionSignature
 */
#[AsController]
final class FfbbEngagementsController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubRepository $clubRepository,
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly SocleGuard $socleGuard,
        private readonly FfbbEngagementReader $reader,
        private readonly FbiDivisionSignature $divisionSignature,
    ) {}

    #[Route('/api/ffbb/engagements', name: 'api_ffbb_engagements', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        [$clubCode, $seasonYear, $error] = $this->context($request);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        \assert(null !== $clubCode && null !== $seasonYear);

        try {
            $rows = $this->reader->read($clubCode, $seasonYear);
        } catch (Throwable) {
            return $this->json(['error' => 'FFBB indisponible, réessayez plus tard.'], Response::HTTP_BAD_GATEWAY);
        }

        /** @var list<Competition> $competitions */
        $competitions = $this->entityManager->getRepository(Competition::class)->findBy([]);
        $byFfbbId = [];
        $byCanonicalName = [];
        // FBI-signature bridge candidates (C1): the club's UNPAIRED xlsx
        // competitions, keyed by the signature parsed from their FBI code name.
        // Tenant/season filters already scope findBy([]) — a foreign club's
        // competition is invisible, so it can never feed a suggestion.
        $bridgeCandidates = [];
        foreach ($competitions as $competition) {
            if (null !== $competition->getFfbbCompetitionId()) {
                $byFfbbId[$competition->getFfbbCompetitionId()] = $competition;
            }
            if (null !== $competition->getFfbbCompetitionName()) {
                $byCanonicalName[$this->normalize($competition->getFfbbCompetitionName())] = $competition;
            }
            // Only an UNPAIRED xlsx competition feeds the bridge (a paired one is
            // already reached by its ffbb id / canonical name above).
            if (null === $competition->getFfbbCompetitionId()) {
                $signature = $this->divisionSignature->fromCode($competition->getName());
                if (null !== $signature) {
                    $bridgeCandidates[] = ['competition' => $competition, 'signature' => $signature];
                }
            }
        }

        $engagements = [];
        foreach ($rows as $row) {
            // Suggestion priority (C1): (a) a Competition already paired to THIS
            // ffbb id → idempotent re-open ; (b) strict normalized canonical-name
            // match → next phase of the same competition ; (c) the FBI-signature
            // bridge → the team already mapped at xlsx import ; (d) nothing.
            $source = null;
            $suggested = $byFfbbId[$row['ffbbCompetitionId']] ?? null;
            if ($suggested instanceof Competition) {
                $source = 'pairing';
            } else {
                $suggested = $byCanonicalName[$this->normalize($row['competitionName'])] ?? null;
                if ($suggested instanceof Competition) {
                    $source = 'canonical';
                } else {
                    $suggested = $this->bridgeSuggestion($bridgeCandidates, $row);
                    $source = $suggested instanceof Competition ? 'fbi' : null;
                }
            }
            $engagements[] = $row + [
                'suggestionSource' => $source,
                'suggestedTeamId' => $suggested?->getTeamId(),
                'suggestedCompetitionId' => $suggested?->getId(),
            ];
        }

        return $this->json(['engagements' => $engagements]);
    }

    #[Route('/api/ffbb/engagements/confirm', name: 'api_ffbb_engagements_confirm', methods: ['POST'])]
    public function confirm(Request $request): JsonResponse
    {
        // SEC-07 first so 403 wins over the 409s (import idiom).
        $this->managementAccessGuard->assertManager();
        $this->seasonAccessGuard->assertWritable($request);
        $this->socleGuard->assertSeasonPlanChosen($request->attributes->get('_season_id') ?? $request->headers->get('X-Season-Id'));

        [$clubCode, $seasonYear, $error] = $this->context($request);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        \assert(null !== $clubCode && null !== $seasonYear);

        /** @var mixed $payload */
        $payload = json_decode($request->getContent(), true);
        $pairings = \is_array($payload) && \is_array($payload['pairings'] ?? null) ? $payload['pairings'] : null;
        if (null === $pairings || [] === $pairings) {
            return $this->json(['error' => 'Aucun appariement fourni.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The poule size/opponents come from a server-side re-read — a forged
        // client cannot write expectedMatchdays and silence the completeness.
        try {
            $rows = $this->reader->read($clubCode, $seasonYear);
        } catch (Throwable) {
            return $this->json(['error' => 'FFBB indisponible, réessayez plus tard.'], Response::HTTP_BAD_GATEWAY);
        }
        $rowsByFfbbId = array_column($rows, null, 'ffbbCompetitionId');

        $season = $this->seasonResolver->selectedOrCurrent($request, $this->resolveCurrentClubId($this->requestStack) ?? '');
        if (!$season instanceof Season) {
            return $this->json(['error' => 'No season in context.'], Response::HTTP_BAD_REQUEST);
        }

        $teamRepository = $this->entityManager->getRepository(Team::class);
        $competitionRepository = $this->entityManager->getRepository(Competition::class);
        /** @var list<Competition> $competitions */
        $competitions = $competitionRepository->findBy([]);

        $confirmed = [];
        foreach ($pairings as $pairing) {
            if (!\is_array($pairing)) {
                return $this->json(['error' => 'Appariement malformé.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $ffbbCompetitionId = \is_string($pairing['ffbbCompetitionId'] ?? null) ? $pairing['ffbbCompetitionId'] : '';
            $teamId = \is_string($pairing['teamId'] ?? null) ? $pairing['teamId'] : '';
            $competitionId = \is_string($pairing['competitionId'] ?? null) && '' !== $pairing['competitionId'] ? $pairing['competitionId'] : null;
            $row = $rowsByFfbbId[$ffbbCompetitionId] ?? null;
            if (null === $row) {
                return $this->json(['error' => \sprintf('Engagement inconnu pour cette saison (%s).', $ffbbCompetitionId)], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            // Tenant/season filters make a foreign team invisible → 422, nothing written.
            if (!$teamRepository->findOneBy(['id' => $teamId]) instanceof Team) {
                return $this->json(['error' => 'Équipe inconnue pour ce club/cette saison.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // One engagement = one team (D4): the refs leave any OTHER competition
            // that carried them (its fixtures survive — only the pairing moves).
            foreach ($competitions as $other) {
                if ($other->getFfbbCompetitionId() === $ffbbCompetitionId && $other->getTeamId() !== $teamId) {
                    $other->setFfbbCompetitionId(null);
                    $other->setFfbbPouleId(null);
                    $other->setFfbbPouleName(null);
                    $other->setFfbbCompetitionName(null);
                    $other->setExpectedMatchdays(null);
                    $other->setFfbbPouleOpponents(null);
                }
            }

            $competition = $this->resolveCompetition($competitions, $teamId, $competitionId, $row['competitionName'], $season->getId());
            $competition->setFfbbCompetitionId($row['ffbbCompetitionId']);
            $competition->setFfbbPouleId($row['ffbbPouleId']);
            $competition->setFfbbPouleName($row['pouleName']);
            $competition->setFfbbCompetitionName($row['competitionName']);
            // P4-195 — une coupe n'a pas de complétude « 2×(N−1) » : le détecteur
            // resterait sur « 1 / 68 » (mesuré, Coupe du Rhône). Journées null si le
            // type EFFECTIF (après inférence ci-dessus) est CUP, sinon 2×(N−1).
            $competition->setExpectedMatchdays(
                CompetitionType::CUP === $competition->getCompetitionType()
                    ? null
                    : FfbbEngagementReader::expectedMatchdays($row['pouleSize']),
            );
            $competition->setFfbbPouleOpponents($row['pouleOpponents']);
            $confirmed[] = ['competitionId' => $competition->getId(), 'teamId' => $teamId, 'ffbbCompetitionId' => $row['ffbbCompetitionId']];
        }

        $this->entityManager->flush();

        return $this->json(['confirmed' => $confirmed]);
    }

    /**
     * The FBI-signature suggestion for ONE FFBB engagement row (C1): the team a
     * manager already mapped at xlsx import. Only when EXACTLY one team's
     * competitions carry a matching signature — several DISTINCT teams →
     * ambiguous → no suggestion (« DFU11 » → U11F1 and « DFU11-2 » → U11F2 :
     * deux équipes, rien). Several competitions toward the SAME team → the first
     * by name.
     *
     * @param list<array{competition: Competition, signature: Signature}>                                    $candidates
     * @param array{category: string|null, level: string|null, gender: string|null, competitionName: string} $row
     */
    private function bridgeSuggestion(array $candidates, array $row): ?Competition
    {
        $ffbbSignature = $this->divisionSignature->fromFfbbRow($row['category'], $row['level'], $row['gender'], $row['competitionName']);

        $matches = [];
        $teamIds = [];
        foreach ($candidates as $candidate) {
            if ($this->divisionSignature->bridges($candidate['signature'], $ffbbSignature)) {
                $matches[] = $candidate['competition'];
                $teamIds[$candidate['competition']->getTeamId()] = true;
            }
        }
        if (1 !== \count($teamIds)) {
            return null;
        }
        usort($matches, static fn (Competition $a, Competition $b): int => strcmp($a->getName(), $b->getName()));

        return $matches[0];
    }

    /** @return array{0: string|null, 1: int|null, 2: JsonResponse|null} */
    private function context(Request $request): array
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return [null, null, $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST)];
        }
        $club = $this->clubRepository->find($clubId);
        $clubCode = $club?->getFfbbClubCode();
        if (null === $clubCode || '' === $clubCode) {
            return [null, null, $this->json(['error' => 'Le club n\'a pas de code FFBB.'], Response::HTTP_UNPROCESSABLE_ENTITY)];
        }
        $season = $this->seasonResolver->selectedOrCurrent($request, $clubId);
        if (!$season instanceof Season) {
            return [null, null, $this->json(['error' => 'No season in context.'], Response::HTTP_BAD_REQUEST)];
        }

        return [$clubCode, SeasonResolver::seasonYear($season->getStartDate()), null];
    }

    /**
     * Resolve the Competition to carry the refs. When the client sends a
     * `competitionId` (the FBI-bridge suggestion accepted, C1), the refs land ON
     * that xlsx competition rather than a twin empty one — but ONLY when it
     * belongs to the chosen team (the tenant/season filters already hide a
     * foreign club's row, and the team check blocks hijacking another team's
     * competition). Its `name` stays the FBI code (« PNM » — the xlsx resolver's
     * key), only the refs are written on top; a positive type inference is
     * re-posed like on a canonical-name reuse (P4-195). Otherwise falls back to
     * the canonical-name reuse-or-create.
     *
     * @param list<Competition> $competitions
     */
    private function resolveCompetition(array &$competitions, string $teamId, ?string $competitionId, string $canonicalName, string $seasonId): Competition
    {
        if (null !== $competitionId) {
            $byId = $this->entityManager->getRepository(Competition::class)->findOneBy(['id' => $competitionId]);
            if ($byId instanceof Competition && $byId->getTeamId() === $teamId) {
                $inferredType = $this->inferCompetitionType($canonicalName);
                if ($inferredType instanceof CompetitionType) {
                    $byId->setCompetitionType($inferredType);
                }

                return $byId;
            }
        }

        return $this->findOrCreateCompetition($competitions, $teamId, $canonicalName, $seasonId);
    }

    /** @param list<Competition> $competitions */
    private function findOrCreateCompetition(array &$competitions, string $teamId, string $canonicalName, string $seasonId): Competition
    {
        $inferredType = $this->inferCompetitionType($canonicalName);
        foreach ($competitions as $competition) {
            if ($competition->getTeamId() === $teamId
                && ($competition->getFfbbCompetitionName() === $canonicalName || $this->normalize($competition->getName()) === $this->normalize($canonicalName))
            ) {
                // P4-195 — sur une compétition RÉUTILISÉE dont le NOM infère
                // positivement coupe/brassage, on repose le type (répare une Coupe
                // du Rhône stockée en championnat, mesuré 1/68). Un type posé à la
                // main via le CRUD sur un nom qui n'infère RIEN reste respecté.
                if ($inferredType instanceof CompetitionType) {
                    $competition->setCompetitionType($inferredType);
                }

                return $competition;
            }
        }

        $clubId = $this->resolveCurrentClubId($this->requestStack) ?? '';
        $competition = new Competition;
        $competition->setClubId($clubId);
        $competition->setSeasonId($seasonId);
        $competition->setTeamId($teamId);
        $competition->setName($canonicalName);
        $competition->setCompetitionType($inferredType ?? CompetitionType::CHAMPIONSHIP);
        $this->entityManager->persist($competition);
        $competitions[] = $competition;

        return $competition;
    }

    /**
     * Positive type inference from the federal name (P4-195) — « coupe » → CUP
     * (checked BEFORE « brassage »), « brassage » → BRASSAGE. Null = no positive
     * inference (default CHAMPIONSHIP on create; a hand-set CRUD type left alone
     * on reuse).
     */
    private function inferCompetitionType(string $name): ?CompetitionType
    {
        $normalized = $this->normalize($name);
        if (str_contains($normalized, 'coupe')) {
            return CompetitionType::CUP;
        }
        if (str_contains($normalized, 'brassage')) {
            return CompetitionType::BRASSAGE;
        }

        return null;
    }

    private function normalize(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $lower = mb_strtolower(false === $ascii ? $value : $ascii, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', $lower)));
    }
}
