<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coach;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\ScheduleDiagnosticSeverity;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds and persists ScheduleDiagnostic rows from an engine result (BCK-04:
 * extracted from GenerateScheduleHandler). Only persists — the caller owns the
 * flush, keeping the generation's unit-of-work boundary intact.
 */
final class ScheduleDiagnosticsRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DiagnosticMessageBuilder $diagnosticMessageBuilder,
    ) {}

    /** Remove diagnostics from previous generation runs of this schedule. */
    public function purgePrevious(Schedule $schedule): void
    {
        $old = $this->entityManager->getRepository(ScheduleDiagnostic::class)->findBy(['scheduleId' => $schedule->getId()]);
        foreach ($old as $diagnostic) {
            $this->entityManager->remove($diagnostic);
        }
    }

    public function recordSingle(Schedule $schedule, string $type, ScheduleDiagnosticSeverity $severity, string $message): void
    {
        $this->entityManager->persist(
            (new ScheduleDiagnostic)
                ->setClubId($schedule->getClubId())
                ->setSeasonId($schedule->getSeasonId())
                ->setScheduleId($schedule->getId())
                ->setType($type)
                ->setSeverity($severity)
                ->setMessage($message)
                ->setSuggestions([]),
        );
    }

    /** @param array<string, mixed> $result */
    public function record(Schedule $schedule, array $result): void
    {
        $diagnostics = $result['diagnostics'] ?? [];
        if (!\is_array($diagnostics) || [] === $diagnostics) {
            $diagnostics = $this->buildFallbackDiagnostics($result);
        }

        if ([] === $diagnostics) {
            // No diagnostics at all. On a *failed* result, surface a generic
            // error so the manager sees something. On a *completed* result,
            // absence of diagnostics simply means "no issue detected" — a clean
            // plan must NOT carry a spurious engine_failed error.
            $engineStatus = strtolower((string) ($result['status'] ?? 'failed'));
            if ('completed' !== $engineStatus) {
                $this->recordSingle($schedule, 'engine_failed', ScheduleDiagnosticSeverity::ERROR, (string) ($result['message'] ?? 'Schedule generation failed.'));
            }

            return;
        }

        [$teamNames, $coachNames, $venueNames] = $this->buildNameMaps($schedule);

        foreach ($diagnostics as $diagnostic) {
            if (!\is_array($diagnostic)) {
                $this->recordSingle($schedule, 'engine_failed', ScheduleDiagnosticSeverity::ERROR, (string) $diagnostic);
                continue;
            }

            $message = $this->diagnosticMessageBuilder->build($diagnostic, $teamNames, $coachNames, $venueNames);

            $entity = (new ScheduleDiagnostic)
                ->setClubId($schedule->getClubId())
                ->setSeasonId($schedule->getSeasonId())
                ->setScheduleId($schedule->getId())
                ->setType((string) ($diagnostic['type'] ?? 'engine_failed'))
                ->setSeverity(ScheduleDiagnosticSeverity::tryFrom((string) ($diagnostic['severity'] ?? 'ERROR')) ?? ScheduleDiagnosticSeverity::ERROR)
                ->setMessage($message)
                ->setSuggestions(\is_array($diagnostic['suggestions'] ?? null) ? $diagnostic['suggestions'] : []);

            if (isset($diagnostic['team_id']) || isset($diagnostic['teamId'])) {
                $entity->setTeamId((string) ($diagnostic['team_id'] ?? $diagnostic['teamId']));
            }
            if (isset($diagnostic['coach_id']) || isset($diagnostic['coachId'])) {
                $entity->setCoachId((string) ($diagnostic['coach_id'] ?? $diagnostic['coachId']));
            }
            if (isset($diagnostic['venue_id']) || isset($diagnostic['venueId'])) {
                $entity->setVenueId((string) ($diagnostic['venue_id'] ?? $diagnostic['venueId']));
            }
            // Slot pinpoint (conflict only): day + start time so the UI can open THAT
            // grid cell. Dual-casing like the ids above (engine sends camelCase, a raw
            // payload may use snake_case).
            if (isset($diagnostic['day_of_week']) || isset($diagnostic['dayOfWeek'])) {
                $entity->setDayOfWeek((int) ($diagnostic['day_of_week'] ?? $diagnostic['dayOfWeek']));
            }
            if (isset($diagnostic['start_time']) || isset($diagnostic['startTime'])) {
                $entity->setStartTime((string) ($diagnostic['start_time'] ?? $diagnostic['startTime']));
            }
            // `implicit_rule_not_honored` carries the wellness rule it concerns (contract
            // ruleKey). Dual-casing like the ids above (engine sends camelCase).
            if (isset($diagnostic['rule_key']) || isset($diagnostic['ruleKey'])) {
                $entity->setRuleKey((string) ($diagnostic['rule_key'] ?? $diagnostic['ruleKey']));
            }
            // `session_below_effective_min` carries the MEASURED cause of a missing session, and
            // P4-96 a `conflict` on INFEASIBLE carries its own: `diag-infeasible` holds the
            // conflicting-rule core, each `diag-infeasible-team-*` the team's closed-candidate
            // aggregate. Both arrive as `causes` (a list of {kind, constraintId, label, count});
            // this generic branch persists them identically — no per-type code. `openCandidates`
            // (slots left open, none closed them) rides alongside only on session_below. Each
            // cause's constraintId is NORMALISED to the entity UUID — see normalizeCauses.
            // openCandidates is dual-cased; `isset` deliberately treats a null value as absent,
            // preserving null (not measured) vs 0 (nothing stayed open).
            if (isset($diagnostic['causes'])) {
                $entity->setCauses($this->normalizeCauses($diagnostic['causes']));
            }
            if (isset($diagnostic['open_candidates']) || isset($diagnostic['openCandidates'])) {
                $entity->setOpenCandidates((int) ($diagnostic['open_candidates'] ?? $diagnostic['openCandidates']));
            }

            $this->entityManager->persist($entity);
        }
    }

    /**
     * Normalise the MEASURED causes of a `session_below_effective_min` diagnostic.
     *
     * The backend is what suffixed a CLUB constraint into N TEAM rows with an id
     * `<uuid>:<teamId>` (ScheduleConstraintBuilder:888/:930) or `<uuid>:forbidden:<teamId>`
     * (:909); the engine echoes THAT suffixed id back on the cause. The wizard deep-link
     * `?edit=<id>` matches on the entity UUID, so the backend strips the suffix here —
     * symmetry, and the only home that knows it added it. Cutting at the first ':' is safe:
     * a UUID never contains one. The bare id (no suffix, a real TEAM/COACH constraint) is
     * left untouched. `priority-tier:%d` (:827) is SOFT and never surfaces as a cause; were
     * it ever to, this would truncate it to `priority-tier` — a non-resolvable id, i.e. a
     * visible no-match, not a wrong match (flagged in the plan, not worked around here).
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeCauses(mixed $causes): array
    {
        if (!\is_array($causes)) {
            return [];
        }

        $normalized = [];
        foreach ($causes as $cause) {
            if (!\is_array($cause)) {
                continue;
            }

            /** @var array<string, mixed> $cause */
            // constraintId dual-cased (engine sends camelCase); store the normalised id back
            // under camelCase to match the serialised contract shape the frontend reads.
            $rawId = $cause['constraintId'] ?? $cause['constraint_id'] ?? null;
            unset($cause['constraint_id']);
            if (null !== $rawId) {
                $id = (string) $rawId;
                $colon = strpos($id, ':');
                $cause['constraintId'] = false === $colon ? $id : substr($id, 0, $colon);
            }

            $normalized[] = $cause;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<int, array{type: string, severity: string, teamId: string, message: string, suggestions: list<string>}>
     */
    private function buildFallbackDiagnostics(array $result): array
    {
        $unplaced = $result['unplaced'] ?? [];
        if (!\is_array($unplaced) || [] === $unplaced) {
            return [];
        }

        $diagnostics = [];
        foreach ($unplaced as $item) {
            $teamId = null;
            if (\is_array($item)) {
                $teamId = isset($item['teamId']) ? (string) $item['teamId'] : (isset($item['team_id']) ? (string) $item['team_id'] : null);
            } elseif (\is_string($item) || \is_int($item)) {
                $teamId = (string) $item;
            }

            if (null === $teamId || '' === $teamId) {
                continue;
            }

            $diagnostics[] = [
                'type' => 'unplaced',
                'severity' => 'WARNING',
                'teamId' => $teamId,
                'message' => \sprintf('Team %s could not be placed in the schedule.', $teamId),
                'suggestions' => [
                    'Add more venue availability or relax hard constraints.',
                    'Check that the team has at least one feasible time slot.',
                ],
            ];
        }

        return $diagnostics;
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, string>}
     */
    private function buildNameMaps(Schedule $schedule): array
    {
        $criteria = [
            'clubId' => $schedule->getClubId(),
            'seasonId' => $schedule->getSeasonId(),
        ];

        $teamNames = [];
        foreach ($this->entityManager->getRepository(Team::class)->findBy($criteria) as $team) {
            $teamNames[$team->getId()] = $team->getName();
        }

        $coachNames = [];
        foreach ($this->entityManager->getRepository(Coach::class)->findBy($criteria) as $coach) {
            $coachNames[$coach->getId()] = trim($coach->getFirstName() . ' ' . $coach->getLastName());
        }

        $venueNames = [];
        foreach ($this->entityManager->getRepository(Venue::class)->findBy($criteria) as $venue) {
            $venueNames[$venue->getId()] = $venue->getName();
        }

        return [$teamNames, $coachNames, $venueNames];
    }
}
