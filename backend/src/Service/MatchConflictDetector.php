<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\LeagueMatchWindow;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueUnavailability;
use App\Enum\FixtureHomeAway;
use App\Enum\TeamCoachRole;
use App\Enum\TeamLinkType;
use DateInterval;
use DateTimeImmutable;

/**
 * Detects, on the fly, the time-occupancy conflicts a SINGLE coach faces in a
 * season (spec gestion-matchs palier A, PR-2). In an amateur club a match and a
 * training can NEVER overlap for the same person — this surfaces the clash as
 * early as the fixture is entered (the anticipation value of the module).
 *
 * Three conflict kinds. The first two are built on {@see MatchFootprint}
 * occupancy windows:
 * - MATCH_MATCH: two fixtures of teams sharing a coach whose windows overlap.
 * - MATCH_TRAINING: a fixture overlapping a training of one of the coach's teams,
 *   read from the schedule EFFECTIVE on the match date (rule extracted to
 *   {@see EffectiveScheduleResolver} — P1-4 PR B). A footprint crossing midnight
 *   is checked against BOTH calendar days it spans.
 * - VENUE_UNAVAILABLE (P1-4 PR B): a fixture whose venue is unavailable on its
 *   date (all-circumstances closure posed on the club calendar AFTER the match
 *   was placed — the real-life case the placement guard cannot catch). Coach-
 *   independent, kickoff-independent: the DATE falling in the range suffices.
 * - TEAM_LINK_OVERLAP (P1-4 PR C): two fixtures of two DECLARED-linked teams
 *   (« SM1 et SM2 partagent des joueurs ») whose footprints overlap. Only
 *   NOT_SIMULTANEOUS links raise it — BACK_TO_BACK is a preference the PR D
 *   solver consumes, not an objective rule.
 *
 * Away-kickoff ESTIMATION (P1-4 PR C, resorbs the PR-2 blind spot): an AWAY
 * fixture without a real hour borrows its team's HABITUAL kickoff when a habit
 * exists on the match's weekday — the footprint is born, conflicts become
 * visible, flagged `estimatedKickoff: true`. Nothing is persisted. No habit
 * on that weekday → still no footprint (told apart in PR E's graded
 * diagnostic). Unplaced HOME fixtures are NOT estimated: their hour is the
 * manager's next gesture, estimating it would be noise.
 *
 * GRADED diagnostic (P1-4 PR E2, cadrage §8): every finding carries a
 * `severity` (1 = worst), emitted by the SERVER — the UI groups and labels, it
 * never re-derives gravity. Coach findings carry `coachRole`: MAIN when the
 * coach is MAIN on ANY involved team (the worst engagement counts) → severity
 * 3, else ASSISTANT → 5. New finding kinds:
 * - VENUE_OVERLAP (1): two placed fixtures, same venue, overlapping footprints —
 *   the manual loop never blocks a collision (founder decision), the diagnostic
 *   screams instead.
 * - LEAGUE_WINDOW_VIOLATION (2): a placed HOME fixture of a MAPPED team whose
 *   day/kickoff sit outside every resolved league window (same
 *   LeagueEnvelopeResolver join as the solver — unmapped team = silent).
 * - ACCESS_WINDOW_LOST (4, dette ii): a placed HOME fixture whose kickoff no
 *   longer falls in any access window of (venue, weekday) — the window changed
 *   AFTER the placement. Mirrors the PANEL rule (kickoff point, half-open,
 *   club-without-any-window = nothing to enforce), NOT the solver's
 *   full-footprint rule: a match the panel just allowed must not alert.
 * - AWAY_NO_FOOTPRINT (7, dette v): an AWAY fixture with no hour and no habit
 *   on its weekday — the residual blind spot is now NAMED (info; the UI folds
 *   the group).
 *
 * Pure/stateless: the controller loads the scoped data and passes it in; this
 * class only crosses and overlaps, so it is unit-testable without a kernel.
 *
 * Away travel is not modelled yet (palier B): an away fixture with no estimated
 * kickoff has no footprint and therefore raises no conflict — intended.
 */
final class MatchConflictDetector
{
    public function __construct(
        private readonly MatchFootprint $footprint,
        private readonly EffectiveScheduleResolver $effectiveScheduleResolver,
        private readonly AwayKickoffEstimator $awayKickoffEstimator,
    ) {}

    /**
     * LE prédicat pur d'accès match — le coup d'envoi (H:i) tombe-t-il dans une fenêtre de
     * (gymnase, jour) ? Intervalle DEMI-OUVERT `[start, end[`. Extrait pour la parité
     * MÉCANIQUE avec le front (`matches/lib/matchAccess.ts::kickoffInsideWindow`, cas
     * partagés `matchAccess.parity.json`, gardés par `MatchAccessMirrorParityTest`) : le
     * front l'utilise pour BLOQUER la pose, le backend pour DIAGNOSTIQUER (ACCESS_WINDOW_LOST).
     * L'enveloppe diverge (déclaré côté front), cette algèbre-ci ne doit pas.
     *
     * @param list<array{venueId: string, dayOfWeek: int, startTime: string, endTime: string}> $windows
     */
    public static function kickoffInsideWindow(string $venueId, int $day, string $kickoff, array $windows): bool
    {
        foreach ($windows as $window) {
            if ($window['venueId'] === $venueId
                && $window['dayOfWeek'] === $day
                && $kickoff >= $window['startTime']
                && $kickoff < $window['endTime']
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Fixture>                                                                          $fixtures         season fixtures (already club+season scoped)
     * @param list<TeamCoach>                                                                        $teamCoachRows    coach↔team links (scoped)
     * @param string|null                                                                            $seasonScheduleId the season's calendar (the version its plan points at), or null
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}> $activePeriods
     *                                                                                                                 active period windows (ordered), scheduleId = their overlay or null
     * @param array<string, list<ScheduleSlotTemplate>>                                              $slotsBySchedule  slots indexed by their scheduleId
     * @param list<VenueUnavailability>                                                              $unavailabilities scoped all-circumstances closures
     * @param list<TeamMatchHabit>                                                                   $habits           scoped habitual windows (estimation source)
     * @param list<TeamLink>                                                                         $teamLinks        scoped declared bridges
     * @param list<VenueMatchWindow>                                                                 $matchWindows     scoped access windows (ACCESS_WINDOW_LOST)
     * @param array<string, list<LeagueMatchWindow>>                                                 $envelope         teamId → resolved league windows ([] = unmapped)
     * @param list<Competition>                                                                      $competitions     scoped competitions (COMPETITION_INCOMPLETE — severity 6)
     * @param array<string, MatchDurationProfile>                                                    $profilesByTeam   teamId → match duration profile (P2-54 RMM-9); a team absent falls back to MatchDurationProfile::fallback()
     *
     * @return list<array<string, mixed>> conflict items ready to serialize
     */
    public function detect(
        array $fixtures,
        array $teamCoachRows,
        ?string $seasonScheduleId,
        array $activePeriods,
        array $slotsBySchedule,
        array $unavailabilities = [],
        array $habits = [],
        array $teamLinks = [],
        array $matchWindows = [],
        array $envelope = [],
        array $competitions = [],
        array $profilesByTeam = [],
    ): array {
        $coachesByTeam = [];
        // teamId → coachId → role; a coach both MAIN and ASSISTANT on one team
        // counts MAIN (the worst engagement wins, cadrage §8).
        $rolesByTeam = [];
        foreach ($teamCoachRows as $link) {
            $coachesByTeam[$link->getTeamId()][$link->getCoachId()] = true;
            $current = $rolesByTeam[$link->getTeamId()][$link->getCoachId()] ?? null;
            if (TeamCoachRole::MAIN !== $current) {
                $rolesByTeam[$link->getTeamId()][$link->getCoachId()] = $link->getRole();
            }
        }

        $habitByTeamDay = $this->awayKickoffEstimator->indexHabits($habits);

        // Fixtures with a footprint: a real kickoff, or (P1-4 PR C) an AWAY
        // fixture borrowing its team's habitual kickoff for the match weekday
        // (rule extracted to AwayKickoffEstimator — the placement payload
        // consumes the SAME estimation, PR D). Coaches attached for the coach
        // conflicts; a coach-less fixture still gets a view (team links don't
        // need a coach).
        $views = [];
        foreach ($fixtures as $fixture) {
            // P2-54 RMM-9 — the footprint durations now depend on the team's
            // category profile; a team missing from the map falls back to the
            // documented 105/30 (MatchDurationProfile::fallback()).
            $profile = $profilesByTeam[$fixture->getTeamId()] ?? MatchDurationProfile::fallback();
            $estimated = false;
            $window = $this->footprint->occupancy($fixture, $profile);
            if (null === $window) {
                $estimatedKickoff = $this->awayKickoffEstimator->estimate($fixture, $habitByTeamDay);
                if ($estimatedKickoff instanceof DateTimeImmutable) {
                    $window = $this->footprint->occupancyAt($fixture, $estimatedKickoff, $profile);
                    $estimated = true;
                }
            }
            if (null === $window) {
                continue;
            }
            $views[] = [
                'fixture' => $fixture,
                'window' => $window,
                'estimated' => $estimated,
                'coachIds' => array_keys($coachesByTeam[$fixture->getTeamId()] ?? []),
            ];
        }
        $coachViews = array_values(array_filter($views, static fn (array $view): bool => [] !== $view['coachIds']));

        return [
            ...$this->venueOverlapConflicts($views),
            ...$this->leagueWindowViolations($fixtures, $envelope),
            ...$this->matchMatchConflicts($coachViews, $rolesByTeam),
            ...$this->matchTrainingConflicts($coachViews, $coachesByTeam, $rolesByTeam, $seasonScheduleId, $activePeriods, $slotsBySchedule),
            ...$this->venueUnavailableConflicts($fixtures, $unavailabilities),
            ...$this->accessWindowLostConflicts($fixtures, $matchWindows),
            ...$this->teamLinkConflicts($views, $teamLinks),
            ...$this->competitionIncompleteItems($fixtures, $competitions),
            ...$this->awayNoFootprintItems($fixtures, $habitByTeamDay),
        ];
    }

    /**
     * Severity 6 (P1-4 PR F2, cadrage §8.6) — a PAIRED competition holding fewer
     * fixtures than its frozen expectation (2×(N−1) matchdays): partial file, or
     * a phase not out yet — either way, the manager should not have to count by
     * hand across 14 teams × 3 phases. Competitions without a pairing are silent.
     *
     * @param list<Fixture>     $fixtures
     * @param list<Competition> $competitions
     *
     * @return list<array<string, mixed>>
     */
    private function competitionIncompleteItems(array $fixtures, array $competitions): array
    {
        $countByCompetition = [];
        foreach ($fixtures as $fixture) {
            $competitionId = $fixture->getCompetitionId();
            if (null !== $competitionId) {
                $countByCompetition[$competitionId] = ($countByCompetition[$competitionId] ?? 0) + 1;
            }
        }

        $items = [];
        foreach ($competitions as $competition) {
            $expected = $competition->getExpectedMatchdays();
            if (null === $expected) {
                continue;
            }
            $imported = $countByCompetition[$competition->getId()] ?? 0;
            if ($imported >= $expected) {
                continue;
            }
            $items[] = [
                'type' => 'COMPETITION_INCOMPLETE',
                'severity' => 6,
                'competitionId' => $competition->getId(),
                'competitionName' => $competition->getName(),
                'teamId' => $competition->getTeamId(),
                'imported' => $imported,
                'expected' => $expected,
            ];
        }

        return $items;
    }

    /**
     * Severity 1 — two fixtures on the SAME venue with overlapping footprints.
     * The manual loop lets this happen on purpose (a derogation or the league
     * can impose it); the diagnostic makes it the loudest finding instead.
     *
     * @param list<array{fixture: Fixture, window: array{start: DateTimeImmutable, end: DateTimeImmutable}, estimated: bool, coachIds: list<string>}> $views
     *
     * @return list<array<string, mixed>>
     */
    private function venueOverlapConflicts(array $views): array
    {
        $withVenue = array_values(array_filter($views, static fn (array $view): bool => null !== $view['fixture']->getVenueId()));
        $conflicts = [];
        $count = \count($withVenue);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $left = $withVenue[$i];
                $right = $withVenue[$j];
                if ($left['fixture']->getVenueId() !== $right['fixture']->getVenueId() || !$this->overlaps($left['window'], $right['window'])) {
                    continue;
                }
                $conflicts[] = [
                    'type' => 'VENUE_OVERLAP',
                    'severity' => 1,
                    'venueId' => $left['fixture']->getVenueId(),
                    'start' => $this->maxMoment($left['window']['start'], $right['window']['start'])->format(DateTimeImmutable::ATOM),
                    'end' => $this->minMoment($left['window']['end'], $right['window']['end'])->format(DateTimeImmutable::ATOM),
                    'left' => $this->fixtureView($left['fixture'], $left['window'], $left['estimated']),
                    'right' => $this->fixtureView($right['fixture'], $right['window'], $right['estimated']),
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Severity 2 — a placed HOME fixture of a MAPPED team outside every resolved
     * league window (day or kickoff). Unmapped team ([] envelope) = silent, same
     * tolerance as the solver and the placement screen.
     *
     * @param list<Fixture>                          $fixtures
     * @param array<string, list<LeagueMatchWindow>> $envelope
     *
     * @return list<array<string, mixed>>
     */
    private function leagueWindowViolations(array $fixtures, array $envelope): array
    {
        $conflicts = [];
        foreach ($fixtures as $fixture) {
            $kickoffTime = $fixture->getKickoffTime();
            if (FixtureHomeAway::HOME !== $fixture->getHomeAway() || !$kickoffTime instanceof DateTimeImmutable) {
                continue;
            }
            $windows = $envelope[$fixture->getTeamId()] ?? [];
            if ([] === $windows) {
                continue;
            }
            $day = (int) $fixture->getMatchDate()->format('N');
            $kickoff = $kickoffTime->format('H:i');
            $inside = false;
            foreach ($windows as $window) {
                if ($window->getDayOfWeek() === $day
                    && $kickoff >= $window->getKickoffMin()->format('H:i')
                    && $kickoff <= $window->getKickoffMax()->format('H:i')
                ) {
                    $inside = true;
                    break;
                }
            }
            if ($inside) {
                continue;
            }
            $conflicts[] = [
                'type' => 'LEAGUE_WINDOW_VIOLATION',
                'severity' => 2,
                'windows' => array_map(static fn (LeagueMatchWindow $w): array => [
                    'dayOfWeek' => $w->getDayOfWeek(),
                    'kickoffMin' => $w->getKickoffMin()->format('H:i'),
                    'kickoffMax' => $w->getKickoffMax()->format('H:i'),
                ], $windows),
                'fixture' => $this->bareFixtureView($fixture),
            ];
        }

        return $conflicts;
    }

    /**
     * Severity 4 (dette ii) — a placed HOME fixture whose kickoff no longer sits
     * in any access window of (venue, weekday): the window moved AFTER the
     * placement. PANEL rule mirrored exactly (kickoff point, half-open end,
     * no window anywhere = data not adopted = nothing to enforce).
     *
     * @param list<Fixture>          $fixtures
     * @param list<VenueMatchWindow> $matchWindows
     *
     * @return list<array<string, mixed>>
     */
    private function accessWindowLostConflicts(array $fixtures, array $matchWindows): array
    {
        if ([] === $matchWindows) {
            return [];
        }

        $conflicts = [];
        foreach ($fixtures as $fixture) {
            $venueId = $fixture->getVenueId();
            $kickoffTime = $fixture->getKickoffTime();
            if (FixtureHomeAway::HOME !== $fixture->getHomeAway() || null === $venueId || !$kickoffTime instanceof DateTimeImmutable) {
                continue;
            }
            $day = (int) $fixture->getMatchDate()->format('N');
            $kickoff = $kickoffTime->format('H:i');
            $windowArrays = array_map(static fn (VenueMatchWindow $w): array => [
                'venueId' => $w->getVenueId(),
                'dayOfWeek' => $w->getDayOfWeek(),
                'startTime' => $w->getStartTime()->format('H:i'),
                'endTime' => $w->getEndTime()->format('H:i'),
            ], $matchWindows);
            if (self::kickoffInsideWindow($venueId, $day, $kickoff, $windowArrays)) {
                continue;
            }
            $conflicts[] = [
                'type' => 'ACCESS_WINDOW_LOST',
                'severity' => 4,
                'venueId' => $venueId,
                'fixture' => $this->bareFixtureView($fixture),
            ];
        }

        return $conflicts;
    }

    /**
     * Severity 7 (dette v, info) — an AWAY fixture with no hour and no habit on
     * its weekday: no footprint, so the radar is BLIND to it. Named so the
     * manager declares a habit instead of trusting a silence.
     *
     * @param list<Fixture>                             $fixtures
     * @param array<string, array<int, TeamMatchHabit>> $habitByTeamDay
     *
     * @return list<array<string, mixed>>
     */
    private function awayNoFootprintItems(array $fixtures, array $habitByTeamDay): array
    {
        $items = [];
        foreach ($fixtures as $fixture) {
            if (FixtureHomeAway::AWAY !== $fixture->getHomeAway() || $fixture->getKickoffTime() instanceof DateTimeImmutable) {
                continue;
            }
            $day = (int) $fixture->getMatchDate()->format('N');
            if (isset($habitByTeamDay[$fixture->getTeamId()][$day])) {
                continue;
            }
            $items[] = [
                'type' => 'AWAY_NO_FOOTPRINT',
                'severity' => 7,
                'fixture' => $this->bareFixtureView($fixture),
            ];
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function bareFixtureView(Fixture $fixture): array
    {
        return [
            'fixtureId' => $fixture->getId(),
            'teamId' => $fixture->getTeamId(),
            'homeAway' => $fixture->getHomeAway()->value,
            'matchDate' => $fixture->getMatchDate()->format('Y-m-d'),
            'kickoffTime' => $fixture->getKickoffTime()?->format('H:i'),
            'status' => $fixture->getStatus()->value,
        ];
    }

    /**
     * Two fixtures of two DECLARED-linked teams overlapping (NOT_SIMULTANEOUS
     * only — BACK_TO_BACK is a solver preference, diagnosing its non-respect
     * without a solver would invent a rule). Coach-independent.
     *
     * @param list<array{fixture: Fixture, window: array{start: DateTimeImmutable, end: DateTimeImmutable}, estimated: bool, coachIds: list<string>}> $views
     * @param list<TeamLink>                                                                                                                          $teamLinks
     *
     * @return list<array<string, mixed>>
     */
    private function teamLinkConflicts(array $views, array $teamLinks): array
    {
        if ([] === $teamLinks) {
            return [];
        }

        /** @var array<string, list<array{fixture: Fixture, window: array{start: DateTimeImmutable, end: DateTimeImmutable}, estimated: bool}>> $byTeam */
        $byTeam = [];
        foreach ($views as $view) {
            $byTeam[$view['fixture']->getTeamId()][] = $view;
        }

        $conflicts = [];
        foreach ($teamLinks as $link) {
            if (TeamLinkType::NOT_SIMULTANEOUS !== $link->getLinkType()) {
                continue;
            }
            foreach ($byTeam[$link->getTeamAId()] ?? [] as $left) {
                foreach ($byTeam[$link->getTeamBId()] ?? [] as $right) {
                    if (!$this->overlaps($left['window'], $right['window'])) {
                        continue;
                    }
                    $conflicts[] = [
                        'type' => 'TEAM_LINK_OVERLAP',
                        'severity' => 5,
                        'teamLinkId' => $link->getId(),
                        'start' => $this->maxMoment($left['window']['start'], $right['window']['start'])->format(DateTimeImmutable::ATOM),
                        'end' => $this->minMoment($left['window']['end'], $right['window']['end'])->format(DateTimeImmutable::ATOM),
                        'left' => $this->fixtureView($left['fixture'], $left['window'], $left['estimated']),
                        'right' => $this->fixtureView($right['fixture'], $right['window'], $right['estimated']),
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * A fixture sitting on a venue that is unavailable on its date. No window
     * math: the closure is all-circumstances, the DATE match suffices (a
     * kickoff-less home fixture with a venue is affected too).
     *
     * @param list<Fixture>             $fixtures
     * @param list<VenueUnavailability> $unavailabilities
     *
     * @return list<array<string, mixed>>
     */
    private function venueUnavailableConflicts(array $fixtures, array $unavailabilities): array
    {
        if ([] === $unavailabilities) {
            return [];
        }

        $conflicts = [];
        foreach ($fixtures as $fixture) {
            $venueId = $fixture->getVenueId();
            if (null === $venueId) {
                continue;
            }
            $date = $fixture->getMatchDate()->format('Y-m-d');
            foreach ($unavailabilities as $unavailability) {
                if ($unavailability->getVenueId() !== $venueId) {
                    continue;
                }
                // Inclusive bounds: « du 4 au 28 février » covers the 28th.
                if ($date < $unavailability->getStartDate()->format('Y-m-d') || $date > $unavailability->getEndDate()->format('Y-m-d')) {
                    continue;
                }
                $conflicts[] = [
                    'type' => 'VENUE_UNAVAILABLE',
                    'severity' => 4,
                    'venueId' => $venueId,
                    'unavailabilityId' => $unavailability->getId(),
                    'label' => $unavailability->getLabel(),
                    'unavailableFrom' => $unavailability->getStartDate()->format('Y-m-d'),
                    'unavailableUntil' => $unavailability->getEndDate()->format('Y-m-d'),
                    'fixture' => [
                        'fixtureId' => $fixture->getId(),
                        'teamId' => $fixture->getTeamId(),
                        'homeAway' => $fixture->getHomeAway()->value,
                        'matchDate' => $date,
                        'kickoffTime' => $fixture->getKickoffTime()?->format('H:i'),
                        'status' => $fixture->getStatus()->value,
                    ],
                ];
            }
        }

        return $conflicts;
    }

    /**
     * @param list<array{fixture: Fixture, window: array{start: DateTimeImmutable, end: DateTimeImmutable}, estimated: bool, coachIds: list<string>}> $views
     * @param array<string, array<string, TeamCoachRole>>                                                                                             $rolesByTeam
     *
     * @return list<array<string, mixed>>
     */
    private function matchMatchConflicts(array $views, array $rolesByTeam): array
    {
        $conflicts = [];
        $count = \count($views);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $left = $views[$i];
                $right = $views[$j];
                if (!$this->overlaps($left['window'], $right['window'])) {
                    continue;
                }
                // A coach shared by both fixtures' teams is double-booked.
                foreach (array_intersect($left['coachIds'], $right['coachIds']) as $coachId) {
                    $role = $this->worstRole($rolesByTeam, $coachId, [$left['fixture']->getTeamId(), $right['fixture']->getTeamId()]);
                    $conflicts[] = [
                        'type' => 'MATCH_MATCH',
                        'severity' => TeamCoachRole::MAIN === $role ? 3 : 5,
                        'coachRole' => $role->value,
                        'coachId' => $coachId,
                        'start' => $this->maxMoment($left['window']['start'], $right['window']['start'])->format(DateTimeImmutable::ATOM),
                        'end' => $this->minMoment($left['window']['end'], $right['window']['end'])->format(DateTimeImmutable::ATOM),
                        'left' => $this->fixtureView($left['fixture'], $left['window'], $left['estimated']),
                        'right' => $this->fixtureView($right['fixture'], $right['window'], $right['estimated']),
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * @param list<array{fixture: Fixture, window: array{start: DateTimeImmutable, end: DateTimeImmutable}, estimated: bool, coachIds: list<string>}> $views
     * @param array<string, array<string, true>>                                                                                                      $coachesByTeam
     * @param array<string, array<string, TeamCoachRole>>                                                                                             $rolesByTeam
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}>                                                  $activePeriods
     * @param array<string, list<ScheduleSlotTemplate>>                                                                                               $slotsBySchedule
     *
     * @return list<array<string, mixed>>
     */
    private function matchTrainingConflicts(
        array $views,
        array $coachesByTeam,
        array $rolesByTeam,
        ?string $seasonScheduleId,
        array $activePeriods,
        array $slotsBySchedule,
    ): array {
        $conflicts = [];
        foreach ($views as $view) {
            // A footprint can cross midnight (late kickoff) — check every calendar
            // day it spans, each resolving its own effective schedule + weekday.
            foreach ($this->spannedDates($view['window']) as $date) {
                $scheduleId = $this->effectiveScheduleResolver->resolve($date, $activePeriods, $seasonScheduleId);
                if (null === $scheduleId) {
                    continue;
                }
                $isoWeekday = (int) $date->format('N');

                foreach ($slotsBySchedule[$scheduleId] ?? [] as $slot) {
                    if ($slot->getDayOfWeek() !== $isoWeekday) {
                        continue;
                    }
                    // Who actually runs the training: the slot's assigned coach if
                    // any, else any coach of the slot's team. Intersect with this
                    // fixture's coaches — only a coach on BOTH sides is double-booked.
                    $slotCoach = $slot->getCoachId();
                    $trainingCoachIds = null !== $slotCoach
                        ? [$slotCoach]
                        : array_keys($coachesByTeam[$slot->getTeamId()] ?? []);
                    $coachIds = array_values(array_intersect($view['coachIds'], $trainingCoachIds));
                    if ([] === $coachIds) {
                        continue;
                    }

                    $trainingWindow = $this->slotWindowOnDate($date, $slot);
                    if (!$this->overlaps($view['window'], $trainingWindow)) {
                        continue;
                    }

                    foreach ($coachIds as $coachId) {
                        $role = $this->worstRole($rolesByTeam, $coachId, [$view['fixture']->getTeamId(), $slot->getTeamId()]);
                        $conflicts[] = [
                            'type' => 'MATCH_TRAINING',
                            'severity' => TeamCoachRole::MAIN === $role ? 3 : 5,
                            'coachRole' => $role->value,
                            'coachId' => $coachId,
                            'start' => $this->maxMoment($view['window']['start'], $trainingWindow['start'])->format(DateTimeImmutable::ATOM),
                            'end' => $this->minMoment($view['window']['end'], $trainingWindow['end'])->format(DateTimeImmutable::ATOM),
                            'fixture' => $this->fixtureView($view['fixture'], $view['window'], $view['estimated']),
                            'training' => [
                                'slotTemplateId' => $slot->getId(),
                                'scheduleId' => $slot->getScheduleId(),
                                'teamId' => $slot->getTeamId(),
                                'venueId' => $slot->getVenueId(),
                                'dayOfWeek' => $slot->getDayOfWeek(),
                                'startTime' => $slot->getStartTime()->format('H:i'),
                                'durationMinutes' => $slot->getDurationMinutes(),
                                'windowStart' => $trainingWindow['start']->format(DateTimeImmutable::ATOM),
                                'windowEnd' => $trainingWindow['end']->format(DateTimeImmutable::ATOM),
                            ],
                        ];
                    }
                }
            }
        }

        return $conflicts;
    }

    /**
     * The calendar days a window touches — its start day, plus its end day when
     * the window crosses midnight (footprints are short, so at most two days).
     *
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable} $window
     *
     * @return list<DateTimeImmutable> midnight of each spanned day
     */
    private function spannedDates(array $window): array
    {
        $startDay = $window['start']->setTime(0, 0);
        $endDay = $window['end']->setTime(0, 0);
        if ($startDay->format('Y-m-d') === $endDay->format('Y-m-d')) {
            return [$startDay];
        }

        return [$startDay, $endDay];
    }

    /**
     * Project a weekly recurring slot onto a concrete date (the caller has
     * already matched the ISO weekday).
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}
     */
    private function slotWindowOnDate(DateTimeImmutable $date, ScheduleSlotTemplate $slot): array
    {
        $slotStart = $slot->getStartTime();
        $start = $date->setTime((int) $slotStart->format('H'), (int) $slotStart->format('i'));

        return ['start' => $start, 'end' => $start->add(new DateInterval('PT' . $slot->getDurationMinutes() . 'M'))];
    }

    /**
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable} $a
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable} $b
     */
    private function overlaps(array $a, array $b): bool
    {
        // Half-open: back-to-back windows (endA == startB) do NOT conflict.
        return $a['start'] < $b['end'] && $b['start'] < $a['end'];
    }

    /**
     * MAIN on ANY involved team beats ASSISTANT — the worst engagement counts.
     *
     * @param array<string, array<string, TeamCoachRole>> $rolesByTeam
     * @param list<string>                                $teamIds
     */
    private function worstRole(array $rolesByTeam, string $coachId, array $teamIds): TeamCoachRole
    {
        foreach ($teamIds as $teamId) {
            if (TeamCoachRole::MAIN === ($rolesByTeam[$teamId][$coachId] ?? null)) {
                return TeamCoachRole::MAIN;
            }
        }

        return TeamCoachRole::ASSISTANT;
    }

    private function maxMoment(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable
    {
        return $a >= $b ? $a : $b;
    }

    private function minMoment(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable
    {
        return $a <= $b ? $a : $b;
    }

    /**
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable} $window
     *
     * @return array<string, mixed>
     */
    private function fixtureView(Fixture $fixture, array $window, bool $estimated = false): array
    {
        return [
            'fixtureId' => $fixture->getId(),
            'teamId' => $fixture->getTeamId(),
            'homeAway' => $fixture->getHomeAway()->value,
            'matchDate' => $fixture->getMatchDate()->format('Y-m-d'),
            'kickoffTime' => $fixture->getKickoffTime()?->format('H:i'),
            // P1-4 PR C — the window was built on the team's HABITUAL kickoff,
            // not a real hour: the UI must say « heure estimée ».
            'estimatedKickoff' => $estimated,
            'windowStart' => $window['start']->format(DateTimeImmutable::ATOM),
            'windowEnd' => $window['end']->format(DateTimeImmutable::ATOM),
        ];
    }
}
