<?php

declare(strict_types=1);

namespace App\Plans;

use App\Entity\Constraint;
use App\Entity\Fixture;
use App\Entity\Schedule;
use App\Enum\SchedulePlanType;
use App\Enum\TeamCoachRole;
use App\Service\CoachDoubleBookingDetector;
use App\Service\FixtureVenueLossMarker;
use App\Service\OrphanedFixtureFinder;
use App\Service\OverlayManager;
use App\Service\ScheduleConstraintBuilder;
use App\Support\WeeklySlot;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Ce qui attend la validation du planning de saison (lot 5) : l'état de travail du plan comparé à la copie de sa
 * version en vigueur, sur les seuls champs froids ({@see PlanState::changesTo}), et la présence d'une version de
 * travail chargée. Rien n'attend sans version en vigueur, ni pour un plan de période (la porte ne couvre que le
 * socle).
 *
 * « Valider ces modifications » ({@see self::apply}) fait valoir ce qui attend sur la version en vigueur, sans
 * régénérer : c'est le SEUL écrivain de la version en vigueur (exception à « seule la version chargée se modifie »),
 * et il n'en écrit que trois choses — sa copie du froid, le coach de ses séances, son entrée figée et son empreinte.
 */
final readonly class PendingChanges
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlanStates $planStates,
        private PlanComposition $planComposition,
        private OverlayManager $overlayManager,
        private ScheduleConstraintBuilder $constraintBuilder,
        private OrphanedFixtureFinder $orphanedFixtureFinder,
        private FixtureVenueLossMarker $fixtureVenueLossMarker,
        private ClockInterface $clock,
        #[Autowire(service: 'cache.schedule')]
        private TagAwareCacheInterface $scheduleCache,
    ) {}

    /**
     * @return array{pending: bool, hasWorkVersion: bool, changes: list<array{family: string, count: int, destroys: bool}>}
     */
    public function of(string $planId): array
    {
        $plan = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT type, chosen_schedule_id, lock_source_schedule_id FROM schedule_plan WHERE id = ?',
            [$planId],
        );
        $inForce = false === $plan ? null : $plan['chosen_schedule_id'];
        if (false === $plan || SchedulePlanType::SEASON->value !== $plan['type'] || !\is_string($inForce)) {
            return ['pending' => false, 'hasWorkVersion' => false, 'changes' => []];
        }

        $changes = $this->changesOf($planId, $inForce)->lines();
        $loaded = $plan['lock_source_schedule_id'];
        $hasWorkVersion = \is_string($loaded) && $loaded !== $inForce;

        return ['pending' => [] !== $changes || $hasWorkVersion, 'hasWorkVersion' => $hasWorkVersion, 'changes' => $changes];
    }

    /**
     * Ce qui diffère entre la copie de la version en vigueur et l'état de travail du plan. Des deux côtés, une ligne
     * dont l'équipe, le gymnase ou le coach n'existe plus ne compte pas (une ligne orpheline de l'état de travail n'est
     * pas une modification).
     */
    public function changesOf(string $planId, string $inForceId): PlanChanges
    {
        return $this->planStates->withoutDeadReferences($planId, $this->planStates->of($inForceId))
            ->changesTo($this->planStates->withoutDeadReferences($planId, $this->planStates->capture($planId)));
    }

    /**
     * « Valider ces modifications » : la version en vigueur du plan de saison adopte l'état de travail, sans régénérer.
     * Elle écrit, et rien d'autre de la version : sa copie du froid (l'état de travail du jour) ; le coach des séances
     * des équipes dont le coach principal a changé (une séance qui nommait l'ancien nomme le nouveau, par la maison de
     * la tenue) ; son entrée figée et son empreinte, recalculées sur l'état adopté, sans greffe. Aucune séance ne change
     * de place, de jour, d'heure, de gymnase ni de verrou ; aucune version ne naît.
     *
     * Hors de la version, comme toute validation du froid : les lignes de règle des périodes suivent, les plannings de
     * période pas commencés sont supprimés et la date du changement de froid est posée (quand du froid change), les
     * matchs d'un gymnase disparu repassent « à placer ». Les gardes (plan de saison, version en vigueur, jeton,
     * génération en cours, verrou de la saison) sont celles de l'appelant.
     */
    public function apply(string $planId): void
    {
        $plan = $this->entityManager->getConnection()->fetchAssociative('SELECT club_id, season_id, chosen_schedule_id FROM schedule_plan WHERE id = ?', [$planId]);
        $inForce = false === $plan || !\is_string($plan['chosen_schedule_id']) ? null : $this->entityManager->find(Schedule::class, $plan['chosen_schedule_id']);
        if (!$inForce instanceof Schedule) {
            throw new LogicException(\sprintf('Le plan %s n\'a pas de version en vigueur : rien ne se valide sans régénérer.', $planId));
        }
        $clubId = $inForce->getClubId();
        $seasonId = $inForce->getSeasonId();

        $this->entityManager->wrapInTransaction(function () use ($planId, $inForce, $clubId, $seasonId): void {
            $replaced = $this->planStates->of($inForce->getId());
            $changes = $this->changesOf($planId, $inForce->getId());
            $adopted = $this->planStates->capture($planId);

            $this->planStates->store($inForce, $adopted);
            $this->rewriteSessionCoaches($inForce, $replaced, $adopted);

            // L'entrée figée et l'empreinte, sur l'état adopté (patron de la transcription) : le cache du payload ne
            // voit pas les écritures SQL ci-dessus.
            $this->scheduleCache->invalidateTags([ScheduleConstraintBuilder::cacheTag($clubId)]);
            $payload = $this->constraintBuilder->buildForPlan($planId, versionId: $inForce->getId());
            $inForce->setSnapshotData($payload)
                ->setSnapshotHash(ScheduleConstraintBuilder::fingerprint($payload, $this->planStates->travelRuleIn($adopted)))
                ->setPayloadGraft(null);
            $this->entityManager->flush();

            $this->planComposition->propagateSocleChanges($seasonId, $replaced, $adopted);
            if ($changes->destroys()) {
                foreach ($this->overlayManager->periodPlansInvalidatedBySeasonChange($clubId, $seasonId) as $entry) {
                    $this->overlayManager->deletePeriodPlanForEntry($entry, force: true);
                }
                $this->entityManager->getConnection()->executeStatement(
                    'UPDATE schedule_plan SET cold_changed_at = ? WHERE id = ?',
                    [$this->clock->now()->format(\DATE_ATOM), $planId],
                );
            }
            $this->fixtureVenueLossMarker->mark(array_map(
                static fn (Fixture $fixture): string => $fixture->getId(),
                $this->orphanedFixtureFinder->orphanedFixtures($clubId, $seasonId),
            ));
            $this->scheduleCache->invalidateTags([ScheduleConstraintBuilder::cacheTag($clubId)]);
        });
    }

    /**
     * Les séances de la version que tenait un ancien coach principal de leur équipe passent au nouveau, choisi par la
     * maison de la tenue ({@see CoachDoubleBookingDetector::storedCoachFor}) : le premier coach principal libre de
     * l'équipe, à défaut le premier. Une équipe qui n'a plus de coach principal rend la séance à personne. Les autres
     * séances gardent leur coach.
     */
    private function rewriteSessionCoaches(Schedule $inForce, PlanState $replaced, PlanState $adopted): void
    {
        $mainOf = static function (PlanState $state): array {
            $byTeam = [];
            foreach ($state->toArray()['TeamCoach'] ?? [] as $row) {
                if (TeamCoachRole::MAIN->value === ($row['role'] ?? null)) {
                    $byTeam[(string) $row['teamId']][] = (string) $row['coachId'];
                }
            }

            return $byTeam;
        };
        $before = $mainOf($replaced);
        $after = $this->mainCoachesInOrder($inForce);
        $changedTeams = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $teamId) {
            $old = $before[$teamId] ?? [];
            $new = $after[$teamId] ?? [];
            sort($old);
            $sortedNew = $new;
            sort($sortedNew);
            if ($old !== $sortedNew) {
                $changedTeams[(string) $teamId] = array_values(array_diff($old, $new));
            }
        }
        if ([] === $changedTeams) {
            return;
        }

        $connection = $this->entityManager->getConnection();
        $sessions = $connection->fetchAllAssociative(
            'SELECT id, team_id, venue_id, coach_id, day_of_week, start_time, duration_minutes FROM schedule_slot_template WHERE schedule_id = ? ORDER BY id',
            [$inForce->getId()],
        );
        $bookingOf = static fn (array $row): array => [
            'teamId' => (string) $row['team_id'],
            'venueId' => (string) $row['venue_id'],
            'dayOfWeek' => (int) $row['day_of_week'],
            'startMinutes' => WeeklySlot::minutesOf((string) $row['start_time']),
            'durationMinutes' => (int) $row['duration_minutes'],
        ];
        $playing = [];
        foreach ($adopted->toArray()['CoachPlayerMembership'] ?? [] as $membership) {
            if (true === ($membership['isActive'] ?? false)) {
                foreach ($sessions as $row) {
                    if ((string) $row['team_id'] === (string) $membership['teamId']) {
                        $playing[(string) $membership['coachId']][] = $bookingOf($row);
                    }
                }
            }
        }
        $blocked = CoachDoubleBookingDetector::blockedIntervalsByCoach(
            $this->entityManager->getRepository(Constraint::class)->findBy(['clubId' => $inForce->getClubId(), 'seasonId' => $inForce->getSeasonId()]),
        );
        $holders = [];
        foreach ($sessions as $row) {
            $holders[(string) $row['id']] = null === $row['coach_id'] ? null : (string) $row['coach_id'];
        }

        foreach ($sessions as $row) {
            $teamId = (string) $row['team_id'];
            $id = (string) $row['id'];
            if (!isset($changedTeams[$teamId]) || null === $holders[$id] || !\in_array($holders[$id], $changedTeams[$teamId], true)) {
                continue;
            }
            $others = [];
            foreach ($sessions as $other) {
                if ((string) $other['id'] !== $id) {
                    $others[] = ['coachId' => $holders[(string) $other['id']] ?? ($after[(string) $other['team_id']][0] ?? null)] + $bookingOf($other);
                }
            }
            $holders[$id] = CoachDoubleBookingDetector::storedCoachFor($bookingOf($row), $after[$teamId] ?? [], $others, $blocked, $playing);
            $connection->executeStatement(
                'UPDATE schedule_slot_template SET coach_id = ?, updated_at = now() WHERE id = ?',
                [$holders[$id], $id],
            );
        }
    }

    /**
     * Les coachs principaux de chaque équipe de la saison, dans l'état de travail, dans l'ordre de leur lien (le premier
     * lien tient la séance).
     *
     * @return array<string, list<string>>
     */
    private function mainCoachesInOrder(Schedule $inForce): array
    {
        $byTeam = [];
        foreach ($this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT team_id, coach_id FROM team_coach WHERE club_id = ? AND season_id = ? AND role = ? ORDER BY created_at, id',
            [$inForce->getClubId(), $inForce->getSeasonId(), TeamCoachRole::MAIN->value],
        ) as $row) {
            $byTeam[(string) $row['team_id']][] = (string) $row['coach_id'];
        }

        return $byTeam;
    }
}
