<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueUnavailability;
use App\Repository\ClubRepository;
use App\Repository\LeagueMatchWindowRepository;
use App\Service\ConflictFingerprinter;
use App\Service\LeagueEnvelopeResolver;
use App\Service\MatchConflictDetector;
use App\Service\SeasonResolver;
use App\Service\TrainingCalendarContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
    ) {}

    // priority > 0: this static path must win over API Platform's /api/fixtures/{id}
    // item route, which would otherwise swallow "conflicts" as an (invalid) uuid.
    #[Route('/api/fixtures/conflicts', name: 'api_fixture_conflicts', methods: ['GET'], priority: 10)]
    public function __invoke(): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

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
        $league = $this->clubRepository->find($clubId)?->getLeague();
        $envelope = $this->envelopeResolver->resolve($teams, $categories, $this->leagueWindowRepository->findEnvelopeForLeague($league));
        // P1-4 PR F2 — severity 6 (completeness of PAIRED competitions).
        /** @var list<Competition> $competitions */
        $competitions = $this->entityManager->getRepository(Competition::class)->findBy([]);

        $season = $this->seasonResolver->selectedOrCurrent($this->requestStack->getCurrentRequest(), $clubId);
        // ADR-0002 context (chosen season version, active periods + overlays,
        // slots) — shared with the unavailability impact (TrainingCalendarContext).
        $context = $this->trainingCalendarContext->load($season?->getId());

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
        );

        // RMM-3 — champ ADDITIF : l'empreinte stable de chaque conflit, calculée EN
        // AVAL par la maison unique (le détecteur reste intact). Le gardien s'en sert
        // pour dire ce qui est « nouveau depuis ta dernière visite ».
        $conflicts = array_map(
            fn (array $conflict): array => $conflict + ['fingerprint' => $this->fingerprinter->fingerprint($conflict)],
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
            'seasonPlanChosen' => null !== $context['seasonScheduleId'],
        ]);
    }
}
