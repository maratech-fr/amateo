<?php

declare(strict_types=1);

namespace App\Plans;

use App\Entity\CalendarEntry;
use App\Entity\CoachWish;
use App\Entity\Schedule;
use App\Enum\SchedulePlanType;
use App\Repository\CalendarEntryRepository;
use App\Service\OrphanedFixtureFinder;
use App\Service\OverlayManager;
use App\Service\SchedulePlanProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Ce que change une validation du planning de saison (lot 5), maison unique de l'annonce et de la gâchette : la liste
 * famille par famille ({@see PlanChanges}), les plannings de période pas commencés qu'elle supprime, ceux qu'elle
 * garde, les doléances des vacances à venir (toujours conservées) et le jeton de la liste vue.
 *
 * Seul le froid supprime : une version dont la copie diffère de celle de la version en vigueur sur un champ froid
 * supprime les plannings de période pas commencés ; une séance déplacée s'annonce pour information, un verrou ne
 * s'annonce pas. Sans version en vigueur, des plannings de période (donnée migrée) sont supprimés comme avant.
 * Valider la version déjà en vigueur, ou une version de période, ne change rien.
 */
final readonly class ValidationImpact
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SchedulePlanProvisioner $schedulePlanProvisioner,
        private PlanStates $planStates,
        private PendingChanges $pendingChanges,
        private OverlayManager $overlayManager,
        private CalendarEntryRepository $calendarEntryRepository,
        private ClockInterface $clock,
        private InForceCoherence $inForceCoherence,
        private OrphanedFixtureFinder $orphanedFixtureFinder,
    ) {}

    /**
     * La forme servie par l'annonce (et par le refus `changes_to_confirm`).
     *
     * @param array{changes: PlanChanges, destroyed: list<CalendarEntry>, keptPeriodPlans: int, keptWishes: int, token: string} $impact
     *
     * @return array{changes: list<array{family: string, count: int, destroys: bool}>, changesToken: string, destroyedPeriodPlans: list<array{entryId: string, title: string}>, keptPeriodPlans: int, keptWishes: int}
     */
    public static function served(array $impact): array
    {
        return [
            'changes' => $impact['changes']->lines(),
            'changesToken' => $impact['token'],
            'destroyedPeriodPlans' => array_map(static fn (CalendarEntry $entry): array => ['entryId' => $entry->getId(), 'title' => $entry->getTitle()], $impact['destroyed']),
            'keptPeriodPlans' => $impact['keptPeriodPlans'],
            'keptWishes' => $impact['keptWishes'],
        ];
    }

    /**
     * Valider cette version.
     *
     * @return array{changes: PlanChanges, destroyed: list<CalendarEntry>, keptPeriodPlans: int, keptWishes: int, token: string}
     */
    public function ofVersion(Schedule $version): array
    {
        if (null !== $this->schedulePlanProvisioner->periodEntryIdOf($version)) {
            return $this->impact($version->getClubId(), $version->getSeasonId(), new PlanChanges([]), false);
        }
        $inForceId = $this->schedulePlanProvisioner->chosenOfSeasonPlan($version->getSeasonId());
        if ($inForceId === $version->getId()) {
            return $this->impact($version->getClubId(), $version->getSeasonId(), new PlanChanges([]), false);
        }
        if (null === $inForceId || !$this->planStates->has($inForceId) || !$this->planStates->has($version->getId())) {
            // Aucune copie à comparer : le calendrier de base bouge, comme avant le lot.
            return $this->impact($version->getClubId(), $version->getSeasonId(), new PlanChanges([]), true);
        }

        $planId = $version->getSchedulePlanId();
        $changes = $this->planStates->withoutDeadReferences($planId, $this->planStates->of($inForceId))
            ->changesTo($this->planStates->withoutDeadReferences($planId, $this->planStates->of($version->getId())))
            ->withMovedSessions($this->movedSessions($inForceId, $version->getId()));

        return $this->impact($version->getClubId(), $version->getSeasonId(), $changes, $changes->destroys());
    }

    /**
     * Valider les modifications en attente du plan de saison, sans régénérer (rien sans version en vigueur).
     *
     * @return array{changes: PlanChanges, destroyed: list<CalendarEntry>, keptPeriodPlans: int, keptWishes: int, token: string}
     */
    public function ofPendingChanges(string $planId, string $clubId, string $seasonId): array
    {
        $inForceId = $this->schedulePlanProvisioner->chosenOfSeasonPlan($seasonId);
        $changes = null === $inForceId ? new PlanChanges([]) : $this->pendingChanges->changesOf($planId, $inForceId);

        return $this->impact($clubId, $seasonId, $changes, $changes->destroys());
    }

    /**
     * L'annonce de « Valider ces modifications » (et le corps de son refus `changes_to_confirm`) : la forme de
     * `validate-impact`, plus le contrôle d'incohérences avec le planning en vigueur ({@see InForceCoherence}).
     *
     * @return array<string, mixed>
     */
    public function pendingBody(string $planId, string $clubId, string $seasonId): array
    {
        // Seul le plan de saison a des modifications qui attendent (la porte ne couvre que le socle).
        $isSeason = SchedulePlanType::SEASON->value === $this->entityManager->getConnection()->fetchOne('SELECT type FROM schedule_plan WHERE id = ?', [$planId]);
        $check = $isSeason ? $this->inForceCoherence->check($planId) : ['incoherences' => [], 'missingSessions' => [], 'inconclusive' => false];

        return [
            ...$this->orphanedFixtureFinder->impact($clubId, $seasonId),
            ...self::served($isSeason ? $this->ofPendingChanges($planId, $clubId, $seasonId) : $this->impact($clubId, $seasonId, new PlanChanges([]), false)),
            'incoherences' => $check['incoherences'],
            'missingSessions' => $check['missingSessions'],
            'checkInconclusive' => $check['inconclusive'],
        ];
    }

    /**
     * @return array{changes: PlanChanges, destroyed: list<CalendarEntry>, keptPeriodPlans: int, keptWishes: int, token: string}
     */
    private function impact(string $clubId, string $seasonId, PlanChanges $changes, bool $destroys): array
    {
        $destroyed = $destroys ? $this->overlayManager->periodPlansInvalidatedBySeasonChange($clubId, $seasonId) : [];
        $periodPlans = (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM schedule_plan WHERE club_id = ? AND season_id = ? AND calendar_entry_id IS NOT NULL',
            [$clubId, $seasonId],
        );
        $futureHolidays = $this->calendarEntryRepository->findFutureHolidayMotherIds($clubId, $seasonId, $this->clock->now());
        $keptWishes = [] === $futureHolidays ? 0 : (int) $this->entityManager->createQuery(
            'SELECT COUNT(w.id) FROM ' . CoachWish::class . ' w WHERE w.calendarEntryId IN (:ids)',
        )->setParameter('ids', $futureHolidays)->getSingleScalarResult();
        $destroyedIds = array_map(static fn (CalendarEntry $entry): string => $entry->getId(), $destroyed);

        return [
            'changes' => $changes,
            'destroyed' => $destroyed,
            'keptPeriodPlans' => max(0, $periodPlans - \count($destroyed)),
            'keptWishes' => $keptWishes,
            // Le jeton de la liste vue : ce qui change ET ce qui serait supprimé.
            'token' => hash('sha256', $changes->token() . '|' . implode(',', $destroyedIds)),
        ];
    }

    /**
     * Les séances déplacées d'une version à l'autre : une séance (équipe, gymnase, jour, heure, durée) que l'autre version
     * ne place pas, comptée du côté qui en a le plus. Un verrou n'est pas une place : il ne compte pas.
     */
    private function movedSessions(string $inForceId, string $versionId): int
    {
        $placements = function (string $scheduleId): array {
            $counts = [];
            foreach ($this->entityManager->getConnection()->fetchFirstColumn(
                'SELECT team_id || \'|\' || venue_id || \'|\' || day_of_week || \'|\' || to_char(start_time, \'HH24:MI\') || \'|\' || duration_minutes FROM schedule_slot_template WHERE schedule_id = ?',
                [$scheduleId],
            ) as $placement) {
                $counts[(string) $placement] = ($counts[(string) $placement] ?? 0) + 1;
            }

            return $counts;
        };
        $before = $placements($inForceId);
        $after = $placements($versionId);
        $gone = 0;
        $arrived = 0;
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $placement) {
            $difference = ($after[$placement] ?? 0) - ($before[$placement] ?? 0);
            $arrived += max(0, $difference);
            $gone += max(0, -$difference);
        }

        return max($gone, $arrived);
    }
}
