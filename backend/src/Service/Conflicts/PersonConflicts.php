<?php

declare(strict_types=1);

namespace App\Service\Conflicts;

use App\Entity\Fixture;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\ConflictPersonRole;
use App\Service\EffectiveScheduleResolver;
use App\Service\MatchConflictDetector;
use DateInterval;
use DateTimeImmutable;

/**
 * Famille « personnes » du radar de conflits — extraite VERBATIM de
 * {@see MatchConflictDetector} (iso-comportement, aucune règle changée). La façade
 * {@see MatchConflictDetector::detect()} monte les vues PERSONNE (coachs ∪ joueurs
 * actifs) et la carte des rôles, puis délègue ici les doubles-réservations
 * MATCH_MATCH et MATCH_TRAINING. La résolution du calendrier effectif passe par le
 * même {@see EffectiveScheduleResolver} que tous les consommateurs.
 *
 * @phpstan-type OccupancyWindow array{start: DateTimeImmutable, end: DateTimeImmutable}
 * @phpstan-type FixtureView array{fixture: Fixture, window: OccupancyWindow, venueWindow: OccupancyWindow, conflictWindow: OccupancyWindow, estimated: bool, estimatedKickoffTime: string|null, travelOneWayMinutes: int|null, matchDurationMinutes: int, personIds: list<string>}
 */
final class PersonConflicts
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
        private readonly EffectiveScheduleResolver $effectiveScheduleResolver,
    ) {}

    /**
     * @param list<FixtureView>                                $views
     * @param array<string, array<string, ConflictPersonRole>> $roleByTeamPerson
     *
     * @return list<array<string, mixed>>
     */
    public function matchMatchConflicts(array $views, array $roleByTeamPerson): array
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
    public function matchTrainingConflicts(
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
                    // Lot M / P4-240 ③ — the MATCH side overlaps on its PERSON-CONFLICT
                    // window: at home the warm-up is dropped (a warm-up-only overlap the
                    // person reaches by kickoff is silent), an away match counts the
                    // warm-up before departure (décision C). A real overlap (the session
                    // runs into the window) still clashes. `spannedDates` above still
                    // scans on the FULL window, a superset, so no day is missed.
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
