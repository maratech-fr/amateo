<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Repository\ConstraintRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Décision fondateur 2026-10-30 : un coach à deux endroits en même temps, sans autre coach pour tenir la
 * séance, BLOQUE la génération. Le récap le disait, mais `/generate`, `/regenerate` et `/fill` ne passent
 * pas par lui : cette garde porte la promesse sur les guichets. Même périmètre de réservations et de
 * contraintes que ce qui part au solveur, même détecteur que le récap.
 */
final class CoachInTwoPlacesGuard
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly CoachDoubleBookingDetector $detector,
        private readonly ConstraintRepository $constraintRepository,
        private readonly PeriodConstraintSelector $periodConstraintSelector,
    ) {}

    /** La phrase qui nomme le coach et les deux gymnases, ou null si rien ne bloque. */
    public function firstConflictMessage(Schedule $schedule): ?string
    {
        $clubId = (string) $schedule->getClubId();
        $seasonId = (string) $schedule->getSeasonId();
        $repository = $this->entityManager->getRepository(Reservation::class);

        if ($this->schedulePlanProvisioner->isSeasonSchedule($schedule)) {
            $reservations = $repository->findBy(['clubId' => $clubId, 'seasonId' => $seasonId, 'schedulePlanId' => null], ['id' => 'ASC']);
            $constraints = $this->constraintRepository->findPermanentByClubSeason($clubId, $seasonId);
        } else {
            $planId = (string) $schedule->getSchedulePlanId();
            $entryId = $this->schedulePlanProvisioner->fetchPlanContext($planId)['calendarEntryId'] ?? null;
            $entry = null !== $entryId ? $this->entityManager->getRepository(CalendarEntry::class)->find($entryId) : null;
            if (!$entry instanceof CalendarEntry) {
                return null;
            }
            $selection = $this->periodConstraintSelector->selectForPeriodPlan($clubId, $seasonId, $planId, $entry);
            $constraints = $selection->kept;
            $reservations = array_values(array_filter(
                $repository->findBy(['schedulePlanId' => $planId], ['id' => 'ASC']),
                static fn (Reservation $reservation): bool => !isset($selection->disabledVenueIds[$reservation->getVenueId()]),
            ));
        }

        $conflicts = $this->detector->detect($reservations, $clubId, $seasonId, $constraints);

        return [] === $conflicts ? null : $this->detector->describeForRecap($conflicts[0]);
    }
}
