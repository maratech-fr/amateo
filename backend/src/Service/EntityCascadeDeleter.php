<?php

declare(strict_types=1);

namespace App\Service;

use App\Deletion\CascadePlan;
use App\Deletion\CascadeStep;
use App\Deletion\DeletionTarget;
use App\Deletion\SlotDeletionTarget;
use App\Entity\Coach;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\LockLevel;
use App\Enum\SchedulePlanType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cascade the logical children of a single deleted entity (team / venue / coach
 * / availability slot). Unlike SeasonDataPurger (whole-season wipe), this runs
 * on a per-entity Delete: the entities carry no ORM associations or DB foreign
 * keys — every link is a plain guid column — so nothing cascades on its own and
 * a bare remove() would orphan reservations, coach links, constraints and the
 * materialised HARD slot templates the solver reads back.
 *
 * Runs in the request's tenant context (RLS GUC already set to the club by
 * TenantFilterListener). The bulk DQL DELETE/UPDATE are scoped by the entity's
 * clubId + seasonId AND guarded by RLS at the DB — a cascade can never cross a
 * club. The tenant/season Doctrine filters are disabled around the statements:
 * like SeasonDataPurger, they alias the table name (invalid SQL for the
 * reserved-word `constraint` table); the explicit scope + RLS replace them.
 *
 * The caller (the state processor) removes the entity itself afterwards.
 */
final class EntityCascadeDeleter
{
    use DisablesTenantFilters;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function purgeChildrenOfTeam(Team $team): void
    {
        $this->run(CascadePlan::forTeam(), new DeletionTarget($team->getId(), $team->getClubId(), $team->getSeasonId()));
    }

    public function purgeChildrenOfVenue(Venue $venue): void
    {
        $this->run(CascadePlan::forVenue(), new DeletionTarget($venue->getId(), $venue->getClubId(), $venue->getSeasonId()));
    }

    public function purgeChildrenOfCoach(Coach $coach): void
    {
        $this->run(CascadePlan::forCoach(), new DeletionTarget($coach->getId(), $coach->getClubId(), $coach->getSeasonId()));
    }

    /**
     * A slot is a leaf, but a Reservation pins to it LOGICALLY by (venueId,
     * dayOfWeek, startTime) — deleting the slot must clear those reservations
     * and their materialised HARD templates (same idiom as
     * ReservationStateProcessor), else they re-inject a pin on a slot that no
     * longer exists (the orphan-reservation bug this whole change fixes).
     */
    public function purgeChildrenOfSlot(VenueTrainingSlot $slot): void
    {
        $this->run(CascadePlan::forSlot(), SlotDeletionTarget::of($slot));
    }

    /**
     * v2 cohérence canSplit — un gymnase repasse « indivisible » alors que des créneaux y
     * accueillent 2 équipes ou plus (état incohérent, cf. VenueStateProcessor). Sur CONFIRMATION
     * du gestionnaire, chaque créneau visé :
     *   - retombe à capacité 1 et perd son libellé de groupe (un libellé suppose ≥ 2 équipes,
     *     cf. VenueTrainingSlotStateProcessor::validateGroupLabel) ;
     *   - voit ses épinglages vidés — réservations ET les seuls verrous HARD qu'elles ont
     *     matérialisés, exactement les DEUX familles de {@see purgeChildrenOfSlot} (une réservation
     *     et son verrou sont les deux faces d'un même épinglage : en supprimer une sans l'autre est
     *     précisément le bug d'orphelin que ces méthodes existent pour fermer).
     *
     * Bornage par COUCHE assuré par `deleteBySlotKey` : chaque créneau porte son `schedulePlanId`,
     * donc un créneau de saison ne vide que les épinglages de base, un créneau de période que les
     * siens — les deux couches sont couvertes parce que l'appelant passe TOUS les créneaux ≥ 2 du
     * gymnase. Écrit en DQL comme le reste du service (hors UnitOfWork). Le planning se dira à
     * régénérer de lui-même : le write du gymnase déplace l'empreinte de structure servie par plan.
     *
     * @param list<VenueTrainingSlot> $slots les créneaux du gymnase à capacité ≥ 2
     */
    public function clampSplitSlotsAndClearPins(array $slots): void
    {
        $this->withoutTenantFilters(function () use ($slots): void {
            foreach ($slots as $slot) {
                $target = SlotDeletionTarget::of($slot);
                foreach (CascadePlan::forSlot() as $step) {
                    $step->execute($this->entityManager, $target);
                }

                $this->entityManager->createQueryBuilder()
                    ->update(VenueTrainingSlot::class, 's')
                    ->set('s.capacity', ':one')
                    ->set('s.groupLabel', 'NULL')
                    ->where('s.id = :id')
                    ->setParameter('one', 1)
                    ->setParameter('id', $slot->getId())
                    ->getQuery()
                    ->execute();
            }
        });
    }

    /**
     * P4-44 — DÉPLACER un créneau déplace ses réservations, il ne les orpheline pas.
     *
     * Une `Reservation` désigne son créneau par le TRIPLET (gymnase, jour, heure),
     * jamais par son id : un `PUT` qui corrige l'horaire laissait donc la réservation
     * sur l'ancien triplet — un horaire qui n'existe plus. Le moteur ne s'en plaint
     * PAS sur le socle : il place la séance à l'horaire mort et rend `completed`
     * (mesuré). Le gestionnaire distribue un planning qui envoie ses équipes devant
     * une porte fermée.
     *
     * Le geste du gestionnaire est « ce créneau est en fait à 18h30 » — pas « annule
     * mes réservations ». On les SUIT donc (décision fondateur 2026-08-07), avec les
     * MÊMES bornes de couche que la purge ci-dessous : un créneau de saison ne touche
     * que les épinglages de base, un créneau de période que les siens.
     *
     * @param VenueTrainingSlot $before l'état AVANT modification (triplet d'origine)
     */
    public function moveChildrenOfSlot(VenueTrainingSlot $before, VenueTrainingSlot $after): void
    {
        if ($before->getVenueId() === $after->getVenueId()
            && $before->getDayOfWeek() === $after->getDayOfWeek()
            && $before->getStartTime()->format('H:i:s') === $after->getStartTime()->format('H:i:s')) {
            return; // le triplet n'a pas bougé : rien à suivre
        }

        $clubId = $before->getClubId();
        $seasonId = $before->getSeasonId();

        $this->withoutTenantFilters(function () use ($before, $after, $clubId, $seasonId): void {
            // Réservations ET verrous HARD qu'elles ont matérialisés — les mêmes deux
            // familles que `purgeChildrenOfSlot`, sinon le verrou resterait à l'ancien
            // horaire et `findBaseSlotTemplates` le ré-injecterait à chaque génération.
            $this->moveBySlotKey(Reservation::class, $before, $after, $clubId, $seasonId, hardOnly: false);
            $this->moveBySlotKey(ScheduleSlotTemplate::class, $before, $after, $clubId, $seasonId, hardOnly: true);
        });
    }

    /**
     * Exécute un plan de cascade dans l'ordre déclaré (les enfants avant les parents, les
     * bascules de période avant leurs contraintes — cf. {@see CascadePlan}).
     *
     * @param list<CascadeStep> $steps
     */
    private function run(array $steps, DeletionTarget $target): void
    {
        $this->withoutTenantFilters(function () use ($steps, $target): void {
            foreach ($steps as $step) {
                $step->execute($this->entityManager, $target);
            }
        });
    }

    /**
     * Le jumeau UPDATE de {@see deleteBySlotKey} : MÊME sélection (mêmes bornes de
     * couche, même famille), mais on réécrit le triplet au lieu de supprimer la ligne.
     * Écrit en DQL comme son jumeau : ces lignes ne passent pas par l'UnitOfWork, et
     * un `find`+`set` sur des centaines d'épinglages coûterait sans rien apporter.
     */
    private function moveBySlotKey(string $entityClass, VenueTrainingSlot $before, VenueTrainingSlot $after, string $clubId, string $seasonId, bool $hardOnly): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->update($entityClass, 'e')
            ->set('e.venueId', ':newVenueId')
            ->set('e.dayOfWeek', ':newDayOfWeek')
            ->set('e.startTime', ':newStartTime')
            ->where('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->andWhere('e.venueId = :venueId')
            ->andWhere('e.dayOfWeek = :dayOfWeek')
            ->andWhere('e.startTime = :startTime')
            ->setParameter('newVenueId', $after->getVenueId())
            ->setParameter('newDayOfWeek', $after->getDayOfWeek())
            ->setParameter('newStartTime', $after->getStartTime())
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->setParameter('venueId', $before->getVenueId())
            ->setParameter('dayOfWeek', $before->getDayOfWeek())
            ->setParameter('startTime', $before->getStartTime());

        $planId = $before->getSchedulePlanId();
        if (Reservation::class === $entityClass) {
            $qb->andWhere(null === $planId ? 'e.schedulePlanId IS NULL' : 'e.schedulePlanId = :planId');
            if (null !== $planId) {
                $qb->setParameter('planId', $planId);
            }
        } elseif (ScheduleSlotTemplate::class === $entityClass) {
            $scheduleIds = array_map(
                static fn (Schedule $s): string => $s->getId(),
                $this->entityManager->getRepository(Schedule::class)->findBy(['schedulePlanId' => $planId ?? $this->seasonPlanIds($seasonId)]),
            );
            if ([] === $scheduleIds) {
                return;
            }
            $qb->andWhere('e.scheduleId IN (:scheduleIds)')->setParameter('scheduleIds', $scheduleIds);
        }
        if ($hardOnly) {
            $qb->andWhere('e.lockLevel = :hard')->setParameter('hard', LockLevel::HARD);
        }
        $qb->getQuery()->execute();
    }

    /**
     * Les plans SOCLE de la saison — la couche d'un créneau saisonnier (schedulePlanId
     * null). Une liste, pas un id : rien n'interdit en base d'en avoir plus d'un, et un
     * `find` unique choisirait silencieusement.
     *
     * @return list<string>
     */
    private function seasonPlanIds(string $seasonId): array
    {
        return array_map(
            static fn (SchedulePlan $p): string => $p->getId(),
            $this->entityManager->getRepository(SchedulePlan::class)->findBy(['seasonId' => $seasonId, 'type' => SchedulePlanType::SEASON]),
        );
    }

    private function withoutTenantFilters(callable $work): void
    {
        $this->disableTenantFilters($this->entityManager);
        $work();
    }
}
