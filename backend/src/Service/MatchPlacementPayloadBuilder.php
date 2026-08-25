<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Fixture;
use App\Entity\LeagueMatchWindow;
use App\Entity\MatchSlotRotation;
use App\Entity\MatchSlotRotationTeam;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueUnavailability;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use App\Repository\LeagueMatchWindowRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the engine `/place-matches` payload (contract 2.2, ADR-0003). The
 * backend PROJECTS, the engine stays flat:
 * - training occupancies are dated projections of the EFFECTIVE schedules
 *   (ADR-0002 rules via TrainingCalendarContext/EffectiveScheduleResolver —
 *   never re-implemented engine-side);
 * - away kickoffs are estimated by the SAME AwayKickoffEstimator the radar
 *   uses;
 * - the league envelope is resolved per team by LeagueEnvelopeResolver
 *   (tolerant join — unmapped team = no league HARD + an INFO diagnostic).
 *
 * Match kinds:
 * - HOME UNPLACED → TO_PLACE ;
 * - HOME PLACED by the SOLVER → TO_PLACE carrying its current placement
 *   (stability bonus + hint) ;
 * - HOME PLACED manually (or legacy null source), SUBMITTED, VALIDATED →
 *   FIXED anchors — IF they still carry venue+kickoff; a submitted match that
 *   lost its venue (DOC-2) is skipped: it can neither anchor nor be placed ;
 * - AWAY → informative footprint (real or estimated hour, else none).
 */
final class MatchPlacementPayloadBuilder
{
    /**
     * Version du CONTRAT backend⇄engine que ce payload s'attribue. Même contrat
     * que `/generate` (un seul contrat, 3 endpoints) : c'est la MÊME chose que
     * `engine/CONTRACT_VERSION`, comparée par l'engine au champ `version` reçu.
     * Elle DOIT valoir exactement la valeur du fichier — gardé par
     * `PayloadVersionMatchesContractVersionTest`.
     */
    public const string CONTRACT_VERSION = '2.15';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TrainingCalendarContext $trainingCalendarContext,
        private readonly AwayKickoffEstimator $awayKickoffEstimator,
        private readonly LeagueEnvelopeResolver $leagueEnvelopeResolver,
        private readonly EffectiveScheduleResolver $effectiveScheduleResolver,
    ) {}

    /**
     * @return array{payload: array<string, mixed>, toPlaceCount: int, infoDiagnostics: list<array<string, mixed>>}
     */
    public function build(Club $club, ?string $seasonId): array
    {
        /** @var list<Fixture> $fixtures */
        $fixtures = $this->entityManager->getRepository(Fixture::class)->findBy([]);
        /** @var list<Team> $teams */
        $teams = $this->entityManager->getRepository(Team::class)->findBy([]);
        /** @var list<SportCategory> $categories */
        $categories = $this->entityManager->getRepository(SportCategory::class)->findBy([]);
        /** @var list<Venue> $venues */
        $venues = $this->entityManager->getRepository(Venue::class)->findBy([]);
        /** @var list<VenueMatchWindow> $matchWindows */
        $matchWindows = $this->entityManager->getRepository(VenueMatchWindow::class)->findBy([]);
        /** @var list<VenueUnavailability> $unavailabilities */
        $unavailabilities = $this->entityManager->getRepository(VenueUnavailability::class)->findBy([]);
        /** @var list<TeamMatchHabit> $habits */
        $habits = $this->entityManager->getRepository(TeamMatchHabit::class)->findBy([]);
        /** @var list<TeamLink> $teamLinks */
        $teamLinks = $this->entityManager->getRepository(TeamLink::class)->findBy([]);
        /** @var list<TeamCoach> $teamCoaches */
        $teamCoaches = $this->entityManager->getRepository(TeamCoach::class)->findBy([]);
        /** @var list<MatchSlotRotation> $rotations */
        $rotations = $this->entityManager->getRepository(MatchSlotRotation::class)->findBy([]);

        $habitIndex = $this->awayKickoffEstimator->indexHabits($habits);

        // Slot rotations (RMM-5, §8) : le créneau de match PARTAGÉ entre N équipes
        // qui l'occupent en alternance (SM1/SM2 sur le 20h30). $rotationTeamDays
        // porte la SUPPLÉANCE (tranchage 5) : l'habitude d'un membre LE MÊME JOUR
        // que sa rotation n'est PAS émise plus bas — la rotation vaut habitude ce
        // jour-là, un seul bonus SOFT côté moteur.
        [$rotationRows, $rotationTeamDays] = $this->slotRotations($rotations);

        // Matches.
        $toPlaceCount = 0;
        $matchRows = [];
        foreach ($fixtures as $fixture) {
            $row = $this->matchRow($fixture, $habitIndex);
            if (null === $row) {
                continue;
            }
            if ('TO_PLACE' === $row['kind']) {
                ++$toPlaceCount;
            }
            $matchRows[] = $row;
        }

        // Venues with their capacity data.
        $windowsByVenue = [];
        foreach ($matchWindows as $window) {
            $windowsByVenue[$window->getVenueId()][] = [
                'dayOfWeek' => $window->getDayOfWeek(),
                'start' => $window->getStartTime()->format('H:i'),
                'end' => $window->getEndTime()->format('H:i'),
            ];
        }
        $unavailabilitiesByVenue = [];
        foreach ($unavailabilities as $unavailability) {
            $unavailabilitiesByVenue[$unavailability->getVenueId()][] = [
                'startDate' => $unavailability->getStartDate()->format('Y-m-d'),
                'endDate' => $unavailability->getEndDate()->format('Y-m-d'),
            ];
        }
        $venueRows = [];
        foreach ($venues as $venue) {
            $venueRows[] = [
                'id' => $venue->getId(),
                'name' => $venue->getName(),
                'matchWindows' => $windowsByVenue[$venue->getId()] ?? [],
                'unavailabilities' => $unavailabilitiesByVenue[$venue->getId()] ?? [],
            ];
        }

        // Teams: league envelope (tolerant), habits, coaches.
        $envelope = $this->resolveEnvelope($club, $teams, $categories);
        $habitsByTeam = [];
        foreach ($habits as $habit) {
            // Suppléance (tranchage 5) : l'habitude d'un membre le MÊME jour que sa
            // rotation est SUPPLANTÉE — pas de double bonus. Les autres jours restent.
            if (isset($rotationTeamDays[$habit->getTeamId()][$habit->getDayOfWeek()])) {
                continue;
            }
            $habitsByTeam[$habit->getTeamId()][] = [
                'dayOfWeek' => $habit->getDayOfWeek(),
                'kickoff' => $habit->getKickoffTime()->format('H:i'),
                'venueId' => $habit->getVenueId(),
            ];
        }
        $coachesByTeam = [];
        foreach ($teamCoaches as $link) {
            $coachesByTeam[$link->getTeamId()][] = [
                'coachId' => $link->getCoachId(),
                'role' => $link->getRole()->value,
            ];
        }
        $teamRows = [];
        $infoDiagnostics = [];
        foreach ($teams as $team) {
            $windows = $envelope[$team->getId()] ?? [];
            $teamRows[] = [
                'id' => $team->getId(),
                'name' => $team->getName(),
                'leagueWindows' => array_map(static fn (LeagueMatchWindow $w): array => [
                    'dayOfWeek' => $w->getDayOfWeek(),
                    'kickoffMin' => $w->getKickoffMin()->format('H:i'),
                    'kickoffMax' => $w->getKickoffMax()->format('H:i'),
                ], $windows),
                'habits' => $habitsByTeam[$team->getId()] ?? [],
                'coaches' => $coachesByTeam[$team->getId()] ?? [],
            ];
            if ([] === $windows) {
                $infoDiagnostics[] = [
                    'type' => 'league_envelope_unresolved',
                    'severity' => 'info',
                    'teamId' => $team->getId(),
                    'message' => \sprintf(
                        'Équipe « %s » : enveloppe ligue non résolue — placement sans contrainte de fenêtre fédérale.',
                        $team->getName(),
                    ),
                ];
            }
        }

        return [
            'payload' => [
                'version' => self::CONTRACT_VERSION,
                'clubId' => $club->getId(),
                'seasonId' => $seasonId ?? '',
                'solverSeed' => 42,
                'solverTimeoutSeconds' => 30,
                'matches' => $matchRows,
                'venues' => $venueRows,
                'teams' => $teamRows,
                'teamLinks' => array_map(static fn (TeamLink $link): array => [
                    'teamAId' => $link->getTeamAId(),
                    'teamBId' => $link->getTeamBId(),
                    'type' => $link->getLinkType()->value,
                ], $teamLinks),
                'slotRotations' => $rotationRows,
                'trainingOccupancies' => $this->trainingOccupancies($fixtures, $seasonId, $teamCoaches),
            ],
            'toPlaceCount' => $toPlaceCount,
            'infoDiagnostics' => $infoDiagnostics,
        ];
    }

    /**
     * @param array<string, array<int, TeamMatchHabit>> $habitIndex
     *
     * @return array<string, mixed>|null null = skipped (unanchorable submitted match)
     */
    private function matchRow(Fixture $fixture, array $habitIndex): ?array
    {
        $base = [
            'id' => $fixture->getId(),
            'teamId' => $fixture->getTeamId(),
            'date' => $fixture->getMatchDate()->format('Y-m-d'),
        ];

        if (FixtureHomeAway::AWAY === $fixture->getHomeAway()) {
            $estimated = $this->awayKickoffEstimator->estimate($fixture, $habitIndex);
            $kickoff = $fixture->getKickoffTime() ?? $estimated;

            return $base + [
                'kind' => 'AWAY',
                'kickoff' => $kickoff?->format('H:i'),
                'kickoffEstimated' => !$fixture->getKickoffTime() instanceof DateTimeImmutable && $estimated instanceof DateTimeImmutable,
            ];
        }

        $isSolverPlaced = FixtureStatus::PLACED === $fixture->getStatus()
            && FixturePlacementSource::SOLVER === $fixture->getPlacementSource();
        if (FixtureStatus::UNPLACED === $fixture->getStatus() || $isSolverPlaced) {
            return $base + [
                'kind' => 'TO_PLACE',
                'currentVenueId' => $isSolverPlaced ? $fixture->getVenueId() : null,
                'currentKickoff' => $isSolverPlaced ? $fixture->getKickoffTime()?->format('H:i') : null,
            ];
        }

        // Manual / submitted / validated anchors — only if still fully anchored
        // (a submitted match whose venue was deleted, DOC-2, can do neither).
        if (null === $fixture->getVenueId() || !$fixture->getKickoffTime() instanceof DateTimeImmutable) {
            return null;
        }

        return $base + [
            'kind' => 'FIXED',
            'venueId' => $fixture->getVenueId(),
            'kickoff' => $fixture->getKickoffTime()->format('H:i'),
        ];
    }

    /**
     * Sérialise les créneaux de match partagés (RMM-5, §8) en
     * `{venueId, dayOfWeek, kickoff, teamIds}`, et l'index (teamId → jours) de la
     * SUPPLÉANCE des habitudes. L'ordre `position` des membres ne VOYAGE PAS
     * (fictif, décision fondateur n°4 : le moteur n'en a pas l'usage) — mais
     * l'ordre d'itération des rotations ET des teamIds est déterministe (patron du
     * tri des tags, ScheduleConstraintBuilder) : ni les UUID des rotations ni ceux
     * des membres ne doivent faire varier le payload. Une rotation tombée sous 2
     * membres n'a plus de sens (miroir de serializeSharedTrainings) : abandonnée.
     *
     * @param list<MatchSlotRotation> $rotations
     *
     * @return array{0: list<array{venueId: string, dayOfWeek: int, kickoff: string, teamIds: list<string>}>, 1: array<string, array<int, true>>}
     */
    private function slotRotations(array $rotations): array
    {
        $rows = [];
        $teamDays = [];
        foreach ($rotations as $rotation) {
            /** @var list<MatchSlotRotationTeam> $members */
            $members = $this->entityManager->getRepository(MatchSlotRotationTeam::class)
                ->findBy(['rotationId' => $rotation->getId()]);
            $teamIds = [];
            foreach ($members as $member) {
                $teamIds[] = $member->getTeamId();
            }
            sort($teamIds);
            if (\count($teamIds) < 2) {
                continue;
            }
            foreach ($teamIds as $teamId) {
                $teamDays[$teamId][$rotation->getDayOfWeek()] = true;
            }
            $rows[] = [
                'venueId' => $rotation->getVenueId(),
                'dayOfWeek' => $rotation->getDayOfWeek(),
                'kickoff' => $rotation->getKickoffTime()->format('H:i'),
                'teamIds' => $teamIds,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['venueId'], $a['dayOfWeek'], $a['kickoff']]
            <=> [$b['venueId'], $b['dayOfWeek'], $b['kickoff']]);

        return [$rows, $teamDays];
    }

    /**
     * @param list<Team>          $teams
     * @param list<SportCategory> $categories
     *
     * @return array<string, list<LeagueMatchWindow>>
     */
    private function resolveEnvelope(Club $club, array $teams, array $categories): array
    {
        /** @var LeagueMatchWindowRepository $repository */
        $repository = $this->entityManager->getRepository(LeagueMatchWindow::class);
        $windows = $repository->findEnvelopeForLeague($club->getLeague());

        return $this->leagueEnvelopeResolver->resolve($teams, $categories, $windows);
    }

    /**
     * Dated projection of the training sessions on every date the horizon
     * touches — one occupancy per (slot, coach) with the slot's own coach when
     * set, else every coach of the slot's team (the radar's exact rule).
     *
     * @param list<Fixture>   $fixtures
     * @param list<TeamCoach> $teamCoaches
     *
     * @return list<array<string, string>>
     */
    private function trainingOccupancies(array $fixtures, ?string $seasonId, array $teamCoaches): array
    {
        $context = $this->trainingCalendarContext->load($seasonId);
        $coachesByTeam = [];
        foreach ($teamCoaches as $link) {
            $coachesByTeam[$link->getTeamId()][] = $link->getCoachId();
        }

        $dates = [];
        foreach ($fixtures as $fixture) {
            $dates[$fixture->getMatchDate()->format('Y-m-d')] = $fixture->getMatchDate();
        }

        $occupancies = [];
        foreach ($dates as $dateKey => $date) {
            $scheduleId = $this->effectiveScheduleResolver->resolve(
                $date,
                $context['activePeriods'],
                $context['seasonScheduleId'],
            );
            if (null === $scheduleId) {
                continue;
            }
            $isoWeekday = (int) $date->format('N');
            foreach ($context['slotsBySchedule'][$scheduleId] ?? [] as $slot) {
                if ($slot->getDayOfWeek() !== $isoWeekday) {
                    continue;
                }
                $start = $slot->getStartTime()->format('H:i');
                $end = $slot->getStartTime()->add(new DateInterval('PT' . $slot->getDurationMinutes() . 'M'))->format('H:i');
                $slotCoach = $slot->getCoachId();
                $coachIds = null !== $slotCoach ? [$slotCoach] : ($coachesByTeam[$slot->getTeamId()] ?? []);
                foreach ($coachIds as $coachId) {
                    $occupancies[] = ['date' => $dateKey, 'start' => $start, 'end' => $end, 'coachId' => $coachId];
                }
            }
        }

        return $occupancies;
    }
}
