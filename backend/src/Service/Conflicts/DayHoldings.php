<?php

declare(strict_types=1);

namespace App\Service\Conflicts;

use App\Entity\ScheduleSlotTemplate;
use App\Enum\ConflictPersonRole;
use App\Service\EffectiveScheduleResolver;
use DateTimeImmutable;

/**
 * Qui tient quoi, date par date, pour le radar : monte les besoins d'une date et les confie à
 * {@see DatedHolding}. Calculé une fois par date, et seulement pour les dates qui portent une ligne.
 *
 * Côté placement, `$busyToPlaceByDate` nomme les personnes qui ont un match à placer ce jour-là : à
 * candidats égaux, les autres passent devant pour tenir (le radar n'en passe aucune).
 *
 * Un besoin de match : un match du jour, avec un tenant parmi les principaux de son équipe dont la
 * plage d'indisponibilité du module Matchs ne contient pas le coup d'envoi.
 * Un besoin de séance : une séance du plan effectif de la date, avec un tenant parmi le coach nommé
 * (sauf s'il n'est qu'adjoint de l'équipe), les principaux de l'équipe et ceux du groupe déclaré sur
 * la même case, moins ceux dont une indisponibilité d'entraînement du plan chevauche la séance.
 * Chaque rail reste le sien. Les créneaux d'un groupe sur la même case font UN besoin. Une séance
 * qui chevauche un match de sa propre équipe n'est pas un besoin : l'équipe est au match. Un jour
 * férié n'a pas de séance.
 *
 * @phpstan-import-type FixtureView from PersonConflicts
 * @phpstan-import-type Need from DatedHolding
 *
 * @phpstan-type Board array{assignment: DatedAssignment, needOfSlot: array<string, string>, teamsOfNeed: array<string, list<string>>, sessions: list<Need>}
 */
final class DayHoldings
{
    /** @var array<string, FixtureView> */
    private array $viewByFixture = [];

    /** @var array<string, Board> */
    private array $boards = [];

    private readonly DayMatches $dayMatches;

    /**
     * @param list<FixtureView>                                                                              $views
     * @param array<string, array<string, ConflictPersonRole>>                                               $roleByTeamPerson
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}>         $activePeriods
     * @param array<string, list<ScheduleSlotTemplate>>                                                      $slotsBySchedule
     * @param list<array{coachId: string, dayOfWeek: int, kickoffMin: string|null, kickoffMax: string|null}> $coachUnavailabilities
     * @param list<string>                                                                                   $publicHolidays
     * @param array<string, array<string, list<array{0: int, 1: int, 2: int}>>>                              $wizardBlockedBySchedule version → coach → [jour, début, fin]
     * @param array<string, list<list<string>>>                                                              $groupsBySchedule        version → listes d'équipes d'un groupe déclaré
     * @param array<string, array<string, true>>                                                             $busyToPlaceByDate       date → personnes avec un match à placer ce jour-là
     */
    public function __construct(
        private readonly array $views,
        private readonly array $roleByTeamPerson,
        private readonly EffectiveScheduleResolver $effectiveScheduleResolver,
        private readonly ?string $seasonScheduleId,
        private readonly array $activePeriods,
        private readonly array $slotsBySchedule,
        private readonly array $coachUnavailabilities,
        private readonly array $publicHolidays,
        private readonly array $wizardBlockedBySchedule,
        private readonly array $groupsBySchedule,
        private readonly array $busyToPlaceByDate = [],
    ) {
        $this->dayMatches = new DayMatches;
        foreach ($views as $view) {
            $this->viewByFixture[$view['fixture']->getId()] = $view;
        }
    }

    /**
     * Les séances-besoins de la date, chacune avec son tenant ou null si personne ne peut la tenir.
     *
     * @return list<array{startMinutes: int, endMinutes: int, holder: string|null, candidates: list<string>, players: list<string>}>
     */
    public function sessionsOn(DateTimeImmutable $date): array
    {
        $board = $this->board($date);
        $sessions = [];
        foreach ($board['sessions'] as $need) {
            $sessions[] = [
                'startMinutes' => $need['startMinutes'],
                'endMinutes' => $need['endMinutes'],
                'holder' => $board['assignment']->holders[$need['id']] ?? null,
                'candidates' => $need['candidates'],
                'players' => $need['players'],
            ];
        }

        return $sessions;
    }

    public function holderOfMatch(DateTimeImmutable $date, string $fixtureId): ?string
    {
        return $this->board($date)['assignment']->holders[$this->matchNeedId($fixtureId)] ?? null;
    }

    /** Une séance sans besoin ce jour-là (son équipe est au match) ne retient personne. */
    public function slotIsANeed(DateTimeImmutable $date, string $slotId): bool
    {
        return isset($this->board($date)['needOfSlot'][$slotId]);
    }

    public function holderOfSlot(DateTimeImmutable $date, string $slotId): ?string
    {
        $board = $this->board($date);
        $needId = $board['needOfSlot'][$slotId] ?? null;

        return null === $needId ? null : ($board['assignment']->holders[$needId] ?? null);
    }

    /**
     * L'équipe du tenant quand elle diffère de celle de la séance : un groupe. Null pour un coach de
     * l'équipe ou un coach nommé hors équipe.
     */
    public function holderTeamOfSlot(DateTimeImmutable $date, string $slotId, string $slotTeamId, string $holderId): ?string
    {
        if (ConflictPersonRole::MAIN === ($this->roleByTeamPerson[$slotTeamId][$holderId] ?? null)) {
            return null;
        }
        $board = $this->board($date);
        $needId = $board['needOfSlot'][$slotId] ?? null;
        foreach (null === $needId ? [] : $board['teamsOfNeed'][$needId] as $teamId) {
            if ($teamId !== $slotTeamId && ConflictPersonRole::MAIN === ($this->roleByTeamPerson[$teamId][$holderId] ?? null)) {
                return $teamId;
            }
        }

        return null;
    }

    private function matchNeedId(string $fixtureId): string
    {
        return 'match:' . $fixtureId;
    }

    private function startMinutes(ScheduleSlotTemplate $slot): int
    {
        $start = $slot->getStartTime();

        return (int) $start->format('H') * 60 + (int) $start->format('i');
    }

    /** @param list<list<string>> $groups */
    private function sameGroup(string $teamA, string $teamB, array $groups): bool
    {
        if ($teamA === $teamB) {
            return true;
        }
        foreach ($groups as $group) {
            if (\in_array($teamA, $group, true) && \in_array($teamB, $group, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return Board */
    private function board(DateTimeImmutable $date): array
    {
        $day = $date->format('Y-m-d');
        if (isset($this->boards[$day])) {
            return $this->boards[$day];
        }

        $busy = $this->busyToPlaceByDate[$day] ?? [];
        $needs = [];
        $matches = $this->dayMatches->forDate($date, $this->views);
        foreach ($matches as $match) {
            $needs[] = [
                'id' => $this->matchNeedId($match['fixtureId']),
                'kind' => 'match',
                'teamId' => $match['teamId'],
                'startMinutes' => $match['startMinutes'],
                'endMinutes' => $match['endMinutes'],
                'candidates' => $this->freeOfMatchesFirst($this->matchCandidates($match['fixtureId'], $match['teamId']), $busy),
                'players' => $this->playersOf($match['teamId']),
            ];
        }

        $needOfSlot = [];
        $teamsOfNeed = [];
        $sessions = [];
        foreach ($this->sessionBuckets($date, $matches) as $bucket) {
            $need = $this->sessionNeed($date, $bucket);
            $need['candidates'] = $this->freeOfMatchesFirst($need['candidates'], $busy);
            $needs[] = $need;
            $sessions[] = $need;
            $teams = [];
            foreach ($bucket as $slot) {
                $needOfSlot[$slot->getId()] = $need['id'];
                $teams[] = $slot->getTeamId();
            }
            $teamsOfNeed[$need['id']] = array_values(array_unique($teams));
        }

        return $this->boards[$day] = [
            'assignment' => new DatedHolding()->assign($needs),
            'needOfSlot' => $needOfSlot,
            'teamsOfNeed' => $teamsOfNeed,
            'sessions' => $sessions,
        ];
    }

    /**
     * @param list<string>        $candidates
     * @param array<string, true> $busy
     *
     * @return list<string>
     */
    private function freeOfMatchesFirst(array $candidates, array $busy): array
    {
        $isBusy = static fn (string $person): bool => isset($busy[$person]);

        return [
            ...array_filter($candidates, static fn (string $person): bool => !$isBusy($person)),
            ...array_filter($candidates, $isBusy),
        ];
    }

    /** @return list<string> */
    private function matchCandidates(string $fixtureId, string $teamId): array
    {
        $view = $this->viewByFixture[$fixtureId];
        $kickoff = PersonConflicts::kickoffMinuteOf($view);
        $weekday = (int) $view['fixture']->getMatchDate()->format('N');
        $candidates = [];
        foreach ($this->mainsOf($teamId) as $coachId) {
            if (null === $kickoff || PersonConflicts::coachAvailableAt($coachId, $weekday, $kickoff, $this->coachUnavailabilities)) {
                $candidates[] = $coachId;
            }
        }

        return $candidates;
    }

    /** @return list<string> */
    private function mainsOf(string $teamId): array
    {
        return $this->personsWithRole($teamId, ConflictPersonRole::MAIN);
    }

    /** @return list<string> */
    private function playersOf(string $teamId): array
    {
        return $this->personsWithRole($teamId, ConflictPersonRole::PLAYER);
    }

    /** @return list<string> */
    private function personsWithRole(string $teamId, ConflictPersonRole $wanted): array
    {
        $persons = [];
        foreach ($this->roleByTeamPerson[$teamId] ?? [] as $personId => $role) {
            if ($wanted === $role) {
                $persons[] = (string) $personId;
            }
        }

        return $persons;
    }

    /**
     * Les séances-besoins de la date, les créneaux d'un groupe déclaré sur la même case réunis.
     *
     * @param list<array{fixtureId: string, teamId: string, homeAway: string, startMinutes: int, endMinutes: int, personIds: list<string>}> $matches
     *
     * @return list<non-empty-list<ScheduleSlotTemplate>>
     */
    private function sessionBuckets(DateTimeImmutable $date, array $matches): array
    {
        if (\in_array($date->format('Y-m-d'), $this->publicHolidays, true)) {
            return [];
        }
        $scheduleId = $this->effectiveScheduleResolver->resolve($date, $this->activePeriods, $this->seasonScheduleId);
        if (null === $scheduleId) {
            return [];
        }
        $weekday = (int) $date->format('N');
        $groups = $this->groupsBySchedule[$scheduleId] ?? [];

        $slots = [];
        foreach ($this->slotsBySchedule[$scheduleId] ?? [] as $slot) {
            if ($slot->getDayOfWeek() === $weekday && !$this->teamIsAtItsMatch($slot, $matches)) {
                $slots[] = $slot;
            }
        }
        usort($slots, static fn (ScheduleSlotTemplate $a, ScheduleSlotTemplate $b): int => $a->getId() <=> $b->getId());

        $buckets = [];
        foreach ($slots as $slot) {
            foreach ($buckets as $key => $bucket) {
                $first = $bucket[0];
                if ($first->getVenueId() === $slot->getVenueId() && $this->startMinutes($first) === $this->startMinutes($slot) && $this->sameGroup($first->getTeamId(), $slot->getTeamId(), $groups)) {
                    $buckets[$key][] = $slot;

                    continue 2;
                }
            }
            $buckets[] = [$slot];
        }

        return array_values($buckets);
    }

    /** @param list<array{fixtureId: string, teamId: string, homeAway: string, startMinutes: int, endMinutes: int, personIds: list<string>}> $matches */
    private function teamIsAtItsMatch(ScheduleSlotTemplate $slot, array $matches): bool
    {
        $start = $this->startMinutes($slot);
        $end = $start + $slot->getDurationMinutes();
        foreach ($matches as $match) {
            if ($match['teamId'] === $slot->getTeamId() && $start < $match['endMinutes'] && $match['startMinutes'] < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param non-empty-list<ScheduleSlotTemplate> $bucket
     *
     * @return Need
     */
    private function sessionNeed(DateTimeImmutable $date, array $bucket): array
    {
        $start = $this->startMinutes($bucket[0]);
        $end = $start;
        $candidates = [];
        $players = [];
        foreach ($bucket as $slot) {
            $end = max($end, $start + $slot->getDurationMinutes());
            $teamId = $slot->getTeamId();
            $named = $slot->getCoachId();
            if (null !== $named && ConflictPersonRole::ASSISTANT !== ($this->roleByTeamPerson[$teamId][$named] ?? null)) {
                $candidates[] = $named;
            }
            $candidates = [...$candidates, ...$this->mainsOf($teamId)];
            $players = [...$players, ...$this->playersOf($teamId)];
        }

        $scheduleId = $bucket[0]->getScheduleId();
        $weekday = (int) $date->format('N');
        $free = array_filter(
            array_unique($candidates),
            fn (string $coachId): bool => !$this->wizardBlocks($scheduleId, $coachId, $weekday, $start, $end),
        );

        return [
            'id' => 'session:' . $bucket[0]->getId(),
            'kind' => 'session',
            'teamId' => $bucket[0]->getTeamId(),
            'startMinutes' => $start,
            'endMinutes' => $end,
            'candidates' => array_values($free),
            'players' => array_values(array_unique($players)),
        ];
    }

    private function wizardBlocks(string $scheduleId, string $coachId, int $weekday, int $start, int $end): bool
    {
        foreach ($this->wizardBlockedBySchedule[$scheduleId][$coachId] ?? [] as [$day, $from, $until]) {
            if ($day === $weekday && $start < $until && $from < $end) {
                return true;
            }
        }

        return false;
    }
}
