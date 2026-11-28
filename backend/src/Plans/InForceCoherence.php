<?php

declare(strict_types=1);

namespace App\Plans;

use App\Entity\Schedule;
use App\Service\MoveSlotService;
use App\Support\WeeklySlot;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * Le contrôle d'incohérences avant « Valider ces modifications » (lot 5) : les séances du planning en vigueur que
 * touchent les modifications en attente sont soumises, à leur place actuelle, au verdict du moteur avec le froid
 * modifié, le reste du planning figé ({@see MoveSlotService::verdictFor}). Il rend chaque règle cassée, nommée avec sa
 * séance ; les séances manquantes par équipe quand le nombre de séances a changé ; ou « non concluant ».
 *
 * Il découpe : {@see self::BATCH_SIZE} séances par demande (la borne du verdict), {@see self::MAX_BATCHES} demandes au
 * plus. Au-delà, ou sur un refus, une erreur ou un délai du moteur, il répond « non concluant », sans lever. Il
 * avertit, il ne bloque jamais ; il ne voit ni les préférences souples ni la qualité du planning.
 *
 * Quelles séances sont touchées ({@see self::touchedSlotIds}), ligne par ligne de la différence de copies :
 *  - coach principal, coach-joueur : celles de l'équipe, et de toute équipe à laquelle l'ancienne ou la nouvelle
 *    personne est liée ;
 *  - règle d'une équipe, d'un coach, d'un gymnase : celles de l'équipe, des équipes du coach, placées dans le
 *    gymnase ; règle de club, règle de trajet, réglage de bien-être (et toute famille sans cible) : toutes ;
 *  - créneau de grille : celles placées dans ce gymnase ce jour-là qui le chevauchent, avant ou après ;
 *  - réservation : celles de l'équipe réservée, et celles placées dans ce gymnase ce jour-là qui la chevauchent ;
 *  - participation, nombre de séances : celles de l'équipe ; passerelle, mutualisation : celles des équipes liées ;
 *  - catégorie, rang, gymnase imposé d'une équipe ; « divisible » d'un gymnase : celles de l'équipe, celles placées
 *    dans le gymnase.
 */
final readonly class InForceCoherence
{
    public const int BATCH_SIZE = 50;

    public const int MAX_BATCHES = 6;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PendingChanges $pendingChanges,
        private PlanStates $planStates,
        private MoveSlotService $moveSlotService,
    ) {}

    /**
     * @return array{incoherences: list<array{slotId: string, teamId: string, teamName: string, dayOfWeek: int, startTime: string, rule: string, label: string}>, missingSessions: list<array{teamId: string, teamName: string, count: int}>, inconclusive: bool}
     */
    public function check(string $planId): array
    {
        $inForceId = $this->inForceIdOf($planId);
        if (null === $inForceId) {
            return ['incoherences' => [], 'missingSessions' => [], 'inconclusive' => false];
        }
        $changes = $this->pendingChanges->changesOf($planId, $inForceId);
        $sessions = $this->sessionsOf($inForceId);
        $teamNames = $this->teamNames($planId);
        $missing = $this->missingSessions($changes, $sessions, $teamNames);

        $touched = $this->touchedSlotIds($planId, $changes);
        if ([] === $touched) {
            return ['incoherences' => [], 'missingSessions' => $missing, 'inconclusive' => false];
        }
        if (\count($touched) > self::BATCH_SIZE * self::MAX_BATCHES) {
            return ['incoherences' => [], 'missingSessions' => $missing, 'inconclusive' => true];
        }
        $version = $this->entityManager->find(Schedule::class, $inForceId);
        if (!$version instanceof Schedule) {
            return ['incoherences' => [], 'missingSessions' => $missing, 'inconclusive' => true];
        }

        $incoherences = [];
        foreach (array_chunk($touched, self::BATCH_SIZE) as $batch) {
            try {
                $verdict = $this->moveSlotService->verdictFor($version, $batch);
            } catch (Throwable) {
                return ['incoherences' => [], 'missingSessions' => $missing, 'inconclusive' => true];
            }
            if ($verdict['indeterminate']) {
                return ['incoherences' => [], 'missingSessions' => $missing, 'inconclusive' => true];
            }
            foreach ($verdict['violations'] as $violation) {
                $slotId = $this->sessionOfViolation($violation, $batch, $sessions);
                $session = $sessions[$slotId];
                $incoherences[$slotId . '|' . $violation['rule']] = [
                    'slotId' => $slotId,
                    'teamId' => $session['teamId'],
                    'teamName' => $teamNames[$session['teamId']] ?? '',
                    'dayOfWeek' => $session['dayOfWeek'],
                    'startTime' => $session['startTime'],
                    'rule' => $violation['rule'],
                    'label' => '' !== $violation['message'] ? $violation['message'] : $violation['rule'],
                ];
            }
        }

        return ['incoherences' => array_values($incoherences), 'missingSessions' => $missing, 'inconclusive' => false];
    }

    /**
     * Les séances du planning en vigueur que touchent ces modifications, triées.
     *
     * @return list<string>
     */
    public function touchedSlotIds(string $planId, PlanChanges $changes): array
    {
        $inForceId = $this->inForceIdOf($planId);
        if (null === $inForceId) {
            return [];
        }
        $sessions = $this->sessionsOf($inForceId);
        $before = $this->planStates->of($inForceId)->toArray();
        $after = $this->planStates->capture($planId)->toArray();
        $all = array_keys($sessions);

        $teams = [];
        $venues = [];
        $windows = [];
        $everything = false;
        foreach ($changes->rows() as $family => $rows) {
            foreach ($rows as $rowId => [$old, $new]) {
                foreach (array_filter([$old, $new], is_array(...)) as $row) {
                    switch ($family) {
                        case 'TeamCoach':
                        case 'CoachPlayerMembership':
                            $teams[] = (string) ($row['teamId'] ?? '');
                            array_push($teams, ...$this->teamsOfPerson((string) ($row['coachId'] ?? ''), $before, $after));
                            break;
                        case 'Constraint':
                            $this->touchByRule($row, $before, $after, $teams, $venues, $everything);
                            break;
                        case 'PlanConstraint':
                            $constraintId = (string) ($row['constraintId'] ?? '');
                            $this->touchByRule($before['Constraint'][$constraintId] ?? $after['Constraint'][$constraintId] ?? [], $before, $after, $teams, $venues, $everything);
                            break;
                        case 'VenueTrainingSlot':
                            $windows[] = $row;
                            break;
                        case 'Reservation':
                            $teams[] = (string) ($row['teamId'] ?? '');
                            $windows[] = $row;
                            break;
                        case 'PlanTeam':
                        case 'SharedTrainingBlockTeam':
                            $teams[] = (string) ($row['teamId'] ?? '');
                            break;
                        case 'TeamLink':
                            $teams[] = (string) ($row['teamAId'] ?? '');
                            $teams[] = (string) ($row['teamBId'] ?? '');
                            break;
                        case 'SharedTrainingBlock':
                            foreach ([$before, $after] as $state) {
                                foreach ($state['SharedTrainingBlockTeam'] ?? [] as $member) {
                                    if (($member['blockId'] ?? null) === (string) $rowId) {
                                        $teams[] = (string) ($member['teamId'] ?? '');
                                    }
                                }
                            }
                            break;
                        case 'Team':
                            $teams[] = (string) $rowId;
                            $venues[] = (string) ($row['forcedVenueId'] ?? '');
                            break;
                        case 'Venue':
                            $venues[] = (string) $rowId;
                            break;
                        case 'PlanVenue':
                            $venues[] = (string) ($row['venueId'] ?? '');
                            break;
                        default:
                            // Règle de trajet, réglage de bien-être, toute famille sans cible : tout le planning.
                            $everything = true;
                    }
                }
            }
        }
        if ($everything) {
            sort($all, \SORT_STRING);

            return $all;
        }

        $teams = array_fill_keys(array_filter($teams, static fn (string $id): bool => '' !== $id), true);
        $venues = array_fill_keys(array_filter($venues, static fn (string $id): bool => '' !== $id), true);
        $touched = [];
        foreach ($sessions as $id => $session) {
            if (isset($teams[$session['teamId']]) || isset($venues[$session['venueId']]) || $this->inAnyWindow($session, $windows)) {
                $touched[] = (string) $id;
            }
        }
        sort($touched, \SORT_STRING);

        return $touched;
    }

    /**
     * Une règle (sa portée et sa cible) : une règle de club touche tout ; d'une équipe, ses séances ; d'un coach, celles
     * de ses équipes ; d'un gymnase, celles qui y sont placées. Une règle introuvable, ou sans cible, touche tout.
     *
     * @param array<string, mixed>                               $rule
     * @param array<string, array<string, array<string, mixed>>> $before
     * @param array<string, array<string, array<string, mixed>>> $after
     * @param list<string>                                       $teams
     * @param list<string>                                       $venues
     */
    private function touchByRule(array $rule, array $before, array $after, array &$teams, array &$venues, bool &$everything): void
    {
        $target = (string) ($rule['scopeTargetId'] ?? '');
        $scope = $rule['scope'] ?? null;
        if ('' === $target || !\in_array($scope, ['TEAM', 'COACH', 'FACILITY'], true)) {
            $everything = true;
        } elseif ('TEAM' === $scope) {
            $teams[] = $target;
        } elseif ('COACH' === $scope) {
            array_push($teams, ...$this->teamsOfPerson($target, $before, $after));
        } else {
            $venues[] = $target;
        }
    }

    /**
     * Les équipes auxquelles une personne est liée (coach principal ou joueur), dans la copie en vigueur ou dans
     * l'état de travail.
     *
     * @param array<string, array<string, array<string, mixed>>> $before
     * @param array<string, array<string, array<string, mixed>>> $after
     *
     * @return list<string>
     */
    private function teamsOfPerson(string $coachId, array $before, array $after): array
    {
        if ('' === $coachId) {
            return [];
        }
        $teams = [];
        foreach ([$before, $after] as $state) {
            foreach (['TeamCoach', 'CoachPlayerMembership'] as $family) {
                foreach ($state[$family] ?? [] as $row) {
                    if (($row['coachId'] ?? null) === $coachId) {
                        $teams[] = (string) ($row['teamId'] ?? '');
                    }
                }
            }
        }

        return $teams;
    }

    /**
     * La séance placée dans ce gymnase ce jour-là chevauche-t-elle l'un de ces horaires (créneau ou réservation) ?
     *
     * @param array{teamId: string, venueId: string, dayOfWeek: int, startTime: string, startMinutes: int, durationMinutes: int} $session
     * @param list<array<string, mixed>>                                                                                         $windows
     */
    private function inAnyWindow(array $session, array $windows): bool
    {
        foreach ($windows as $window) {
            if (($window['venueId'] ?? null) !== $session['venueId'] || (int) ($window['dayOfWeek'] ?? 0) !== $session['dayOfWeek'] || !\is_string($window['startTime'] ?? null)) {
                continue;
            }
            $start = WeeklySlot::minutesOf(new DateTimeImmutable($window['startTime']));
            if (WeeklySlot::intervalsOverlap($start, $start + (int) ($window['durationMinutes'] ?? 0), $session['startMinutes'], $session['startMinutes'] + $session['durationMinutes'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les séances manquantes par équipe dont la participation ou le nombre de séances a changé : celles que le plan
     * demande désormais, moins celles que le planning en vigueur place.
     *
     * @param array<string, array{teamId: string, venueId: string, dayOfWeek: int, startTime: string, startMinutes: int, durationMinutes: int}> $sessions
     * @param array<string, string>                                                                                                             $teamNames
     *
     * @return list<array{teamId: string, teamName: string, count: int}>
     */
    private function missingSessions(PlanChanges $changes, array $sessions, array $teamNames): array
    {
        $placed = [];
        foreach ($sessions as $session) {
            $placed[$session['teamId']] = ($placed[$session['teamId']] ?? 0) + 1;
        }
        $missing = [];
        foreach ($changes->rows()['PlanTeam'] ?? [] as [, $line]) {
            if (!\is_array($line) || true !== ($line['participates'] ?? true)) {
                continue;
            }
            $teamId = (string) ($line['teamId'] ?? '');
            $count = (int) ($line['sessionsPerWeek'] ?? 0) - ($placed[$teamId] ?? 0);
            if ($count > 0 && isset($teamNames[$teamId])) {
                $missing[$teamId] = ['teamId' => $teamId, 'teamName' => $teamNames[$teamId], 'count' => $count];
            }
        }
        ksort($missing, \SORT_STRING);

        return array_values($missing);
    }

    /**
     * La séance que nomme une règle cassée : celle de la demande à l'équipe, au jour et à l'heure du verdict ; à défaut,
     * la première de l'équipe (ou de l'équipe en conflit) ; à défaut, la première de la demande. Une incohérence n'est
     * jamais tue faute de séance exacte.
     *
     * @param array{rule: string, message: string, teamId: ?string, coachId: ?string, venueId: ?string, dayOfWeek: ?int, startTime: ?string, conflictingTeamId: ?string} $violation
     * @param list<string>                                                                                                                                               $batch
     * @param array<string, array{teamId: string, venueId: string, dayOfWeek: int, startTime: string, startMinutes: int, durationMinutes: int}>                          $sessions
     */
    private function sessionOfViolation(array $violation, array $batch, array $sessions): string
    {
        $start = null === $violation['startTime'] ? null : substr($violation['startTime'], 0, 5);
        foreach ($batch as $id) {
            $session = $sessions[$id];
            if ($session['teamId'] === $violation['teamId'] && $session['dayOfWeek'] === $violation['dayOfWeek'] && $session['startTime'] === $start) {
                return $id;
            }
        }
        foreach ([$violation['teamId'], $violation['conflictingTeamId']] as $teamId) {
            foreach ($batch as $id) {
                if (null !== $teamId && $sessions[$id]['teamId'] === $teamId) {
                    return $id;
                }
            }
        }

        return $batch[0];
    }

    private function inForceIdOf(string $planId): ?string
    {
        $chosen = $this->entityManager->getConnection()->fetchOne('SELECT chosen_schedule_id FROM schedule_plan WHERE id = ?', [$planId]);

        return \is_string($chosen) ? $chosen : null;
    }

    /**
     * Les séances d'une version, par id.
     *
     * @return array<string, array{teamId: string, venueId: string, dayOfWeek: int, startTime: string, startMinutes: int, durationMinutes: int}>
     */
    private function sessionsOf(string $scheduleId): array
    {
        $sessions = [];
        foreach ($this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id, team_id, venue_id, day_of_week, start_time, duration_minutes FROM schedule_slot_template WHERE schedule_id = ? ORDER BY id',
            [$scheduleId],
        ) as $row) {
            $start = new DateTimeImmutable((string) $row['start_time']);
            $sessions[(string) $row['id']] = [
                'teamId' => (string) $row['team_id'],
                'venueId' => (string) $row['venue_id'],
                'dayOfWeek' => (int) $row['day_of_week'],
                'startTime' => $start->format('H:i'),
                'startMinutes' => WeeklySlot::minutesOf($start),
                'durationMinutes' => (int) $row['duration_minutes'],
            ];
        }

        return $sessions;
    }

    /** @return array<string, string> les noms des équipes de la saison du plan, par id */
    private function teamNames(string $planId): array
    {
        $names = [];
        foreach ($this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT t.id, t.name FROM team t JOIN schedule_plan p ON p.season_id = t.season_id AND p.club_id = t.club_id WHERE p.id = ?',
            [$planId],
        ) as $row) {
            $names[(string) $row['id']] = (string) $row['name'];
        }

        return $names;
    }
}
