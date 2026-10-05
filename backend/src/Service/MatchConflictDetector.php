<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CoachPlayerMembership;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\LeagueWindowInterface;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\TeamCoach;
use App\Entity\TeamMatchHabit;
use App\Entity\VenueMatchWindow;
use App\Enum\ConflictPersonRole;
use App\Enum\FixtureHomeAway;
use App\Enum\TeamLevel;
use App\Service\Conflicts\PersonConflicts;
use App\Service\Conflicts\RuleWindowConflicts;
use App\Service\Conflicts\VenueConflicts;
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
 *   même règle pour le coach ») — at HOME the warm-up NEVER counts for a person
 *   clash: the shared person only has to ARRIVE by the second kickoff, the warm-up
 *   is hers to skip. ⚠ P4-240 ③ (décision C) — before an AWAY match the warm-up IS
 *   counted (she must be at the away gym warmed up). So each side's window is its
 *   {@see MatchFootprint::personConflictOccupancy}: [kickoff, kickoff + match] at
 *   home, [kickoff − travelOut − warmup, kickoff + match + travelBack] away.
 *   Arrival exactly at the second kickoff = no conflict (half-open overlap). This
 *   SUBSUMES the old « même gymnase à domicile » exception (2026-09-17): two home
 *   matches always drop their warm-up now, not only in one shared gym. Sides are
 *   ordered CHRONOLOGICALLY (earliest full
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
 *   positive) but NEVER evicts its players. ⚠ Lot M / P4-240 ③ — the MATCH side
 *   uses its PERSON-CONFLICT window: at HOME the warm-up is dropped (a warm-up-only
 *   overlap with a session the person reaches by kickoff is silent), while an AWAY
 *   match counts the warm-up before departure (décision C). A real overlap (the
 *   session runs into the person window) still clashes. This subsumes the old
 *   same-gym « second match » special case (2026-09-17).
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
 * - CLUB_RULE_VIOLATION (3, P4-272 ③): a placed HOME fixture whose kickoff violates
 *   a HARD club match rule covering the match day. HARD rules ONLY (a PREFERRED rule
 *   never makes a violation); friendlies exempt (like the league envelope). Manual
 *   placement outside a HARD rule stays PERMITTED — this ALERTS, it never blocks.
 * - TEAM_VENUE_FORBIDDEN (3, P4-272 ④, same gravity as CLUB_RULE_VIOLATION — founder
 *   decision): a placed HOME fixture in a venue its team is FORBIDDEN to play at
 *   (scope TEAM HARD rule). Kickoff-independent (the venue is the violation);
 *   friendlies exempt. The solver never places a match there; a MANUAL placement in a
 *   forbidden venue stays PERMITTED — this ALERTS, it never blocks.
 * - ACCESS_WINDOW_LOST (4, dette ii): a placed HOME fixture whose kickoff no
 *   longer falls in any access window of (venue, weekday) — the window changed
 *   AFTER the placement. Mirrors the PANEL rule (kickoff point, half-open,
 *   club-without-any-window = nothing to enforce), NOT the solver's
 *   full-footprint rule: a match the panel just allowed must not alert.
 * - AWAY_NO_FOOTPRINT (7, dette v): an AWAY fixture with no hour and no habit
 *   on its weekday — the residual blind spot is now NAMED (info; the UI folds
 *   the group). JAMAIS émis pour une équipe LOISIR (ni calendrier ligue ni habitude
 *   à déclarer — décision fondateur 2026-10-01).
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
     * Les familles de conflits extraites VERBATIM (lot architecture BCK-19). Elles
     * sont PURES/stateless et instanciées ici : la façade {@see detect()} monte les
     * vues/fixtures et leur délègue chaque famille. Instanciées en interne (et non
     * injectées) pour garder le constructeur à 3 arguments — le test unitaire
     * `MatchConflictDetectorTest` construit le détecteur à la main et ne doit pas changer.
     */
    private readonly VenueConflicts $venueConflicts;

    private readonly RuleWindowConflicts $ruleWindowConflicts;

    private readonly PersonConflicts $personConflicts;

    public function __construct(
        private readonly MatchFootprint $footprint,
        private readonly EffectiveScheduleResolver $effectiveScheduleResolver,
        private readonly AwayKickoffEstimator $awayKickoffEstimator,
    ) {
        $this->venueConflicts = new VenueConflicts;
        $this->ruleWindowConflicts = new RuleWindowConflicts;
        $this->personConflicts = new PersonConflicts($this->effectiveScheduleResolver);
    }

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
     * LE prédicat pur d'une règle de match du club — le coup d'envoi (H:i) est-il
     * dans la fourchette de la règle ? Chaque borne facultative (« pas après 21h » =
     * max seul) ; intervalle FERMÉ des deux côtés (comme l'enveloppe ligue). Une
     * borne absente = ouverte de ce côté. Comparaison lexicographique sûre (H:i
     * zero-paddé).
     *
     * @param array{kickoffMin: string|null, kickoffMax: string|null} $rule
     */
    public static function kickoffSatisfiesClubRule(string $kickoff, array $rule): bool
    {
        $belowMin = null !== $rule['kickoffMin'] && $kickoff < $rule['kickoffMin'];
        $aboveMax = null !== $rule['kickoffMax'] && $kickoff > $rule['kickoffMax'];

        return !$belowMin && !$aboveMax;
    }

    /**
     * P4-300 — le prédicat PUR « la date tombe dans l'intervalle de fermeture [startDate, endDate] »,
     * bornes INCLUSES (dates `Y-m-d` zero-paddées → comparaison lexicographique = comparaison de
     * dates). MIROIR DÉCLARÉ avec `matches/lib/matchAccess.ts::dateInsideClosure` (le front REFUSE
     * la pose, rail synchrone ; le backend DIAGNOSTIQUE puis refuse) — cas partagés
     * `matchAccess.parity.json`, gardés par `MatchAccessMirrorParityTest`.
     */
    public static function dateInsideClosure(string $date, string $startDate, string $endDate): bool
    {
        return $date >= $startDate && $date <= $endDate;
    }

    /**
     * @param list<Fixture>                                                                                          $fixtures              season fixtures (already club+season scoped)
     * @param list<TeamCoach>                                                                                        $teamCoachRows         coach↔team links (scoped)
     * @param string|null                                                                                            $seasonScheduleId      the season's calendar (the version its plan points at), or null
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}>                 $activePeriods
     *                                                                                                                                      active period windows (ordered), scheduleId = their overlay or null
     * @param array<string, list<ScheduleSlotTemplate>>                                                              $slotsBySchedule       slots indexed by their scheduleId
     * @param list<array{venueId: string, startDate: string, endDate: string, label: string|null, sourceId: string}> $unavailabilities      indispos déclarées + fermetures `venue_closed`, forme commune (P4-300)
     * @param list<TeamMatchHabit>                                                                                   $habits                scoped habitual windows (estimation source)
     * @param list<VenueMatchWindow>                                                                                 $matchWindows          scoped access windows (ACCESS_WINDOW_LOST)
     * @param array<string, list<LeagueWindowInterface>>                                                             $envelope              teamId → resolved league windows ([] = unmapped)
     * @param list<Competition>                                                                                      $competitions          scoped competitions (COMPETITION_INCOMPLETE — severity 6 alerte / 7 info)
     * @param array<string, MatchDurationProfile>                                                                    $profilesByTeam        teamId → match duration profile (P2-54 RMM-9); a team absent falls back to MatchDurationProfile::fallback()
     * @param array<string, int>                                                                                     $roundTripByFixtureId
     *                                                                                                                                      fixtureId → round-trip car travel minutes (P2-54 RMM-9 PR-3, AWAY only); absent = 0
     *                                                                                                                                      (no travel modelled → no spatial extension of the footprint). The controller projects it
     *                                                                                                                                      via OpponentTravelProjection (link gym → constant travel cache, 2 × one-way), the detector stays pure.
     * @param DateTimeImmutable|null                                                                                 $clubToday
     *                                                                                                                                      the club's civil today ({@see ClubDay}); a fixture whose matchDate is strictly BEFORE
     *                                                                                                                                      it is already played — it neither PORTS nor RECEIVES a conflict (D1, rule 3). null
     *                                                                                                                                      (the pure test path) disables the filter entirely.
     * @param list<CoachPlayerMembership>                                                                            $playerMemberships
     *                                                                                                                                      scoped coach↔team PLAYER links; only the active ones count. A person is a PLAYER of
     *                                                                                                                                      that team UNLESS she already coaches it (the coach role then wins). Both callers load
     *                                                                                                                                      and pass these (parité MatchVisitDeltaParityTest).
     * @param list<array{ruleType: string, daysOfWeek: list<int>, kickoffMin: string|null, kickoffMax: string|null}> $clubRules
     *                                                                                                                                      the club's CLUB-scoped match rules (P4-272 ③). Only HARD rules make a
     *                                                                                                                                      violation (CLUB_RULE_VIOLATION); PREFERRED ones only nudge the solver.
     * @param array<string, list<string>>                                                                            $forbiddenVenuesByTeam
     *                                                                                                                                      teamId → the venue ids that team is FORBIDDEN to play at (P4-272 ④,
     *                                                                                                                                      scope TEAM HARD rules). A HOME fixture posed in one is TEAM_VENUE_FORBIDDEN.
     * @param array<string, TeamLevel>                                                                               $levelByTeam
     *                                                                                                                                      teamId → niveau de l'équipe ; une équipe LOISIR est muette pour AWAY_NO_FOOTPRINT
     *                                                                                                                                      (décision fondateur 2026-10-01)
     * @param array<string, DateTimeImmutable>                                                                       $deadlineByCompetition
     *                                                                                                                                      competitionId → échéance de saisie effective (club gagne, sinon défaut
     *                                                                                                                                      communautaire) ; gradue la cohérence de complétude (info vs alerte)
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
        array $clubRules = [],
        array $forbiddenVenuesByTeam = [],
        array $levelByTeam = [],
        array $deadlineByCompetition = [],
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
            // Lot M / P4-240 ③ — the PERSON-conflict window: at home the warm-up is
            // dropped (a person reaches a home game she is late for by its kickoff),
            // before an away match the warm-up IS counted (she warms up at the away
            // gym, décision C). Travel kept. The families of PERSON overlap on THIS.
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
            ...$this->venueConflicts->venueOverlapConflicts($views),
            ...$this->ruleWindowConflicts->leagueWindowViolations($activeFixtures, $envelope),
            ...$this->ruleWindowConflicts->clubRuleViolations($activeFixtures, $clubRules),
            ...$this->venueConflicts->teamVenueForbiddenConflicts($activeFixtures, $forbiddenVenuesByTeam),
            ...$this->personConflicts->matchMatchConflicts($personViews, $roleByTeamPerson),
            ...$this->personConflicts->matchTrainingConflicts($personViews, $coachesByTeam, $playersByTeam, $roleByTeamPerson, $seasonScheduleId, $activePeriods, $slotsBySchedule),
            ...$this->venueConflicts->venueUnavailableConflicts($activeFixtures, $unavailabilities),
            ...$this->venueConflicts->accessWindowLostConflicts($activeFixtures, $matchWindows),
            ...$this->competitionIncompleteItems($fixtures, $competitions, $deadlineByCompetition, $clubToday),
            ...$this->awayNoFootprintItems($activeFixtures, $habitByTeamDay, $levelByTeam),
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
     * Cohérence de complétude d'une compétition APPARIÉE (règle fondateur 2026-10-01).
     * FBI (les matchs importés) fait FOI ; l'appariement FFBB n'est qu'une vérification.
     * Une phase sort EN ENTIER, donc le nombre de rencontres `n` rattachées à la
     * compétition doit valoir `adv` (aller simple) ou `2×adv` (aller-retour), où
     * `adv` = adversaires RÉELS de la poule = (taille de poule − 1 pour le club) − exempts.
     * `expectedMatchdays` stocké au confirm == 2×(taille de poule − 1), d'où
     * `adv = expectedMatchdays/2 − exempts`. Table servie (type COMPETITION_INCOMPLETE,
     * champ `reason`) :
     *  - pas d'appariement (expectedMatchdays null, coupe comprise) ⇒ rien ;
     *  - n == 0 ⇒ rien (phase pas encore sortie) ;
     *  - n == adv ou n == 2×adv ⇒ cohérent, rien ;
     *  - n > 2×adv ⇒ ALERTE (severity 6, reason OVER) « appariement/rattachement à vérifier » ;
     *  - 0 < n < 2×adv, n ≠ adv ⇒ échéance de saisie dépassée : ALERTE (6, INCOHERENT) ;
     *    sinon (pas dépassée, ou inconnue) : INFO discrète (severity 7, PENDING, repliée).
     *
     * @param list<Fixture>                    $fixtures
     * @param list<Competition>                $competitions
     * @param array<string, DateTimeImmutable> $deadlineByCompetition competitionId → échéance de saisie effective
     *
     * @return list<array<string, mixed>>
     */
    private function competitionIncompleteItems(array $fixtures, array $competitions, array $deadlineByCompetition, ?DateTimeImmutable $clubToday): array
    {
        $countByCompetition = [];
        foreach ($fixtures as $fixture) {
            $competitionId = $fixture->getCompetitionId();
            if (null !== $competitionId) {
                $countByCompetition[$competitionId] = ($countByCompetition[$competitionId] ?? 0) + 1;
            }
        }

        $today = $clubToday?->format('Y-m-d');
        $items = [];
        foreach ($competitions as $competition) {
            $expected = $competition->getExpectedMatchdays();
            if (null === $expected) {
                continue; // pas d'appariement (ou coupe) → aucune base de cohérence
            }
            $exempts = 0;
            foreach ($competition->getFfbbPouleOpponents() ?? [] as $opponentName) {
                if (false !== stripos($opponentName, 'exempt')) {
                    ++$exempts;
                }
            }
            $adv = intdiv($expected, 2) - $exempts;
            if ($adv < 1) {
                continue;
            }
            $n = $countByCompetition[$competition->getId()] ?? 0;
            if (0 === $n || $n === $adv || $n === 2 * $adv) {
                continue; // phase pas sortie, aller simple ou aller-retour : cohérent
            }

            if ($n > 2 * $adv) {
                $reason = 'OVER';
                $severity = 6;
            } else {
                $deadline = $deadlineByCompetition[$competition->getId()] ?? null;
                $passed = $deadline instanceof DateTimeImmutable && null !== $today && $deadline->format('Y-m-d') < $today;
                $reason = $passed ? 'INCOHERENT' : 'PENDING';
                $severity = $passed ? 6 : 7;
            }

            $items[] = [
                'type' => 'COMPETITION_INCOMPLETE',
                'severity' => $severity,
                'reason' => $reason,
                'competitionId' => $competition->getId(),
                'competitionName' => $competition->getName(),
                'teamId' => $competition->getTeamId(),
                'imported' => $n,
                // `expected` = la cible ALLER-RETOUR, adversaires réels exclus des exempts
                // (2×adv) : le front dérive l'aller simple (adv = expected/2) et compose le
                // message FR par `reason`. Corrige l'ancienne valeur qui comptait les exempts.
                'expected' => 2 * $adv,
            ];
        }

        return $items;
    }

    /**
     * Severity 7 (dette v, info) — an AWAY fixture with no hour and no habit on
     * its weekday: no footprint, so the radar is BLIND to it. Named so the
     * manager declares a habit instead of trusting a silence.
     *
     * @param list<Fixture>                             $fixtures
     * @param array<string, array<int, TeamMatchHabit>> $habitByTeamDay
     * @param array<string, TeamLevel>                  $levelByTeam    teamId → niveau ; une équipe LOISIR est muette ici
     *
     * @return list<array<string, mixed>>
     */
    private function awayNoFootprintItems(array $fixtures, array $habitByTeamDay, array $levelByTeam): array
    {
        $items = [];
        foreach ($fixtures as $fixture) {
            if (FixtureHomeAway::AWAY !== $fixture->getHomeAway() || $fixture->getKickoffTime() instanceof DateTimeImmutable) {
                continue;
            }
            // Une équipe LOISIR n'a ni calendrier ligue ni habitude à déclarer : son
            // extérieur sans heure n'est pas un angle mort à nommer (décision fondateur
            // 2026-10-01) — on ne l'émet pas pour LOISIR_ADULTE/LOISIR_JEUNE.
            if (\in_array($levelByTeam[$fixture->getTeamId()] ?? null, [TeamLevel::LOISIR_ADULTE, TeamLevel::LOISIR_JEUNE], true)) {
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
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable} $a
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable} $b
     */
    private function overlaps(array $a, array $b): bool
    {
        // Half-open: back-to-back windows (endA == startB) do NOT conflict.
        return $a['start'] < $b['end'] && $b['start'] < $a['end'];
    }
}
