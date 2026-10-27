<?php

declare(strict_types=1);

namespace App\Service\Conflicts;

use App\Entity\Fixture;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\ConflictPersonRole;
use App\Enum\FixtureHomeAway;
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
    public function __construct(
        private readonly EffectiveScheduleResolver $effectiveScheduleResolver,
    ) {}

    /**
     * @param list<FixtureView>                                                                              $views
     * @param array<string, array<string, ConflictPersonRole>>                                               $roleByTeamPerson
     * @param list<array{coachId: string, dayOfWeek: int, kickoffMin: string|null, kickoffMax: string|null}> $coachUnavailabilities
     *
     * @return list<array<string, mixed>>
     */
    public function matchMatchConflicts(array $views, array $roleByTeamPerson, array $coachUnavailabilities = []): array
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
                if (!ConflictMoments::overlaps($conflictA, $conflictB)) {
                    continue;
                }
                $overlapStart = ConflictMoments::maxMoment($conflictA['start'], $conflictB['start']);
                $overlapEnd = ConflictMoments::minMoment($conflictA['end'], $conflictB['end']);
                // Chronological order: the fixture whose window starts earliest is
                // the left side (the fingerprint sorts the pair, so this is only for
                // a stable, readable view).
                [$left, $right] = $a['window']['start'] <= $b['window']['start'] ? [$a, $b] : [$b, $a];
                // A person (coach or player) shared by both fixtures' teams is
                // double-booked, graded by her role on EACH side.
                foreach (array_intersect($left['personIds'], $right['personIds']) as $personId) {
                    $leftRole = $this->personRole($roleByTeamPerson, $left['fixture']->getTeamId(), $personId);
                    $rightRole = $this->personRole($roleByTeamPerson, $right['fixture']->getTeamId(), $personId);
                    $severity = $this->pairSeverity($leftRole, $rightRole);
                    // Chantier H volet matchs (amendement a) — un conflit DUR (3) s'adoucit à 5
                    // quand, SUR UN CÔTÉ OÙ CETTE PERSONNE EST MAIN, l'équipe a un autre coach
                    // (adjoint OU autre principal) DISPONIBLE qui peut tenir ce côté. CÔTÉ JOUEUR
                    // jamais adouci (un joueur ne se remplace pas par un coach).
                    $relief = 3 === $severity && (
                        (ConflictPersonRole::MAIN === $leftRole && $this->reliefAvailable($left, $personId, $roleByTeamPerson, $coachUnavailabilities))
                        || (ConflictPersonRole::MAIN === $rightRole && $this->reliefAvailable($right, $personId, $roleByTeamPerson, $coachUnavailabilities))
                    );
                    $conflicts[] = [
                        'type' => 'MATCH_MATCH',
                        'severity' => $relief ? 5 : $severity,
                        'relief' => $relief,
                        'coachRole' => $this->aggregateRole($leftRole, $rightRole)->value,
                        'coachId' => $personId,
                        'start' => $overlapStart->format(ConflictMoments::WALL_CLOCK_FORMAT),
                        'end' => $overlapEnd->format(ConflictMoments::WALL_CLOCK_FORMAT),
                        'left' => ConflictMoments::fixtureView($left, $leftRole),
                        'right' => ConflictMoments::fixtureView($right, $rightRole),
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * @param list<FixtureView>                                                                              $views
     * @param array<string, array<string, true>>                                                             $coachesByTeam
     * @param array<string, array<string, true>>                                                             $playersByTeam
     * @param array<string, array<string, ConflictPersonRole>>                                               $roleByTeamPerson
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}>         $activePeriods
     * @param array<string, list<ScheduleSlotTemplate>>                                                      $slotsBySchedule
     * @param list<array{coachId: string, dayOfWeek: int, kickoffMin: string|null, kickoffMax: string|null}> $coachUnavailabilities
     * @param list<string>                                                                                   $publicHolidays        dates fériées (Y-m-d) — aucun entraînement n'a lieu un jour férié (PR2)
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
        array $coachUnavailabilities = [],
        array $publicHolidays = [],
    ): array {
        $conflicts = [];
        foreach ($views as $view) {
            // A footprint can cross midnight (late kickoff) — check every calendar
            // day it spans, each resolving its own effective schedule + weekday.
            foreach ($this->spannedDates($view['window']) as $date) {
                // PR2 — un jour FÉRIÉ, l'entraînement projeté n'a pas lieu : aucun MATCH_TRAINING
                // ne peut naître de ce jour-là (décision fondateur 2026-10-27, a+b). Le match, lui,
                // est fixé par la ligue — il reste. La mise en sourdine est conditionnée à la DATE.
                if (\in_array($date->format('Y-m-d'), $publicHolidays, true)) {
                    continue;
                }
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
                    if (!ConflictMoments::overlaps($matchWindow, $trainingWindow)) {
                        continue;
                    }

                    foreach ($personIds as $personId) {
                        $matchRole = $this->personRole($roleByTeamPerson, $view['fixture']->getTeamId(), $personId);
                        $trainingRole = $this->personRole($roleByTeamPerson, $slot->getTeamId(), $personId);
                        $severity = $this->trainingSeverity($matchRole, $trainingRole);
                        // Chantier H volet matchs (amendement a) — un conflit DUR (3) s'adoucit à 5
                        // si, du CÔTÉ MATCH où la personne est MAIN, l'équipe a un autre coach
                        // disponible qui peut tenir le match (côté joueur jamais).
                        $relief = 3 === $severity
                            && ConflictPersonRole::MAIN === $matchRole
                            && $this->reliefAvailable($view, $personId, $roleByTeamPerson, $coachUnavailabilities);
                        $conflicts[] = [
                            'type' => 'MATCH_TRAINING',
                            'severity' => $relief ? 5 : $severity,
                            'relief' => $relief,
                            'coachRole' => $this->aggregateRole($matchRole, $trainingRole)->value,
                            'coachId' => $personId,
                            'start' => ConflictMoments::maxMoment($matchWindow['start'], $trainingWindow['start'])->format(ConflictMoments::WALL_CLOCK_FORMAT),
                            'end' => ConflictMoments::minMoment($matchWindow['end'], $trainingWindow['end'])->format(ConflictMoments::WALL_CLOCK_FORMAT),
                            'fixture' => ConflictMoments::fixtureView($view, $matchRole),
                            'training' => [
                                'slotTemplateId' => $slot->getId(),
                                'scheduleId' => $slot->getScheduleId(),
                                'teamId' => $slot->getTeamId(),
                                'venueId' => $slot->getVenueId(),
                                'dayOfWeek' => $slot->getDayOfWeek(),
                                'startTime' => $slot->getStartTime()->format('H:i'),
                                'durationMinutes' => $slot->getDurationMinutes(),
                                'role' => $trainingRole->value,
                                'windowStart' => $trainingWindow['start']->format(ConflictMoments::WALL_CLOCK_FORMAT),
                                'windowEnd' => $trainingWindow['end']->format(ConflictMoments::WALL_CLOCK_FORMAT),
                            ],
                        ];
                    }
                }
            }
        }

        return $conflicts;
    }

    /**
     * Chantier H volet matchs (amendement b) — la famille MATCH_COACH_UNAVAILABLE : un match dont
     * le coup d'envoi tombe dans l'indisponibilité DÉCLARÉE d'un coach PRINCIPAL de l'équipe. Crans
     * (refonte fondateur, échelle `toneOf`) :
     *  - coup d'envoi DANS l'indispo → ROUGE (3), à domicile comme à l'extérieur (le match est manqué) ;
     *  - l'indispo ne touche QUE la durée du match (coup d'envoi libre) → JAUNE (5) à DOMICILE, mais
     *    ROUGE (3) à l'EXTÉRIEUR (le coach doit être sur place à l'heure) ;
     *  - un adjoint OU un autre principal DISPONIBLE peut couvrir → le ROUGE devient ORANGE (4) ;
     *    le JAUNE reste JAUNE.
     * L'indispo d'un coach qui n'est qu'ADJOINT ne lève JAMAIS la famille (le principal disponible
     * reste l'entité). Empreinte (TYPE:coachId:fixtureId) : une entrée par (coach, rencontre).
     *
     * @param list<FixtureView>                                                                              $views
     * @param array<string, array<string, ConflictPersonRole>>                                               $roleByTeamPerson
     * @param list<array{coachId: string, dayOfWeek: int, kickoffMin: string|null, kickoffMax: string|null}> $coachUnavailabilities
     *
     * @return list<array<string, mixed>>
     */
    public function coachUnavailableConflicts(array $views, array $roleByTeamPerson, array $coachUnavailabilities): array
    {
        if ([] === $coachUnavailabilities) {
            return [];
        }

        $byCoachFixture = [];
        foreach ($views as $view) {
            $fixture = $view['fixture'];
            $teamId = $fixture->getTeamId();
            $kickoff = $this->kickoffMinuteOf($view);
            if (null === $kickoff) {
                continue; // pas d'empreinte (aucune heure, aucune habitude empruntée)
            }
            $weekday = (int) $fixture->getMatchDate()->format('N');
            $matchEnd = $kickoff + (int) $view['matchDurationMinutes'];
            $away = FixtureHomeAway::AWAY === $fixture->getHomeAway();

            foreach ($roleByTeamPerson[$teamId] ?? [] as $personId => $role) {
                // Seul un coach PRINCIPAL indisponible lève la famille (adjoint-only → jamais).
                if (ConflictPersonRole::MAIN !== $role) {
                    continue;
                }
                foreach ($coachUnavailabilities as $unavailability) {
                    if ($unavailability['coachId'] !== $personId || $unavailability['dayOfWeek'] !== $weekday) {
                        continue;
                    }
                    $from = $this->timeToMinutes($unavailability['kickoffMin'], 0);
                    $to = $this->timeToMinutes($unavailability['kickoffMax'], 1440);
                    $kickoffInside = $kickoff >= $from && $kickoff <= $to;
                    $overlapsMatch = $from < $matchEnd && $kickoff < $to;
                    if ($kickoffInside) {
                        $base = 3;
                    } elseif ($overlapsMatch) {
                        $base = $away ? 3 : 5;
                    } else {
                        continue;
                    }
                    $relief = 3 === $base
                        && $this->coverAvailable($teamId, $personId, $weekday, $kickoff, $roleByTeamPerson, $coachUnavailabilities);
                    $severity = 3 === $base && $relief ? 4 : $base;

                    $key = $personId . '|' . $fixture->getId();
                    if (isset($byCoachFixture[$key]) && $byCoachFixture[$key]['severity'] <= $severity) {
                        continue; // garde la plus grave (sévérité la plus basse)
                    }
                    $byCoachFixture[$key] = [
                        'type' => 'MATCH_COACH_UNAVAILABLE',
                        'severity' => $severity,
                        'relief' => $relief,
                        'coachId' => $personId,
                        'coachRole' => ConflictPersonRole::MAIN->value,
                        'fixture' => ConflictMoments::fixtureView($view, ConflictPersonRole::MAIN),
                    ];
                }
            }
        }

        return array_values($byCoachFixture);
    }

    /**
     * Chantier H — un autre coach (adjoint OU autre principal) de CETTE équipe, autre que la
     * personne en conflit, est-il DISPONIBLE au coup d'envoi pour tenir ce côté ? (service
     * « adjoint disponible » du volet matchs). Les joueurs ne couvrent jamais un coach.
     *
     * @param FixtureView                                                                                    $view
     * @param array<string, array<string, ConflictPersonRole>>                                               $roleByTeamPerson
     * @param list<array{coachId: string, dayOfWeek: int, kickoffMin: string|null, kickoffMax: string|null}> $coachUnavailabilities
     */
    private function reliefAvailable(array $view, string $excludePersonId, array $roleByTeamPerson, array $coachUnavailabilities): bool
    {
        $kickoff = $this->kickoffMinuteOf($view);
        if (null === $kickoff) {
            return false;
        }
        $weekday = (int) $view['fixture']->getMatchDate()->format('N');

        return $this->coverAvailable($view['fixture']->getTeamId(), $excludePersonId, $weekday, $kickoff, $roleByTeamPerson, $coachUnavailabilities);
    }

    /**
     * @param array<string, array<string, ConflictPersonRole>>                                               $roleByTeamPerson
     * @param list<array{coachId: string, dayOfWeek: int, kickoffMin: string|null, kickoffMax: string|null}> $coachUnavailabilities
     */
    private function coverAvailable(string $teamId, string $excludePersonId, int $weekday, int $kickoffMinute, array $roleByTeamPerson, array $coachUnavailabilities): bool
    {
        foreach ($roleByTeamPerson[$teamId] ?? [] as $personId => $role) {
            if ($personId === $excludePersonId) {
                continue;
            }
            // Seul un COACH (principal ou adjoint) couvre — jamais un joueur.
            if (ConflictPersonRole::MAIN !== $role && ConflictPersonRole::ASSISTANT !== $role) {
                continue;
            }
            if ($this->coachAvailableAt($personId, $weekday, $kickoffMinute, $coachUnavailabilities)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{coachId: string, dayOfWeek: int, kickoffMin: string|null, kickoffMax: string|null}> $coachUnavailabilities
     */
    private function coachAvailableAt(string $coachId, int $weekday, int $kickoffMinute, array $coachUnavailabilities): bool
    {
        foreach ($coachUnavailabilities as $unavailability) {
            if ($unavailability['coachId'] !== $coachId || $unavailability['dayOfWeek'] !== $weekday) {
                continue;
            }
            $from = $this->timeToMinutes($unavailability['kickoffMin'], 0);
            $to = $this->timeToMinutes($unavailability['kickoffMax'], 1440);
            if ($kickoffMinute >= $from && $kickoffMinute <= $to) {
                return false;
            }
        }

        return true;
    }

    /**
     * Le coup d'envoi (minutes depuis minuit) de la rencontre d'une vue : l'heure RÉELLE, ou
     * l'heure ESTIMÉE empruntée à l'habitude (même source que les autres familles personne) ;
     * null quand il n'y a aucune empreinte.
     *
     * @param FixtureView $view
     */
    private function kickoffMinuteOf(array $view): ?int
    {
        $kickoff = $view['fixture']->getKickoffTime()?->format('H:i') ?? $view['estimatedKickoffTime'];

        return null === $kickoff ? null : $this->timeToMinutes($kickoff, 0);
    }

    private function timeToMinutes(?string $time, int $default): int
    {
        if (null === $time || 1 !== preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches)) {
            return $default;
        }

        return (int) $matches[1] * 60 + (int) $matches[2];
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
}
