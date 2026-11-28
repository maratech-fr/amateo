<?php

declare(strict_types=1);

namespace App\Plans;

use App\Entity\CalendarEntry;
use App\Entity\Coach;
use App\Entity\CoachWish;
use App\Entity\Schedule;
use App\Enum\SchedulePlanType;
use App\Enum\TeamCoachRole;
use App\Repository\CalendarEntryRepository;
use App\Service\OrphanedFixtureFinder;
use App\Service\OverlayManager;
use App\Service\SchedulePlanProvisioner;
use App\Structure\PersonDirectory;
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
        private PersonDirectory $personDirectory,
    ) {}

    /**
     * La forme servie par l'annonce (et par le refus `changes_to_confirm`).
     *
     * @param array{changes: PlanChanges, destroyed: list<CalendarEntry>, keptPeriodPlans: int, keptWishes: int, token: string, coachChanges: list<array{teamId: string, teamName: string, coachId: ?string, coachName: ?string}>} $impact
     *
     * @return array{changes: list<array{family: string, count: int, destroys: bool}>, changesToken: string, destroyedPeriodPlans: list<array{entryId: string, title: string}>, keptPeriodPlans: int, keptWishes: int, coachChanges: list<array{teamId: string, teamName: string, coachId: ?string, coachName: ?string}>}
     */
    public static function served(array $impact): array
    {
        return [
            'changes' => $impact['changes']->lines(),
            'changesToken' => $impact['token'],
            'destroyedPeriodPlans' => array_map(static fn (CalendarEntry $entry): array => ['entryId' => $entry->getId(), 'title' => $entry->getTitle()], $impact['destroyed']),
            'keptPeriodPlans' => $impact['keptPeriodPlans'],
            'keptWishes' => $impact['keptWishes'],
            'coachChanges' => $impact['coachChanges'],
        ];
    }

    /**
     * Valider cette version.
     *
     * @return array{changes: PlanChanges, destroyed: list<CalendarEntry>, keptPeriodPlans: int, keptWishes: int, token: string, coachChanges: list<array{teamId: string, teamName: string, coachId: ?string, coachName: ?string}>}
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
        $after = $this->planStates->withoutDeadReferences($planId, $this->planStates->of($version->getId()));
        $changes = $this->planStates->withoutDeadReferences($planId, $this->planStates->of($inForceId))
            ->changesTo($after)
            ->withMovedSessions($this->movedSessions($inForceId, $version->getId()));

        return $this->impact($version->getClubId(), $version->getSeasonId(), $changes, $changes->destroys(), $after);
    }

    /**
     * Valider les modifications en attente du plan de saison, sans régénérer (rien sans version en vigueur).
     *
     * @return array{changes: PlanChanges, destroyed: list<CalendarEntry>, keptPeriodPlans: int, keptWishes: int, token: string, coachChanges: list<array{teamId: string, teamName: string, coachId: ?string, coachName: ?string}>}
     */
    public function ofPendingChanges(string $planId, string $clubId, string $seasonId): array
    {
        $inForceId = $this->schedulePlanProvisioner->chosenOfSeasonPlan($seasonId);
        $changes = null === $inForceId ? new PlanChanges([]) : $this->pendingChanges->changesOf($planId, $inForceId);

        return $this->impact($clubId, $seasonId, $changes, $changes->destroys(), null === $inForceId ? null : $this->planStates->capture($planId));
    }

    /**
     * L'annonce de « Valider ces modifications » (et le corps de son refus `changes_to_confirm`) : la forme de
     * `validate-impact`, plus le contrôle d'incohérences avec le planning en vigueur ({@see InForceCoherence}).
     *
     * `$withCheck` : le contrôle appelle le moteur (jusqu'à six demandes) ; il n'est fait que pour un gestionnaire, seul
     * à pouvoir valider. Sans lui, `checkPerformed` est faux et les listes du contrôle sont vides.
     *
     * @return array<string, mixed>
     */
    public function pendingBody(string $planId, string $clubId, string $seasonId, bool $withCheck = true): array
    {
        // Seul le plan de saison a des modifications qui attendent (la porte ne couvre que le socle).
        $isSeason = SchedulePlanType::SEASON->value === $this->entityManager->getConnection()->fetchOne('SELECT type FROM schedule_plan WHERE id = ?', [$planId]);
        $performed = $isSeason && $withCheck;
        $check = $performed ? $this->inForceCoherence->check($planId) : ['incoherences' => [], 'missingSessions' => [], 'inconclusive' => false];

        return [
            ...$this->orphanedFixtureFinder->impact($clubId, $seasonId),
            ...self::served($isSeason ? $this->ofPendingChanges($planId, $clubId, $seasonId) : $this->impact($clubId, $seasonId, new PlanChanges([]), false)),
            'incoherences' => $check['incoherences'],
            'missingSessions' => $check['missingSessions'],
            'checkInconclusive' => $check['inconclusive'],
            'checkPerformed' => $performed,
        ];
    }

    /**
     * `$after` : l'état que la validation fait entrer en vigueur (la copie de la version, ou l'état de travail), d'où se
     * lit le nouveau coach principal des équipes dont il change.
     *
     * @return array{changes: PlanChanges, destroyed: list<CalendarEntry>, keptPeriodPlans: int, keptWishes: int, token: string, coachChanges: list<array{teamId: string, teamName: string, coachId: ?string, coachName: ?string}>}
     */
    private function impact(string $clubId, string $seasonId, PlanChanges $changes, bool $destroys, ?PlanState $after = null): array
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
            'coachChanges' => $after instanceof PlanState ? $this->coachChanges($clubId, $seasonId, $changes, $after) : [],
        ];
    }

    /**
     * Pour chaque équipe dont le coach principal change, l'équipe et le coach qui tiendra ses séances : le premier coach
     * principal de l'état adopté, dans l'ordre des liens (le premier lien tient la séance) ; null si l'équipe n'a plus
     * de coach principal. Triées par nom d'équipe.
     *
     * @return list<array{teamId: string, teamName: string, coachId: ?string, coachName: ?string}>
     */
    private function coachChanges(string $clubId, string $seasonId, PlanChanges $changes, PlanState $after): array
    {
        $teamIds = [];
        foreach ($changes->rows()['TeamCoach'] ?? [] as [$old, $new]) {
            foreach ([$old, $new] as $row) {
                if (\is_array($row) && \is_string($row['teamId'] ?? null)) {
                    $teamIds[$row['teamId']] = true;
                }
            }
        }
        if ([] === $teamIds) {
            return [];
        }
        $connection = $this->entityManager->getConnection();
        $linkIds = [];
        foreach ($after->toArray()['TeamCoach'] ?? [] as $linkId => $row) {
            if (TeamCoachRole::MAIN->value === ($row['role'] ?? null) && isset($teamIds[(string) ($row['teamId'] ?? '')])) {
                $linkIds[(string) $linkId] = $row;
            }
        }
        // L'ordre des liens vivants (création, puis id) ; un lien de la copie absent de la base vient après, par id.
        $ordered = [] === $linkIds ? [] : array_map(strval(...), $connection->fetchFirstColumn(
            'SELECT id FROM team_coach WHERE club_id = ? AND season_id = ? AND id IN (' . implode(', ', array_fill(0, \count($linkIds), '?')) . ') ORDER BY created_at, id',
            [$clubId, $seasonId, ...array_keys($linkIds)],
        ));
        $rest = array_values(array_diff(array_map(strval(...), array_keys($linkIds)), $ordered));
        sort($rest, \SORT_STRING);
        $holderOf = [];
        foreach ([...$ordered, ...$rest] as $linkId) {
            $holderOf[(string) $linkIds[$linkId]['teamId']] ??= (string) $linkIds[$linkId]['coachId'];
        }

        $teamNames = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT id, name FROM team WHERE club_id = ? AND season_id = ? AND id IN (' . implode(', ', array_fill(0, \count($teamIds), '?')) . ')',
            [$clubId, $seasonId, ...array_keys($teamIds)],
        ) as $team) {
            $teamNames[(string) $team['id']] = (string) $team['name'];
        }
        $coaches = [] === $holderOf ? [] : $this->entityManager->getRepository(Coach::class)->findBy(['id' => array_values(array_unique($holderOf))]);
        $names = [];
        foreach ($this->personDirectory->forCoaches($coaches) as $coachId => $identity) {
            $names[(string) $coachId] = $identity->fullName();
        }

        $coachChanges = [];
        foreach (array_keys($teamIds) as $teamId) {
            $teamId = (string) $teamId;
            if (!isset($teamNames[$teamId])) {
                continue; // une équipe supprimée depuis ne s'annonce pas
            }
            $coachId = $holderOf[$teamId] ?? null;
            $coachChanges[] = ['teamId' => $teamId, 'teamName' => $teamNames[$teamId], 'coachId' => $coachId, 'coachName' => null === $coachId ? null : ($names[$coachId] ?? null)];
        }
        usort($coachChanges, static fn (array $a, array $b): int => strcmp($a['teamName'], $b['teamName']) ?: strcmp($a['teamId'], $b['teamId']));

        return $coachChanges;
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
