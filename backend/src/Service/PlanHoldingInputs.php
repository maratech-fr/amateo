<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\SharedTrainingBlockTeam;
use App\Enum\CalendarEntryPeriodType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce que le plan de chaque version dit de qui peut tenir une séance : ses indisponibilités
 * d'entraînement (saison : les permanentes ; période : le jeu que la génération reçoit) et ses
 * groupes déclarés (saison : sans plan ; période : ceux du plan, jamais un mélange). Lu par le radar
 * et par le payload de placement : les deux jugent une séance sur les mêmes données.
 */
final class PlanHoldingInputs
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PeriodConstraintSelector $periodConstraintSelector,
    ) {}

    /**
     * @param list<string> $scheduleIds
     *
     * @return array{0: array<string, array<string, list<array{0: int, 1: int, 2: int}>>>, 1: array<string, list<list<string>>>}
     */
    public function load(string $clubId, ?string $seasonId, array $scheduleIds): array
    {
        if (null === $seasonId) {
            return [[], []];
        }

        $teamsByPlanBlock = [];
        foreach ($this->entityManager->getRepository(SharedTrainingBlockTeam::class)->findBy([]) as $member) {
            $teamsByPlanBlock[$member->getSchedulePlanId() ?? ''][$member->getBlockId()][] = $member->getTeamId();
        }
        $permanent = $this->entityManager->getRepository(Constraint::class)->findPermanentByClubSeason($clubId, $seasonId);

        $blocked = [];
        $groups = [];
        foreach ($scheduleIds as $scheduleId) {
            $schedule = $this->entityManager->getRepository(Schedule::class)->find($scheduleId);
            if (!$schedule instanceof Schedule) {
                continue;
            }
            $planId = $schedule->getSchedulePlanId();
            $entryId = $this->entityManager->getRepository(SchedulePlan::class)->find($planId)?->getCalendarEntryId();
            $entry = null !== $entryId ? $this->entityManager->getRepository(CalendarEntry::class)->find($entryId) : null;

            if ($entry instanceof CalendarEntry && \in_array($entry->getPeriodType(), [CalendarEntryPeriodType::CLOSURE, CalendarEntryPeriodType::HOLIDAY], true)) {
                $blocked[$scheduleId] = CoachDoubleBookingDetector::blockedIntervalsByCoach($this->periodConstraintSelector->selectForPeriodPlan($clubId, $seasonId, $planId, $entry)->kept);
                $groups[$scheduleId] = array_values($teamsByPlanBlock[$planId] ?? []);
            } else {
                $blocked[$scheduleId] = CoachDoubleBookingDetector::blockedIntervalsByCoach($permanent);
                $groups[$scheduleId] = array_values($teamsByPlanBlock[''] ?? []);
            }
        }

        return [$blocked, $groups];
    }
}
