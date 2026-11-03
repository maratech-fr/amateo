<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\CalendarEntryPeriodType;
use App\Repository\CalendarEntryRepository;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Loads the tenant-scoped context EffectiveScheduleResolver::resolve() needs:
 * the season's chosen calendar, the ordered active periods with their overlay
 * (chosen version of their plan, else null = no training), and the slots of
 * every involved schedule. One home — the conflicts radar and the
 * venue-unavailability impact must read the SAME picture (P1-4 PR B).
 *
 * Repositories go through the Doctrine club+season filters: the context is
 * always the caller's club.
 */
final class TrainingCalendarContext
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CalendarEntryRepository $calendarEntryRepository,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly PlanVenueClosures $planVenueClosures,
    ) {}

    /**
     * Fermetures de gymnase (C2) — chaque période porte aussi son TYPE (une fermeture sans version ne
     * capture plus) et les jours que son plan ROUVRE ; `closedDatesByVenue` est l'union de toutes les
     * fermetures déclarées du club (fait brut, chargé une fois). `$ignoredEntryId` écarte la FAMILLE
     * de la fermeture interrogée (aperçu avant « Enregistrer » == impact relu après).
     *
     * @return array{
     *     seasonScheduleId: string|null,
     *     activePeriods: list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null, periodType: CalendarEntryPeriodType|null, reopenedWeekdaysByVenue: array<string, array<int, true>>}>,
     *     slotsBySchedule: array<string, list<ScheduleSlotTemplate>>,
     *     closedDatesByVenue: array<string, array<string, true>>,
     * }
     */
    public function load(?string $seasonId, ?string $ignoredEntryId = null): array
    {
        // ADR-0002 : le calendrier de base = la version CHOISIE du plan SEASON.
        // null = espace de travail : rien n'est arrêté.
        $seasonScheduleId = $this->schedulePlanProvisioner->chosenOfSeasonPlan($seasonId);

        // Active period entries capture the dates they cover (ordered so
        // overlapping periods resolve deterministically); overlay = the chosen
        // version of the period's plan, resolved in ONE query for all periods.
        $activePeriods = [];
        $scheduleIds = null !== $seasonScheduleId ? [$seasonScheduleId] : [];
        $periods = $this->calendarEntryRepository->findActivePeriodsOrdered();
        $entryIds = array_map(static fn (CalendarEntry $p): string => $p->getId(), $periods);
        $overlayByEntry = $this->schedulePlanProvisioner->chosenByPeriodPlans($entryIds);
        // Les jours ROUVERTS ne comptent que pour un plan POINTÉ (c'est lui qui gouverne la date) :
        // une seule requête `IN` pour les surcharges de tous ces plans.
        $planIdByEntry = [];
        $pointedEntryIds = array_keys($overlayByEntry);
        if ([] !== $pointedEntryIds) {
            foreach ($this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT id, calendar_entry_id FROM schedule_plan WHERE calendar_entry_id IN (:ids)',
                ['ids' => array_map(strval(...), $pointedEntryIds)],
                ['ids' => ArrayParameterType::STRING],
            ) as $row) {
                $planIdByEntry[(string) $row['calendar_entry_id']] = (string) $row['id'];
            }
        }
        $reopenedByPlan = $this->planVenueClosures->reopenedWeekdaysByPlans(array_values($planIdByEntry));
        foreach ($periods as $period) {
            $overlayId = $overlayByEntry[$period->getId()] ?? null;
            $planId = $planIdByEntry[$period->getId()] ?? null;
            $activePeriods[] = [
                'start' => $period->getStartDate(),
                'end' => $period->getEndDate(),
                'scheduleId' => $overlayId,
                'periodType' => $period->getPeriodType(),
                'reopenedWeekdaysByVenue' => null !== $planId ? ($reopenedByPlan[$planId] ?? []) : [],
            ];
            if (null !== $overlayId) {
                $scheduleIds[] = $overlayId;
            }
        }

        $slotsBySchedule = [];
        if ([] !== $scheduleIds) {
            /** @var list<ScheduleSlotTemplate> $slots */
            $slots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy([
                'scheduleId' => array_values(array_unique($scheduleIds)),
            ]);
            foreach ($slots as $slot) {
                $slotsBySchedule[$slot->getScheduleId()][] = $slot;
            }
        }

        $closedDatesByVenue = [];
        if (null !== $seasonId) {
            $clubId = $this->entityManager->getConnection()->fetchOne('SELECT club_id FROM season WHERE id = :sid', ['sid' => $seasonId]);
            if (\is_string($clubId)) {
                $closedDatesByVenue = $this->planVenueClosures->closedDatesByVenue($clubId, $seasonId, $ignoredEntryId);
            }
        }

        return [
            'seasonScheduleId' => $seasonScheduleId,
            'activePeriods' => $activePeriods,
            'slotsBySchedule' => $slotsBySchedule,
            'closedDatesByVenue' => $closedDatesByVenue,
        ];
    }
}
