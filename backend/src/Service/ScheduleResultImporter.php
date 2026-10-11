<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

final class ScheduleResultImporter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    /** @param array<string, mixed> $solverOutput */
    public function import(Schedule $schedule, array $solverOutput): void
    {
        // Match THIS schedule's own rows only, keyed by PLACEMENT (team:venue:day:
        // start) — not by the engine's slot id. The engine id is deterministic on
        // the placement alone, so two schedules sharing a placement would collide
        // on the primary key (P0-5 theft). Placement-keying preserves a schedule's
        // HARD locks across regeneration while a fresh, per-schedule row id
        // (SlotIdScoper) lets sibling versions hold the same placement side by side.
        // This is transition-safe: a legacy row keyed on the old global id is still
        // matched by its placement, so no data migration is required.
        // Un import de résultat solveur RÉALIGNE le placement sur le score : le marqueur
        // « retouché à la main depuis la génération » retombe donc à false (le score
        // affiché redevient fidèle au planning). F2b l'a posé sur un déplacement manuel.
        $schedule->setManuallyEditedSinceGeneration(false);

        $existingSlots = $this->entityManager
            ->getRepository(ScheduleSlotTemplate::class)
            ->findBy(['scheduleId' => $schedule->getId()]);

        $existingByPlacement = [];
        foreach ($existingSlots as $slot) {
            $existingByPlacement[$this->placementKey($slot->getTeamId(), $slot->getVenueId(), $slot->getDayOfWeek(), $slot->getStartTime()->format('H:i'))] = $slot;
        }

        $reservationPlacements = $this->reservationPlacements($schedule);
        $manualPlacements = $this->sourceManualPlacements($schedule);

        $solverSlots = $this->deduplicateNoneSlots($solverOutput['slots'] ?? []);
        $keptPlacements = [];
        foreach ($solverSlots as $slotData) {
            $keptPlacements[$this->placementKeyFromData($slotData)] = true;
        }

        foreach ($existingSlots as $slot) {
            $placement = $this->placementKey($slot->getTeamId(), $slot->getVenueId(), $slot->getDayOfWeek(), $slot->getStartTime()->format('H:i'));
            if (LockLevel::NONE === $slot->getLockLevel() && !isset($keptPlacements[$placement])) {
                $this->entityManager->remove($slot);
            }
        }

        foreach ($solverSlots as $engineId => $slotData) {
            $existingSlot = $existingByPlacement[$this->placementKeyFromData($slotData)] ?? null;
            // A HARD-locked row of this schedule is preserved untouched (its manual
            // metadata survives) — the engine re-emits it at the same placement.
            if (null !== $existingSlot && LockLevel::NONE !== $existingSlot->getLockLevel()) {
                continue;
            }

            $slot = $existingSlot ?? (new ScheduleSlotTemplate)->setId(SlotIdScoper::scope($schedule->getId(), (string) $engineId));
            $this->hydrateSlot($slot, $schedule, $slotData, $reservationPlacements, $manualPlacements);

            if (null === $existingSlot) {
                $this->entityManager->persist($slot);
            }
        }

        $this->entityManager->flush();
    }

    /** Canonical placement key (team:venue:day:HH:MM) — the identity of a slot within a schedule. */
    private function placementKey(string $teamId, string $venueId, int $dayOfWeek, string $startTime): string
    {
        return \sprintf('%s:%s:%d:%s', $teamId, $venueId, $dayOfWeek, substr($startTime, 0, 5));
    }

    /** @param array<string, mixed> $slotData */
    private function placementKeyFromData(array $slotData): string
    {
        return $this->placementKey(
            (string) $slotData['teamId'],
            (string) $slotData['venueId'],
            (int) $slotData['dayOfWeek'],
            (string) $slotData['startTime'],
        );
    }

    /** @return array<string, mixed> */
    private function deduplicateNoneSlots(mixed $rawSlots): array
    {
        if (!\is_array($rawSlots)) {
            return [];
        }

        $slots = [];
        foreach ($rawSlots as $slot) {
            if (!\is_array($slot)) {
                continue;
            }

            $lockLevel = ($slot['lockLevel'] ?? 'NONE');
            if ('NONE' !== $lockLevel && 'HARD' !== $lockLevel) {
                continue;
            }

            $slotId = (string) ($slot['id'] ?? '');
            if ('' === $slotId || isset($slots[$slotId])) {
                continue;
            }

            $slots[$slotId] = $slot;
        }

        return $slots;
    }

    /**
     * @param array<string, mixed> $slotData
     * @param array<string, true>  $reservationPlacements placement keys of this plan's durable pins
     * @param array<string, true>  $manualPlacements      placement keys of the source version's MANUAL locks
     */
    private function hydrateSlot(ScheduleSlotTemplate $slot, Schedule $schedule, array $slotData, array $reservationPlacements, array $manualPlacements): void
    {
        $lockLevel = LockLevel::tryFrom(strtoupper((string) ($slotData['lockLevel'] ?? 'NONE'))) ?? LockLevel::NONE;
        $slot
            ->setClubId($schedule->getClubId())
            ->setSeasonId($schedule->getSeasonId())
            ->setScheduleId($schedule->getId())
            ->setTeamId((string) $slotData['teamId'])
            ->setVenueId((string) $slotData['venueId'])
            ->setCoachId(isset($slotData['coachId']) ? (string) $slotData['coachId'] : null)
            ->setDayOfWeek((int) $slotData['dayOfWeek'])
            ->setStartTime($this->parseStartTime((string) $slotData['startTime']))
            ->setDurationMinutes((int) $slotData['durationMinutes'])
            ->setLockLevel($lockLevel)
            ->setLockOrigin($this->originForImportedLock($lockLevel, $slot, $reservationPlacements, $manualPlacements));
    }

    /**
     * L'origine d'un verrou fraîchement importé du solveur. Un verrou HARD réhydraté ici est forcément
     * une épingle réalimentée au moteur : les verrous HARD de CETTE version sont préservés INTACTS par
     * `import()` (leur ligne est sautée, jamais réhydratée), donc un HARD qui ressort du solveur vient
     * d'un pin durable du PLAN. Deux provenances VRAIES, jamais devinées (cf. `LockOriginProvenanceTest`) :
     *  - il coïncide avec une `Reservation` du plan → RESERVATION ;
     *  - sinon il coïncide avec un verrou MANUAL de la version SOURCE (verrou de work-loop hérité, V1
     *    « les verrous survivent à Régénérer ») → MANUAL. Ça RÉPARE AUSSI le compteur de la saison :
     *    un MANUAL hérité ressortait en UNKNOWN jusqu'ici, faussant le décompte « verrous posés ».
     *  - ni l'un ni l'autre → UNKNOWN (indécidable).
     *
     * @param array<string, true> $reservationPlacements
     * @param array<string, true> $manualPlacements
     */
    private function originForImportedLock(LockLevel $lockLevel, ScheduleSlotTemplate $slot, array $reservationPlacements, array $manualPlacements): ?LockOrigin
    {
        if (LockLevel::NONE === $lockLevel) {
            return null;
        }

        $placement = $this->placementKey($slot->getTeamId(), $slot->getVenueId(), $slot->getDayOfWeek(), $slot->getStartTime()->format('H:i'));
        if (isset($reservationPlacements[$placement])) {
            return LockOrigin::RESERVATION;
        }

        return isset($manualPlacements[$placement]) ? LockOrigin::MANUAL : LockOrigin::UNKNOWN;
    }

    /**
     * Placement keys of the MANUAL locks carried by the PLAN's lock-source version ({@see
     * SchedulePlanProvisioner::lockSourceScheduleIdOfPlan} — the last COMPLETED version, EXCLUDING the
     * one being imported, which is still GENERATING at import time). These are the work-loop locks the
     * builder re-pinned HARD into the solve input; the engine re-emits them, and matching their
     * placement restores their MANUAL origin on the new version instead of losing it to UNKNOWN.
     *
     * @return array<string, true>
     */
    private function sourceManualPlacements(Schedule $schedule): array
    {
        $planId = $schedule->getSchedulePlanId();
        $sourceId = $this->schedulePlanProvisioner->lockSourceScheduleIdOfPlan($planId);
        if (null === $sourceId || $sourceId === $schedule->getId()) {
            return [];
        }

        $placements = [];
        foreach ($this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $sourceId]) as $slot) {
            if (LockLevel::HARD !== $slot->getLockLevel() || LockOrigin::MANUAL !== $slot->getLockOrigin()) {
                continue;
            }
            $placements[$this->placementKey($slot->getTeamId(), $slot->getVenueId(), $slot->getDayOfWeek(), $slot->getStartTime()->format('H:i'))] = true;
        }

        return $placements;
    }

    /**
     * Placement keys of the durable Reservation pins that feed THIS schedule's plan — the
     * only source of a HARD lock in a solver result. Loaded once per import.
     *
     * @return array<string, true>
     */
    private function reservationPlacements(Schedule $schedule): array
    {
        $reservations = $this->entityManager->getRepository(Reservation::class)->findBy([
            'clubId' => $schedule->getClubId(),
            'seasonId' => $schedule->getSeasonId(),
        ]);

        $placements = [];
        foreach ($reservations as $reservation) {
            // Lot 4bis — un créneau LIBRE (teamId null) ne matérialise aucun verrou HARD d'équipe :
            // il n'entre pas dans les clés de placement.
            if (null === $reservation->getTeamId()) {
                continue;
            }
            $placements[$this->placementKey(
                $reservation->getTeamId(),
                $reservation->getVenueId(),
                $reservation->getDayOfWeek(),
                $reservation->getStartTime()->format('H:i'),
            )] = true;
        }

        return $placements;
    }

    private function parseStartTime(string $startTime): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!H:i', $startTime)
            ?: DateTimeImmutable::createFromFormat('!H:i:s', $startTime);

        if (!$time instanceof DateTimeImmutable) {
            throw new InvalidArgumentException(\sprintf('Invalid solver slot startTime "%s".', $startTime));
        }

        return $time;
    }
}
