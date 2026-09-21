<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CoachPlayerMembership;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\LeagueMatchWindow;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\TeamCoach;
use App\Entity\TeamMatchHabit;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueUnavailability;
use App\Enum\ConflictPersonRole;
use App\Enum\FixtureHomeAway;
use DateInterval;
use DateTimeImmutable;

/**
 * Detects, on the fly, the time-occupancy conflicts a SINGLE coach faces in a
 * season (spec gestion-matchs palier A, PR-2). In an amateur club a match and a
 * training can NEVER overlap for the same person — this surfaces the clash as
 * early as the fixture is entered (the anticipation value of the module).
 *
 * A « person » is a coach OR a player: the occupancy map unions coach
 * engagements (`team_coach`: MAIN/ASSISTANT) with player engagements (active
 * {@see CoachPlayerMembership}), so a player who plays SM2 while she coaches SF2
 * is double-booked exactly like a coach on two teams. Each side of a person
 * conflict carries its {@see ConflictPersonRole} on THAT team (`role`), and the
 * aggregate `coachRole` (kept for compat) is MAIN when every side is MAIN,
 * ASSISTANT as soon as one side is ASSISTANT, PLAYER otherwise.
 *
 * Conflict kinds. The person families are built on {@see MatchFootprint}
 * PERSON-CONFLICT windows:
 * - MATCH_MATCH: two fixtures of teams sharing a person whose PERSON-CONFLICT
 *   windows overlap. ⚠ Lot M (cas Inès 2026-10-10, « pas de conflit du tout, et
 *   même règle pour le coach ») — the warm-up NEVER counts for a person clash,
 *   WHATEVER the gym: the shared person only has to ARRIVE by the SECOND kickoff,
 *   the warm-up is hers to skip. So each side's window is its
 *   {@see MatchFootprint::personConflictOccupancy} (occupancy MINUS the leading
 *   warm-up — travel kept). Arrival exactly at the second kickoff = no conflict
 *   (half-open overlap). This SUBSUMES the old « même gymnase à domicile »
 *   exception (2026-09-17): two home matches always drop their warm-up now, not
 *   only in one shared gym. Sides are ordered CHRONOLOGICALLY (earliest full
 *   window start on the left); the fingerprint sorts the pair anyway, so the view
 *   order is free. The per-side windowStart/windowEnd still serve the FULL person
 *   windows (warm-up included) — only the overlap TEST and the served start/end
 *   use the conflict windows.
 * - MATCH_TRAINING: a fixture overlapping a training of one of the person's teams,
 *   read from the schedule EFFECTIVE on the match date (rule extracted to
 *   {@see EffectiveScheduleResolver} — P1-4 PR B). A footprint crossing midnight
 *   is checked against BOTH calendar days it spans. ⚠ D1 (2026-09-13), EXTENDED
 *   to players: a training of the fixture's OWN team is skipped for its coaches
 *   AND its players (the players who play don't also train); a SISTER team's
 *   training (coach or player on two teams) still clashes. When the slot has an
 *   assigned coach, he replaces the OTHER coaches of the slot's team (anti false-
 *   positive) but NEVER evicts its players. ⚠ Lot M — the MATCH side uses its
 *   PERSON-CONFLICT window (warm-up dropped, whatever the gym), so a warm-up-only
 *   overlap with a session the person reaches by kickoff is silent; a real
 *   overlap (the session runs past the kickoff) still clashes. This subsumes the
 *   old same-gym « second match » special case (2026-09-17).
 * - VENUE_UNAVAILABLE (P1-4 PR B): a fixture whose venue is unavailable on its
 *   date (all-circumstances closure posed on the club calendar AFTER the match
 *   was placed — the real-life case the placement guard cannot catch). Coach-
 *   independent, kickoff-independent: the DATE falling in the range suffices.
 *
 * ⚠ Lot M — the TEAM_LINK family (declared bridges « SM1 et SM2 partagent des
 * joueurs ») has LEFT the radar entirely (founder decision : « pour l'instant ça
 * fait plus de bruit qu'autre chose »). The PLACEMENT solver KEEPS its soft
 * NOT_SIMULTANEOUS preference (a deliberate, safe asymmetry: a soft preference
 * never blocks anything) — see {@see MatchPlacementPayloadBuilder}. The detector
 * no longer even loads team links.
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
 * never re-derives gravity. Person findings grade by the PER-SIDE roles:
 * - MATCH_MATCH: severity 3 for MAIN×MAIN, MAIN×PLAYER and PLAYER×PLAYER; a
 *   single ASSISTANT engagement on either side softens it to 5 (P4-189) — a
 *   helper can hold that side.
 * - MATCH_TRAINING: severity 3 (match played × training coached MAIN, or
 *   match played × training played), EXCEPT a match COACHED (MAIN) against a
 *   training where she only PLAYS → 5, and any ASSISTANT side → 5.
 * New finding kinds:
 * - VENUE_OVERLAP (1): two placed fixtures, same venue, overlapping VENUE windows
 *   ([kickoff, kickoff + match], no warm-up — D1, 2026-09-13, so two matches
 *   chained two hours apart do not false-alarm) — the manual loop never blocks a
 *   collision (founder decision), the diagnostic screams instead.
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
 * - FRIENDLY_ON_MATCH_SLOT (5, « À surveiller », P4-193): a placed HOME FRIENDLY
 *   (competitionId null, venue+kickoff) sitting on a match slot — its footprint
 *   overlaps a match access window of its gym (reason MATCH_SLOT_WINDOW) and/or
 *   its date is a Sat/Sun of a weekend where the club plays a non-friendly match
 *   (reason MATCH_WEEKEND). The solver no longer places friendlies and their
 *   manual placement is FREE — this ALERTS, it never blocks (founder decision).
 *
 * Pure/stateless: the controller loads the scoped data and passes it in; this
 * class only crosses and overlaps, so it is unit-testable without a kernel.
 *
 * PAST matches (D1 rule 3, 2026-09-13): a fixture already played neither ports
 * nor receives a conflict. `detect()` takes the club's civil today ({@see ClubDay},
 * injected by both callers) and filters matchDate < today out of every family
 * EXCEPT COMPETITION_INCOMPLETE (whose counts stay complete) and the match-weekend
 * index of FRIENDLY_ON_MATCH_SLOT (a past Saturday championship still marks its
 * Sunday). A null today (the pure test path) disables the filter.
 *
 * Away travel IS modelled (P2-54 RMM-9 PR-3): an away footprint grows by the
 * round-trip car time when the opponent's travel is known (0 otherwise). An away
 * fixture with no real hour and no estimated kickoff still has no footprint and
 * therefore raises no conflict — named instead by AWAY_NO_FOOTPRINT.
 *
 * @phpstan-type OccupancyWindow array{start: DateTimeImmutable, end: DateTimeImmutable}
 * @phpstan-type FixtureView array{fixture: Fixture, window: OccupancyWindow, venueWindow: OccupancyWindow, conflictWindow: OccupancyWindow, estimated: bool, estimatedKickoffTime: string|null, travelOneWayMinutes: int|null, matchDurationMinutes: int, personIds: list<string>}
 */
final class MatchConflictDetector
{
    /**
     * Occupancy bounds are the club's WALL-CLOCK time, carried WITHOUT an
     * offset (P4-191): `2026-10-03T15:00:00`, not the ATOM `…+02:00`. A match
     * and a training are wall-clock facts of one club — appending the server
     * offset let a browser in another zone shift « 20:45 » by an hour. The UI
     * parses these as local and re-formats them, so no offset must ride along.
     * Only these datetime bounds use it; `matchDate` (Y-m-d) and `kickoffTime`
     * (H:i) are unaffected.
     */
    private const string WALL_CLOCK_FORMAT = 'Y-m-d\TH:i:s';

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
     * LE prédicat pur d'enveloppe de LIGUE — le coup d'envoi (H:i) tombe-t-il dans une
     * fenêtre autorisée par la ligue ce JOUR ? Intervalle FERMÉ `[kickoffMin, kickoffMax]`
     * (les deux bornes incluses — la ligue autorise le dernier coup d'envoi), à distinguer
     * du demi-ouvert de {@see kickoffInsideWindow} (accès gymnase). Extrait pour la parité
     * MÉCANIQUE avec le front (`matches/lib/envelope.ts::kickoffInsideLeagueWindow`, cas
     * partagés `leagueEnvelope.parity.json`, gardés par `LeagueEnvelopeMirrorParityTest`) :
     * le front l'utilise pour BLOQUER la pose (via `isInEnvelope`), le backend pour
     * DIAGNOSTIQUER (LEAGUE_WINDOW_VIOLATION). L'exemption AMICAL et la résolution
     * équipe↔fenêtre divergent (déclaré côté front) ; cette algèbre-ci ne doit pas.
     *
     * @param list<array{dayOfWeek: int, kickoffMin: string, kickoffMax: string}> $windows
     */
    public static function kickoffInsideLeagueWindow(int $day, string $kickoff, array $windows): bool
    {
        foreach ($windows as $window) {
            if ($window['dayOfWeek'] === $day
                && $kickoff >= $window['kickoffMin']
                && $kickoff <= $window['kickoffMax']
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Fixture>                                                                          $fixtures             season fixtures (already club+season scoped)
     * @param list<TeamCoach>                                                                        $teamCoachRows        coach↔team links (scoped)
     * @param string|null                                                                            $seasonScheduleId     the season's calendar (the version its plan points at), or null
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}> $activePeriods
     *                                                                                                                     active period windows (ordered), scheduleId = their overlay or null
     * @param array<string, list<ScheduleSlotTemplate>>                                              $slotsBySchedule      slots indexed by their scheduleId
     * @param list<VenueUnavailability>                                                              $unavailabilities     scoped all-circumstances closures
     * @param list<TeamMatchHabit>                                                                   $habits               scoped habitual windows (estimation source)
     * @param list<VenueMatchWindow>                                                                 $matchWindows         scoped access windows (ACCESS_WINDOW_LOST)
     * @param array<string, list<LeagueMatchWindow>>                                                 $envelope             teamId → resolved league windows ([] = unmapped)
     * @param list<Competition>                                                                      $competitions         scoped competitions (COMPETITION_INCOMPLETE — severity 6)
     * @param array<string, MatchDurationProfile>                                                    $profilesByTeam       teamId → match duration profile (P2-54 RMM-9); a team absent falls back to MatchDurationProfile::fallback()
     * @param array<string, int>                                                                     $roundTripByFixtureId
     *                                                                                                                     fixtureId → round-trip car travel minutes (P2-54 RMM-9 PR-3, AWAY only); absent = 0
     *                                                                                                                     (no travel modelled → no spatial extension of the footprint). The controller projects it
     *                                                                                                                     via OpponentTravelProjection (link gym → constant travel cache, 2 × one-way), the detector stays pure.
     * @param DateTimeImmutable|null                                                                 $clubToday
     *                                                                                                                     the club's civil today ({@see ClubDay}); a fixture whose matchDate is strictly BEFORE
     *                                                                                                                     it is already played — it neither PORTS nor RECEIVES a conflict (D1, rule 3). null
     *                                                                                                                     (the pure test path) disables the filter entirely.
     * @param list<CoachPlayerMembership>                                                            $playerMemberships
     *                                                                                                                     scoped coach↔team PLAYER links; only the active ones count. A person is a PLAYER of
     *                                                                                                                     that team UNLESS she already coaches it (the coach role then wins). Both callers load
     *                                                                                                                     and pass these (parité MatchVisitDeltaParityTest).
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
        array $matchWindows = [],
        array $envelope = [],
        array $competitions = [],
        array $profilesByTeam = [],
        array $roundTripByFixtureId = [],
        ?DateTimeImmutable $clubToday = null,
        array $playerMemberships = [],
    ): array {
        // Two person maps by team. Coaches carry a role (MAIN/ASSISTANT, worst
        // engagement wins, cadrage §8); active players carry PLAYER. Kept apart
        // because the training side reads coaches and players differently (an
        // assigned slot coach replaces the other COACHES, never the players).
        $coachesByTeam = [];
        $playersByTeam = [];
        // teamId → personId → ConflictPersonRole, the SINGLE per-side role: the
        // coach role wins over PLAYER (a MAIN who also plays is graded MAIN), and
        // its keys are the union of coaches and active players of the team.
        $roleByTeamPerson = [];
        foreach ($teamCoachRows as $link) {
            $coachesByTeam[$link->getTeamId()][$link->getCoachId()] = true;
            $role = ConflictPersonRole::from($link->getRole()->value);
            $current = $roleByTeamPerson[$link->getTeamId()][$link->getCoachId()] ?? null;
            if (ConflictPersonRole::MAIN !== $current) {
                $roleByTeamPerson[$link->getTeamId()][$link->getCoachId()] = $role;
            }
        }
        foreach ($playerMemberships as $membership) {
            if (!$membership->getIsActive()) {
                continue;
            }
            $playersByTeam[$membership->getTeamId()][$membership->getCoachId()] = true;
            // The coach role wins: only stamp PLAYER where no coach engagement
            // already grades this person on this team.
            if (!isset($roleByTeamPerson[$membership->getTeamId()][$membership->getCoachId()])) {
                $roleByTeamPerson[$membership->getTeamId()][$membership->getCoachId()] = ConflictPersonRole::PLAYER;
            }
        }

        $habitByTeamDay = $this->awayKickoffEstimator->indexHabits($habits);

        // D1 rule 3 (founder decision 2026-09-13) — a match already PLAYED must
        // neither port nor receive a conflict. The active list (matchDate >=
        // clubToday) feeds every family EXCEPT competitionIncompleteItems (its
        // counts stay complete) and the match-weekend index of
        // friendlyOnMatchSlotConflicts (a PAST Saturday championship still makes
        // its Sunday a « match weekend »), which both read the FULL list. A null
        // clubToday (the pure test path) disables the filter. Compared as Y-m-d
        // strings: matchDate is a civil date, no timezone must sneak in.
        $today = $clubToday?->format('Y-m-d');
        $activeFixtures = null === $today
            ? $fixtures
            : array_values(array_filter(
                $fixtures,
                static fn (Fixture $fixture): bool => $fixture->getMatchDate()->format('Y-m-d') >= $today,
            ));

        // Fixtures with a footprint: a real kickoff, or (P1-4 PR C) an AWAY
        // fixture borrowing its team's habitual kickoff for the match weekday
        // (rule extracted to AwayKickoffEstimator — the placement payload
        // consumes the SAME estimation, PR D). Each view carries BOTH the PERSON
        // window (warm-up + match + travel — coach/link families) and the VENUE
        // window (match only — the gym-collision families). Coaches attached for
        // the coach conflicts; a coach-less fixture still gets a view (team links
        // don't need a coach).
        $views = [];
        foreach ($activeFixtures as $fixture) {
            // P2-54 RMM-9 — the footprint durations now depend on the team's
            // category profile; a team missing from the map falls back to the
            // documented 105/30 (MatchDurationProfile::fallback()).
            $profile = $profilesByTeam[$fixture->getTeamId()] ?? MatchDurationProfile::fallback();
            // P2-54 RMM-9 PR-3 — the AWAY footprint gains the round-trip travel leg
            // (0 when the opponent has no known travel). Home fixtures ignore it
            // (MatchFootprint only adds the leg for AWAY).
            $roundTrip = $roundTripByFixtureId[$fixture->getId()] ?? 0;
            $estimated = false;
            // P2-54 conflict side details — the estimated kickoff « HH:MM » held for
            // the per-side rendering, non-null ONLY when the window borrowed a habit.
            $estimatedKickoffTime = null;
            $window = $this->footprint->occupancy($fixture, $profile, $roundTrip);
            $venueWindow = $this->footprint->venueOccupancy($fixture, $profile);
            // Lot M — the PERSON-conflict window (warm-up dropped, travel kept):
            // the families of PERSON overlap on THIS window, so the warm-up never
            // false-alarms a clash the shared person can reach by kickoff.
            $conflictWindow = $this->footprint->personConflictOccupancy($fixture, $profile, $roundTrip);
            if (null === $window) {
                $estimatedKickoff = $this->awayKickoffEstimator->estimate($fixture, $habitByTeamDay);
                if ($estimatedKickoff instanceof DateTimeImmutable) {
                    $window = $this->footprint->occupancyAt($fixture, $estimatedKickoff, $profile, $roundTrip);
                    $venueWindow = $this->footprint->venueOccupancyAt($fixture, $estimatedKickoff, $profile);
                    $conflictWindow = $this->footprint->personConflictOccupancyAt($fixture, $estimatedKickoff, $profile, $roundTrip);
                    $estimated = true;
                    $estimatedKickoffTime = $estimatedKickoff->format('H:i');
                }
            }
            // The three windows share the same kickoff source, so they are non-null
            // together; the guards keep PHPStan honest about it (split so each narrows,
            // Rector leaves a two-term OR alone).
            if (null === $window || null === $venueWindow) {
                continue;
            }
            if (null === $conflictWindow) {
                continue;
            }
            // P2-54 conflict side details — the ONE-WAY travel leg (round trip / 2)
            // for the per-side rendering. null = NOT modelled (the fixture carries no
            // row in the projected map) so the UI says « trajet inconnu » rather than
            // « 0 min » ; the footprint above still gets `?? 0`. Always null on HOME
            // (the controller only projects AWAY rows), guarded here so the contract
            // stays local to this class.
            $travelOneWayMinutes = FixtureHomeAway::AWAY === $fixture->getHomeAway() && \array_key_exists($fixture->getId(), $roundTripByFixtureId)
                ? intdiv($roundTripByFixtureId[$fixture->getId()], 2)
                : null;
            $views[] = [
                'fixture' => $fixture,
                'window' => $window,
                'venueWindow' => $venueWindow,
                'conflictWindow' => $conflictWindow,
                'estimated' => $estimated,
                'estimatedKickoffTime' => $estimatedKickoffTime,
                'travelOneWayMinutes' => $travelOneWayMinutes,
                'matchDurationMinutes' => $profile->matchMinutes,
                // The PERSONS of the fixture's team (coaches ∪ active players) —
                // the keys of the per-team role map are exactly that union.
                'personIds' => array_keys($roleByTeamPerson[$fixture->getTeamId()] ?? []),
            ];
        }
        $personViews = array_values(array_filter($views, static fn (array $view): bool => [] !== $view['personIds']));

        return [
            ...$this->venueOverlapConflicts($views),
            ...$this->leagueWindowViolations($activeFixtures, $envelope),
            ...$this->matchMatchConflicts($personViews, $roleByTeamPerson),
            ...$this->matchTrainingConflicts($personViews, $coachesByTeam, $playersByTeam, $roleByTeamPerson, $seasonScheduleId, $activePeriods, $slotsBySchedule),
            ...$this->venueUnavailableConflicts($activeFixtures, $unavailabilities),
            ...$this->accessWindowLostConflicts($activeFixtures, $matchWindows),
            ...$this->competitionIncompleteItems($fixtures, $competitions),
            ...$this->awayNoFootprintItems($activeFixtures, $habitByTeamDay),
            ...$this->friendlyOnMatchSlotConflicts($views, $fixtures, $matchWindows),
        ];
    }

    /**
     * Severity 5 (« À surveiller ») — a placed HOME FRIENDLY (competitionId null,
     * venue + kickoff) sitting on a match slot. The solver no longer places
     * friendlies (P4-193) and their manual placement is FREE: this ALERTS, it
     * never blocks (founder decision, 2026-09-10). ONE item per fixture, carrying
     * the `reasons` that triggered it (MATCH_SLOT_WINDOW, MATCH_WEEKEND):
     * - MATCH_SLOT_WINDOW: the match VENUE window ([kickoff, kickoff + match], no
     *   warm-up — D1, 2026-09-13) overlaps a VenueMatchWindow of the SAME gym,
     *   projected onto the ISO weekday of the date. ⚠ Divergence ASSUMÉE with the
     *   kickoffInsideWindow rule of ACCESS_WINDOW_LOST (patron ci-dessus): there we
     *   test whether the KICKOFF POINT falls inside the window; HERE we overlap the
     *   whole gym occupancy — a friendly whose match still bites into the window
     *   alerts. Warm-up is EXCLUDED though: a friendly whose gym window sits clear
     *   of the access window (only its warm-up would have touched it) stays silent.
     *   `venueId` is carried for this branch.
     * - MATCH_WEEKEND: the date is a Saturday or Sunday and the club has ≥ 1
     *   NON-friendly fixture (HOME or AWAY) on the Saturday OR the Sunday of the
     *   same weekend (key = the Saturday's date; Friday does NOT count — founder
     *   decision).
     *
     * @param list<FixtureView>      $views
     * @param list<Fixture>          $fixtures
     * @param list<VenueMatchWindow> $matchWindows
     *
     * @return list<array<string, mixed>>
     */
    private function friendlyOnMatchSlotConflicts(array $views, array $fixtures, array $matchWindows): array
    {
        // Weekends (key = the Saturday's date) where a NON-friendly match is
        // played, on the Saturday or the Sunday. Friday does not count.
        $matchWeekends = [];
        foreach ($fixtures as $fixture) {
            if (null === $fixture->getCompetitionId()) {
                continue;
            }
            $weekendKey = $this->weekendSaturdayKey($fixture->getMatchDate());
            if (null !== $weekendKey) {
                $matchWeekends[$weekendKey] = true;
            }
        }

        $conflicts = [];
        foreach ($views as $view) {
            $fixture = $view['fixture'];
            if (null !== $fixture->getCompetitionId()
                || FixtureHomeAway::HOME !== $fixture->getHomeAway()
                || null === $fixture->getVenueId()
                || !$fixture->getKickoffTime() instanceof DateTimeImmutable
            ) {
                continue;
            }

            $reasons = [];
            // Fenêtre : la fenêtre SALLE (sans échauffement, D1) chevauche une fenêtre
            // du même gymnase, projetée sur le jour ISO de la date (chevauchement, pas
            // appartenance du kickoff).
            $day = (int) $fixture->getMatchDate()->format('N');
            foreach ($matchWindows as $window) {
                if ($window->getVenueId() !== $fixture->getVenueId() || $window->getDayOfWeek() !== $day) {
                    continue;
                }
                if ($this->overlaps($view['venueWindow'], $this->matchWindowOnDate($fixture->getMatchDate(), $window))) {
                    $reasons[] = 'MATCH_SLOT_WINDOW';

                    break;
                }
            }
            // Week-end de match.
            $weekendKey = $this->weekendSaturdayKey($fixture->getMatchDate());
            if (null !== $weekendKey && isset($matchWeekends[$weekendKey])) {
                $reasons[] = 'MATCH_WEEKEND';
            }

            if ([] === $reasons) {
                continue;
            }

            $conflict = [
                'type' => 'FRIENDLY_ON_MATCH_SLOT',
                'severity' => 5,
                'reasons' => $reasons,
                'fixture' => $this->bareFixtureView($fixture),
            ];
            if (\in_array('MATCH_SLOT_WINDOW', $reasons, true)) {
                $conflict['venueId'] = $fixture->getVenueId();
            }
            $conflicts[] = $conflict;
        }

        return $conflicts;
    }

    /**
     * The Saturday date (Y-m-d) of the weekend a Sat/Sun date belongs to, or null
     * for Monday-Friday (a weekday match never triggers the MATCH_WEEKEND reason).
     */
    private function weekendSaturdayKey(DateTimeImmutable $date): ?string
    {
        return match ((int) $date->format('N')) {
            6 => $date->format('Y-m-d'),
            7 => $date->modify('-1 day')->format('Y-m-d'),
            default => null,
        };
    }

    /**
     * Project a match access window onto a concrete date (the caller has already
     * matched the ISO weekday).
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}
     */
    private function matchWindowOnDate(DateTimeImmutable $date, VenueMatchWindow $window): array
    {
        $start = $window->getStartTime();
        $end = $window->getEndTime();

        return [
            'start' => $date->setTime((int) $start->format('H'), (int) $start->format('i')),
            'end' => $date->setTime((int) $end->format('H'), (int) $end->format('i')),
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
     * Severity 1 — two fixtures on the SAME venue whose VENUE windows overlap.
     * The manual loop lets this happen on purpose (a derogation or the league
     * can impose it); the diagnostic makes it the loudest finding instead. ⚠ D1
     * (2026-09-13): the collision is tested on the VENUE window ([kickoff,
     * kickoff + match], no warm-up) — two matches chained two hours apart in the
     * same gym must NOT collide on their inflated person footprints. The served
     * `start`/`end` are therefore the intersection of the VENUE windows.
     *
     * @param list<FixtureView> $views
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
                if ($left['fixture']->getVenueId() !== $right['fixture']->getVenueId() || !$this->overlaps($left['venueWindow'], $right['venueWindow'])) {
                    continue;
                }
                $conflicts[] = [
                    'type' => 'VENUE_OVERLAP',
                    'severity' => 1,
                    'venueId' => $left['fixture']->getVenueId(),
                    'start' => $this->maxMoment($left['venueWindow']['start'], $right['venueWindow']['start'])->format(self::WALL_CLOCK_FORMAT),
                    'end' => $this->minMoment($left['venueWindow']['end'], $right['venueWindow']['end'])->format(self::WALL_CLOCK_FORMAT),
                    'left' => $this->fixtureView($left),
                    'right' => $this->fixtureView($right),
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
            // Un amical (competitionId null) n'obéit à aucune enveloppe de ligue
            // FFBB : il ne peut donc jamais être « hors fenêtre autorisée par la
            // ligue » (décision fondateur, 2026-09-08, P4-190). Les autres
            // familles (VENUE_OVERLAP, MATCH_MATCH…) continuent de s'appliquer.
            if (null === $fixture->getCompetitionId()) {
                continue;
            }
            $windows = $envelope[$fixture->getTeamId()] ?? [];
            if ([] === $windows) {
                continue;
            }
            $day = (int) $fixture->getMatchDate()->format('N');
            $kickoff = $kickoffTime->format('H:i');
            $windowArrays = array_map(static fn (LeagueMatchWindow $w): array => [
                'dayOfWeek' => $w->getDayOfWeek(),
                'kickoffMin' => $w->getKickoffMin()->format('H:i'),
                'kickoffMax' => $w->getKickoffMax()->format('H:i'),
            ], $windows);
            if (self::kickoffInsideLeagueWindow($day, $kickoff, $windowArrays)) {
                continue;
            }
            $conflicts[] = [
                'type' => 'LEAGUE_WINDOW_VIOLATION',
                'severity' => 2,
                'windows' => $windowArrays,
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
            // Les accès match du GYMNASE de la fixture, jour du match d'abord — de quoi
            // dire à l'écran « placé hors des accès (samedi 14:00–18:00, …) ». Champ
            // ADDITIF (hors identité : l'empreinte reste TYPE:fixtureId).
            $venueWindows = array_values(array_filter(
                $windowArrays,
                static fn (array $w): bool => $w['venueId'] === $venueId,
            ));
            usort($venueWindows, static fn (array $a, array $b): int => [
                $a['dayOfWeek'] === $day ? 0 : 1, $a['dayOfWeek'], $a['startTime'],
            ] <=> [
                $b['dayOfWeek'] === $day ? 0 : 1, $b['dayOfWeek'], $b['startTime'],
            ]);
            $conflicts[] = [
                'type' => 'ACCESS_WINDOW_LOST',
                'severity' => 4,
                'venueId' => $venueId,
                'fixture' => $this->bareFixtureView($fixture),
                'windows' => array_map(static fn (array $w): array => [
                    'dayOfWeek' => $w['dayOfWeek'],
                    'startTime' => $w['startTime'],
                    'endTime' => $w['endTime'],
                ], $venueWindows),
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
     * @param list<FixtureView>                                $views
     * @param array<string, array<string, ConflictPersonRole>> $roleByTeamPerson
     *
     * @return list<array<string, mixed>>
     */
    private function matchMatchConflicts(array $views, array $roleByTeamPerson): array
    {
        $conflicts = [];
        $count = \count($views);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $a = $views[$i];
                $b = $views[$j];
                // Lot M — the PERSON-CONFLICT windows (warm-up dropped, whatever the
                // gym): the shared person only has to ARRIVE by the second kickoff.
                // The overlap test and the served start/end use these windows; the
                // full person windows (warm-up included) are still served per side
                // (windowStart/windowEnd, via fixtureView, which reads $view['window']
                // untouched).
                $conflictA = $a['conflictWindow'];
                $conflictB = $b['conflictWindow'];
                if (!$this->overlaps($conflictA, $conflictB)) {
                    continue;
                }
                $overlapStart = $this->maxMoment($conflictA['start'], $conflictB['start']);
                $overlapEnd = $this->minMoment($conflictA['end'], $conflictB['end']);
                // Chronological order: the fixture whose window starts earliest is
                // the left side (the fingerprint sorts the pair, so this is only for
                // a stable, readable view).
                [$left, $right] = $a['window']['start'] <= $b['window']['start'] ? [$a, $b] : [$b, $a];
                // A person (coach or player) shared by both fixtures' teams is
                // double-booked, graded by her role on EACH side.
                foreach (array_intersect($left['personIds'], $right['personIds']) as $personId) {
                    $leftRole = $this->personRole($roleByTeamPerson, $left['fixture']->getTeamId(), $personId);
                    $rightRole = $this->personRole($roleByTeamPerson, $right['fixture']->getTeamId(), $personId);
                    $conflicts[] = [
                        'type' => 'MATCH_MATCH',
                        'severity' => $this->pairSeverity($leftRole, $rightRole),
                        'coachRole' => $this->aggregateRole($leftRole, $rightRole)->value,
                        'coachId' => $personId,
                        'start' => $overlapStart->format(self::WALL_CLOCK_FORMAT),
                        'end' => $overlapEnd->format(self::WALL_CLOCK_FORMAT),
                        'left' => $this->fixtureView($left, $leftRole),
                        'right' => $this->fixtureView($right, $rightRole),
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * @param list<FixtureView>                                                                      $views
     * @param array<string, array<string, true>>                                                     $coachesByTeam
     * @param array<string, array<string, true>>                                                     $playersByTeam
     * @param array<string, array<string, ConflictPersonRole>>                                       $roleByTeamPerson
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}> $activePeriods
     * @param array<string, list<ScheduleSlotTemplate>>                                              $slotsBySchedule
     *
     * @return list<array<string, mixed>>
     */
    private function matchTrainingConflicts(
        array $views,
        array $coachesByTeam,
        array $playersByTeam,
        array $roleByTeamPerson,
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
                    // D1 rule 2 (2026-09-13), EXTENDED to players — a match of a team
                    // against the training of the SAME team is not a conflict for its
                    // coaches NOR its players: they play, they don't also train.
                    // Whatever the gym, skip the slot of the fixture's own team; a
                    // SISTER team's training (coach or player on two teams) still
                    // clashes below.
                    if ($slot->getTeamId() === $view['fixture']->getTeamId()) {
                        continue;
                    }
                    // Who is held by the training: the slot's assigned coach if any,
                    // else any coach of the slot's team — PLUS its players in every
                    // case (an assigned coach replaces the OTHER coaches, never the
                    // players). Intersect with this fixture's persons: only a person
                    // on BOTH sides is double-booked.
                    $slotCoach = $slot->getCoachId();
                    $trainingCoachIds = null !== $slotCoach
                        ? [$slotCoach]
                        : array_keys($coachesByTeam[$slot->getTeamId()] ?? []);
                    $trainingPersonIds = array_values(array_unique([
                        ...$trainingCoachIds,
                        ...array_keys($playersByTeam[$slot->getTeamId()] ?? []),
                    ]));
                    $personIds = array_values(array_intersect($view['personIds'], $trainingPersonIds));
                    if ([] === $personIds) {
                        continue;
                    }

                    $trainingWindow = $this->slotWindowOnDate($date, $slot);
                    // Lot M — the MATCH side overlaps on its PERSON-CONFLICT window
                    // (warm-up dropped, whatever the gym): the person reaches a session
                    // she is late for only by its kickoff, so a warm-up-only overlap is
                    // silent while a real one (the session runs past the kickoff) still
                    // clashes. `spannedDates` above still scans on the FULL window, a
                    // superset, so no day is missed.
                    $matchWindow = $view['conflictWindow'];
                    if (!$this->overlaps($matchWindow, $trainingWindow)) {
                        continue;
                    }

                    foreach ($personIds as $personId) {
                        $matchRole = $this->personRole($roleByTeamPerson, $view['fixture']->getTeamId(), $personId);
                        $trainingRole = $this->personRole($roleByTeamPerson, $slot->getTeamId(), $personId);
                        $conflicts[] = [
                            'type' => 'MATCH_TRAINING',
                            'severity' => $this->trainingSeverity($matchRole, $trainingRole),
                            'coachRole' => $this->aggregateRole($matchRole, $trainingRole)->value,
                            'coachId' => $personId,
                            'start' => $this->maxMoment($matchWindow['start'], $trainingWindow['start'])->format(self::WALL_CLOCK_FORMAT),
                            'end' => $this->minMoment($matchWindow['end'], $trainingWindow['end'])->format(self::WALL_CLOCK_FORMAT),
                            'fixture' => $this->fixtureView($view, $matchRole),
                            'training' => [
                                'slotTemplateId' => $slot->getId(),
                                'scheduleId' => $slot->getScheduleId(),
                                'teamId' => $slot->getTeamId(),
                                'venueId' => $slot->getVenueId(),
                                'dayOfWeek' => $slot->getDayOfWeek(),
                                'startTime' => $slot->getStartTime()->format('H:i'),
                                'durationMinutes' => $slot->getDurationMinutes(),
                                'role' => $trainingRole->value,
                                'windowStart' => $trainingWindow['start']->format(self::WALL_CLOCK_FORMAT),
                                'windowEnd' => $trainingWindow['end']->format(self::WALL_CLOCK_FORMAT),
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
     * The person's role on ONE team, for a conflict side. Falls back to ASSISTANT
     * for a person the map does not grade on that team — the case of an assigned
     * slot coach outside `team_coach`: he acts as a coach, but the softest one, so
     * the finding is never harder than the truth.
     *
     * @param array<string, array<string, ConflictPersonRole>> $roleByTeamPerson
     */
    private function personRole(array $roleByTeamPerson, string $teamId, string $personId): ConflictPersonRole
    {
        return $roleByTeamPerson[$teamId][$personId] ?? ConflictPersonRole::ASSISTANT;
    }

    /**
     * MATCH_MATCH gravity from the two side roles (cadrage §, founder decision):
     * MAIN×MAIN, MAIN×PLAYER and PLAYER×PLAYER are all a hard clash (3); a single
     * ASSISTANT engagement on either side softens it to 5 — a helper can hold it.
     */
    private function pairSeverity(ConflictPersonRole $left, ConflictPersonRole $right): int
    {
        return ConflictPersonRole::ASSISTANT === $left || ConflictPersonRole::ASSISTANT === $right ? 5 : 3;
    }

    /**
     * MATCH_TRAINING gravity (asymmetric — the match side and the training side do
     * not weigh the same): a match she PLAYS clashing with a training she COACHES
     * (MAIN) or PLAYS is hard (3), but a match she COACHES (MAIN) against a training
     * where she only PLAYS is softer (5) — she can drop the play, not the coaching.
     * Any ASSISTANT side softens it to 5 too.
     */
    private function trainingSeverity(ConflictPersonRole $matchRole, ConflictPersonRole $trainingRole): int
    {
        if (ConflictPersonRole::ASSISTANT === $matchRole || ConflictPersonRole::ASSISTANT === $trainingRole) {
            return 5;
        }

        return ConflictPersonRole::MAIN === $matchRole && ConflictPersonRole::PLAYER === $trainingRole ? 5 : 3;
    }

    /**
     * The aggregate `coachRole` (kept for compat with the pre-player contract):
     * MAIN when every side is MAIN, ASSISTANT as soon as one side is ASSISTANT,
     * PLAYER otherwise. With coaches only it degenerates to the old MAIN/ASSISTANT.
     */
    private function aggregateRole(ConflictPersonRole $a, ConflictPersonRole $b): ConflictPersonRole
    {
        if (ConflictPersonRole::ASSISTANT === $a || ConflictPersonRole::ASSISTANT === $b) {
            return ConflictPersonRole::ASSISTANT;
        }
        if (ConflictPersonRole::MAIN === $a && ConflictPersonRole::MAIN === $b) {
            return ConflictPersonRole::MAIN;
        }

        return ConflictPersonRole::PLAYER;
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
     * @param FixtureView             $view
     * @param ConflictPersonRole|null $role the person's role on this fixture's team, on a PERSON conflict
     *                                      (MATCH_MATCH/MATCH_TRAINING). null for the gym/link families,
     *                                      which share this view but carry no person → no `role` key.
     *
     * @return array<string, mixed>
     */
    private function fixtureView(array $view, ?ConflictPersonRole $role = null): array
    {
        $fixture = $view['fixture'];
        $window = $view['window'];
        $serialized = [
            'fixtureId' => $fixture->getId(),
            'teamId' => $fixture->getTeamId(),
            'homeAway' => $fixture->getHomeAway()->value,
            'matchDate' => $fixture->getMatchDate()->format('Y-m-d'),
            'kickoffTime' => $fixture->getKickoffTime()?->format('H:i'),
            // P1-4 PR C — the window was built on the team's HABITUAL kickoff,
            // not a real hour: the UI must say « heure estimée ».
            'estimatedKickoff' => $view['estimated'],
            // P2-54 conflict side details — ADDITIVE per-side fields served on
            // EVERY family that shares this view, because the conflicts screen now
            // renders a per-side line for the gym family too: VENUE_OVERLAP reads
            // opponentLabel + matchDurationMinutes (matches/lib/conflictSideLines.ts
            // ::venueSide), not only MATCH_MATCH/MATCH_TRAINING. The
            // ConflictFingerprinter is a whitelist and never reads them.
            // `opponentPlace` is NOT here — it is decorated by
            // FixtureConflictsController on AWAY sides after detection.
            'estimatedKickoffTime' => $view['estimatedKickoffTime'],
            'travelOneWayMinutes' => $view['travelOneWayMinutes'],
            'matchDurationMinutes' => $view['matchDurationMinutes'],
            'opponentLabel' => $fixture->getOpponentLabel(),
            'windowStart' => $window['start']->format(self::WALL_CLOCK_FORMAT),
            'windowEnd' => $window['end']->format(self::WALL_CLOCK_FORMAT),
        ];
        if ($role instanceof ConflictPersonRole) {
            $serialized['role'] = $role->value;
        }

        return $serialized;
    }
}
