<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Entity\Competition;
use App\Entity\ConflictResolution;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\User;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueUnavailability;
use App\Enum\ConflictResolutionStatus;
use App\Enum\FixtureHomeAway;
use App\Repository\ClubRepository;
use App\Repository\ConflictResolutionRepository;
use App\Repository\LeagueMatchWindowRepository;
use App\Repository\OpponentTravelRepository;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\ClubDay;
use App\Service\ConflictFingerprinter;
use App\Service\LeagueEnvelopeResolver;
use App\Service\ManagementAccessGuard;
use App\Service\MatchConflictDetector;
use App\Service\MatchDurationResolver;
use App\Service\SeasonResolver;
use App\Service\TrainingCalendarContext;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * On-the-fly match/training conflict radar for a single coach (spec
 * gestion-matchs palier A, PR-2 — + VENUE_UNAVAILABLE since P1-4 PR B).
 * Recomputed at each call from the current fixtures + the schedule effective on
 * each match date (period overlay, else the season baseline) — nothing is
 * persisted. Read-only display feed for the placement grid / radar.
 *
 * Tenant scope: everything is loaded through mapped-entity repositories, so the
 * Doctrine club+season filters apply automatically — a club only ever sees its
 * own conflicts (guarded by FixtureConflictsApiTest).
 */
final class FixtureConflictsController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    // P4-207: a conflict fingerprint is `TYPE:field(,field)*(:field(,field)*)*` where
    // TYPE is A-Z_ and every field is a uuid (see ConflictFingerprinter) — so colons,
    // commas and hex/hyphen only, never a slash. A strict requirement keeps a malformed
    // fingerprint a 404 (routing) instead of reaching the action.
    private const string FINGERPRINT_REQUIREMENT = '[A-Z_]+:[0-9a-fA-F,:-]+';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly MatchConflictDetector $detector,
        private readonly ConflictFingerprinter $fingerprinter,
        private readonly TrainingCalendarContext $trainingCalendarContext,
        private readonly ClubRepository $clubRepository,
        private readonly LeagueMatchWindowRepository $leagueWindowRepository,
        private readonly LeagueEnvelopeResolver $envelopeResolver,
        private readonly MatchDurationResolver $matchDurationResolver,
        private readonly OpponentTravelRepository $opponentTravelRepository,
        private readonly ClubDay $clubDay,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly ConflictResolutionRepository $resolutionRepository,
        private readonly VenueLabelNormalizer $labelNormalizer,
    ) {}

    // priority > 0: this static path must win over API Platform's /api/fixtures/{id}
    // item route, which would otherwise swallow "conflicts" as an (invalid) uuid.
    #[Route('/api/fixtures/conflicts', name: 'api_fixture_conflicts', methods: ['GET'], priority: 10)]
    public function conflicts(): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        [$season, $conflicts, $seasonPlanChosen] = $this->computeConflicts($clubId);

        // P4-207 — champ ADDITIF `resolution` : le statut de traitement posé par le
        // gestionnaire, joint par empreinte sur les lignes du club+saison courants. La
        // map ne sert QUE les empreintes du flux ⇒ un orphelin (empreinte disparue) reste
        // invisible. Aucune ligne = « à traiter » = `null` (le défaut n'a pas de ligne).
        $byFingerprint = $season instanceof Season ? $this->resolutionRepository->mapByFingerprint($season->getId()) : [];
        $conflicts = array_map(
            function (array $conflict) use ($byFingerprint): array {
                $fingerprint = $conflict['fingerprint'] ?? null;
                $row = \is_string($fingerprint) ? ($byFingerprint[$fingerprint] ?? null) : null;

                return $conflict + ['resolution' => $row instanceof ConflictResolution ? $this->resolutionView($row) : null];
            },
            $conflicts,
        );

        return $this->json([
            'clubId' => $clubId,
            'seasonId' => $season?->getId(),
            'conflicts' => $conflicts,
            // Même raison que le radar des périodes : sans version pointée, la saison
            // n'a pas de calendrier, `MATCH_TRAINING` ne peut rien détecter hors période,
            // et `conflicts: []` devient indiscernable d'une saison réellement saine. Le
            // gestionnaire poserait un match sur un entraînement vivant. Un silence qui
            // ment est pire qu'un blanc.
            'seasonPlanChosen' => $seasonPlanChosen,
        ]);
    }

    /**
     * PUT — le gestionnaire pose (ou remplace) le statut de traitement d'un conflit.
     * L'empreinte DOIT appartenir au flux COURANT du club+saison (recalculé par la
     * même plomberie que le GET) : une empreinte absente → 422 (jamais un statut
     * fantôme). Upsert sur la clé unique (club, saison, empreinte).
     */
    #[Route('/api/fixtures/conflicts/{fingerprint}/resolution', name: 'api_fixture_conflict_resolution_put', requirements: ['fingerprint' => self::FINGERPRINT_REQUIREMENT], methods: ['PUT'], priority: 10)]
    public function putResolution(Request $request, string $fingerprint): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07 first, so 403 wins.

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var mixed $payload */
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];

        $status = ConflictResolutionStatus::tryFrom(\is_string($payload['status'] ?? null) ? $payload['status'] : '');
        if (!$status instanceof ConflictResolutionStatus) {
            return $this->json(['error' => 'Statut de traitement inconnu.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $note = null;
        if (\is_string($payload['note'] ?? null) && '' !== trim($payload['note'])) {
            $note = trim($payload['note']);
            if (mb_strlen($note) > 500) {
                return $this->json(['error' => 'La note ne peut pas dépasser 500 caractères.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        [$season, $conflicts] = $this->computeConflicts($clubId);
        if (!$season instanceof Season || !\in_array($fingerprint, array_map(static fn (array $c): string => (string) ($c['fingerprint'] ?? ''), $conflicts), true)) {
            return $this->json(['error' => 'Ce conflit n\'existe plus dans le radar actuel.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->getUser();
        \assert($user instanceof User); // assertManager() proved an active management membership.

        $row = $this->resolutionRepository->findOneByFingerprint($season->getId(), $fingerprint)
            ?? (new ConflictResolution)
                ->setClubId($clubId)
                ->setSeasonId($season->getId())
                ->setFingerprint($fingerprint);
        $row->setStatus($status)
            ->setNote($note)
            ->setUpdatedBy($user->getId());
        $this->entityManager->persist($row);
        $this->entityManager->flush();

        return $this->json(['fingerprint' => $fingerprint, 'resolution' => $this->resolutionView($row)], Response::HTTP_OK);
    }

    /**
     * DELETE — retour à « à traiter » : la ligne disparaît. Idempotent (204 même sans
     * ligne) — « à traiter » EST l'absence de ligne.
     */
    #[Route('/api/fixtures/conflicts/{fingerprint}/resolution', name: 'api_fixture_conflict_resolution_delete', requirements: ['fingerprint' => self::FINGERPRINT_REQUIREMENT], methods: ['DELETE'], priority: 10)]
    public function deleteResolution(string $fingerprint): Response
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $season = $this->seasonResolver->selectedOrCurrent($this->requestStack->getCurrentRequest(), $clubId);
        if ($season instanceof Season) {
            $row = $this->resolutionRepository->findOneByFingerprint($season->getId(), $fingerprint);
            if ($row instanceof ConflictResolution) {
                $this->entityManager->remove($row);
                $this->entityManager->flush();
            }
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * The live conflict radar of a club: load everything through mapped-entity
     * repositories (Doctrine club+season filters apply), detect, then stamp each
     * item with its stable fingerprint. Shared by the GET feed and the resolution
     * writes (which need the CURRENT flow to reject a vanished conflict).
     *
     * @return array{0: Season|null, 1: list<array<string, mixed>>, 2: bool} season, conflicts (with fingerprint), seasonPlanChosen
     */
    private function computeConflicts(string $clubId): array
    {
        /** @var list<Fixture> $fixtures */
        $fixtures = $this->entityManager->getRepository(Fixture::class)->findBy([]);
        /** @var list<TeamCoach> $teamCoachRows */
        $teamCoachRows = $this->entityManager->getRepository(TeamCoach::class)->findBy([]);
        /** @var list<VenueUnavailability> $unavailabilities */
        $unavailabilities = $this->entityManager->getRepository(VenueUnavailability::class)->findBy([]);
        /** @var list<TeamMatchHabit> $habits */
        $habits = $this->entityManager->getRepository(TeamMatchHabit::class)->findBy([]);
        /** @var list<TeamLink> $teamLinks */
        $teamLinks = $this->entityManager->getRepository(TeamLink::class)->findBy([]);
        /** @var list<VenueMatchWindow> $matchWindows */
        $matchWindows = $this->entityManager->getRepository(VenueMatchWindow::class)->findBy([]);
        // P1-4 PR E2 — the graded diagnostic needs the league envelope, resolved
        // by the SAME tolerant join as the solver (one implementation, PR D).
        /** @var list<Team> $teams */
        $teams = $this->entityManager->getRepository(Team::class)->findBy([]);
        /** @var list<SportCategory> $categories */
        $categories = $this->entityManager->getRepository(SportCategory::class)->findBy([]);
        $club = $this->clubRepository->find($clubId);
        $league = $club?->getLeague();
        $envelope = $this->envelopeResolver->resolve($teams, $categories, $this->leagueWindowRepository->findEnvelopeForLeague($league));
        // D1 rule 3 — the club's civil today drops already-played matches from the
        // radar (foyer ClubDay, never rebuilt inline).
        $clubToday = $club instanceof Club ? $this->clubDay->todayFor($club) : null;
        // P2-54 RMM-9 — teamId → match duration profile: the category's own values
        // when set, else its family default (MatchDurationResolver). The detector
        // stays PURE (data injected), the resolution happens once, here.
        $categoriesById = [];
        foreach ($categories as $category) {
            $categoriesById[$category->getId()] = $category;
        }
        $profilesByTeam = [];
        foreach ($teams as $team) {
            $category = $categoriesById[$team->getSportCategoryId()] ?? null;
            if (null !== $category) {
                $profilesByTeam[$team->getId()] = $this->matchDurationResolver->resolve($category);
            }
        }
        // P1-4 PR F2 — severity 6 (completeness of PAIRED competitions).
        /** @var list<Competition> $competitions */
        $competitions = $this->entityManager->getRepository(Competition::class)->findBy([]);

        $season = $this->seasonResolver->selectedOrCurrent($this->requestStack->getCurrentRequest(), $clubId);
        // ADR-0002 context (chosen season version, active periods + overlays,
        // slots) — shared with the unavailability impact (TrainingCalendarContext).
        $context = $this->trainingCalendarContext->load($season?->getId());

        // P2-54 RMM-9 PR-3 — the SPATIAL radar: an AWAY fixture's footprint grows by
        // the round trip (2 × one-way car time) to the opponent's venue, read from
        // the tenant `opponent_travel` via the stamped organisme code. Grain ÉQUIPE
        // (P2-54 PR-1) : chaque rencontre résout son trajet par (code, libellé
        // normalisé) → override équipe, sinon défaut club. Une rencontre sans code /
        // sans trajet reste 0 (aucun conflit spatial — dit franchement).
        $roundTripByFixtureId = [];
        if ($season instanceof Season) {
            $travelBySeason = $this->opponentTravelRepository->travelMinutesBySeason($season->getId());
            if ([] !== $travelBySeason) {
                foreach ($fixtures as $fixture) {
                    $code = $fixture->getOpponentOrganismeCode();
                    if (FixtureHomeAway::AWAY !== $fixture->getHomeAway() || null === $code || !isset($travelBySeason[$code])) {
                        continue;
                    }
                    $teamKey = $this->labelNormalizer->normalize(trim($fixture->getOpponentLabel()));
                    $entry = $travelBySeason[$code];
                    $oneWay = $entry['teams'][$teamKey] ?? $entry['club'];
                    if (null !== $oneWay) {
                        $roundTripByFixtureId[$fixture->getId()] = 2 * $oneWay;
                    }
                }
            }
        }

        $conflicts = $this->detector->detect(
            $fixtures,
            $teamCoachRows,
            $context['seasonScheduleId'],
            $context['activePeriods'],
            $context['slotsBySchedule'],
            $unavailabilities,
            $habits,
            $teamLinks,
            $matchWindows,
            $envelope,
            $competitions,
            $profilesByTeam,
            $roundTripByFixtureId,
            $clubToday,
        );

        // RMM-3 — champ ADDITIF : l'empreinte stable de chaque conflit, calculée EN
        // AVAL par la maison unique (le détecteur reste intact). Le gardien s'en sert
        // pour dire ce qui est « nouveau depuis ta dernière visite ».
        $conflicts = array_map(
            fn (array $conflict): array => $conflict + ['fingerprint' => $this->fingerprinter->fingerprint($conflict)],
            $conflicts,
        );

        return [$season, $conflicts, null !== $context['seasonScheduleId']];
    }

    /** @return array{status: string, note: string|null, updatedAt: string} */
    private function resolutionView(ConflictResolution $row): array
    {
        return [
            'status' => $row->getStatus()->value,
            'note' => $row->getNote(),
            'updatedAt' => $row->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];
    }
}
