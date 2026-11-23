<?php

declare(strict_types=1);

namespace App\Deletion;

use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\LockLevel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use LogicException;

/**
 * Les ÉPINGLAGES d'un créneau, identifiés par (gymnase, jour, heure) — ni une réservation ni
 * un verrou ne cite l'id du créneau.
 *
 * ⚠️ Bornés à la COUCHE du créneau supprimé (#8) : un créneau de SAISON emporte les épinglages
 * de base (`schedulePlanId` null), un créneau de PÉRIODE ceux de SA période. Sans cette borne,
 * vider la grille d'une période détruirait les réservations du planning principal au même
 * horaire — le planning principal n'est JAMAIS modifié par une période (invariant fondateur n°1).
 *
 * ⚑ Logique DÉPLACÉE telle quelle depuis `EntityCascadeDeleter::deleteBySlotKey`, pas réécrite :
 * c'est du code destructif et subtil. Ce que cette étape ajoute, c'est de savoir aussi **se
 * compter**, donc de pouvoir être ANNONCÉE avant confirmation — l'objet du lot.
 */
final readonly class SlotPinStep implements CascadeStep
{
    /**
     * @param class-string $entityClass {@see Reservation} ou {@see ScheduleSlotTemplate}
     * @param bool         $hardOnly    ne viser que les verrous HARD (les seuls qu'une
     *                                  réservation matérialise) — jamais les placements
     *                                  SOFT/NONE choisis par le solveur, qui sont des
     *                                  RÉSULTATS et appartiennent à leur version
     */
    public function __construct(
        private string $entityClass,
        private bool $hardOnly,
        private ?ImpactLabel $label,
    ) {}

    public function label(): ?ImpactLabel
    {
        return $this->label;
    }

    public function count(EntityManagerInterface $entityManager, DeletionTarget $target): int
    {
        $qb = $entityManager->createQueryBuilder()->select('COUNT(e.id)')->from($this->entityClass, 'e');
        $scoped = $this->scope($qb, $entityManager, $this->slotTarget($target));

        // `null` = aucune version sur cette couche : rien à emporter, donc rien à annoncer.
        return $scoped instanceof QueryBuilder ? (int) $scoped->getQuery()->getSingleScalarResult() : 0;
    }

    public function execute(EntityManagerInterface $entityManager, DeletionTarget $target): void
    {
        $qb = $entityManager->createQueryBuilder()->delete($this->entityClass, 'e');
        $this->scope($qb, $entityManager, $this->slotTarget($target))?->getQuery()->execute();
    }

    private function slotTarget(DeletionTarget $target): SlotDeletionTarget
    {
        if (!$target instanceof SlotDeletionTarget) {
            throw new LogicException('Une étape d’épinglage exige la cible d’un créneau (triplet + couche).');
        }

        return $target;
    }

    /** `null` quand la couche ne porte aucune version : il n'y a alors aucun verrou à viser. */
    private function scope(QueryBuilder $qb, EntityManagerInterface $entityManager, SlotDeletionTarget $target): ?QueryBuilder
    {
        $qb->where('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->andWhere('e.venueId = :venueId')
            ->andWhere('e.dayOfWeek = :dayOfWeek')
            ->andWhere('e.startTime = :startTime')
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId)
            ->setParameter('venueId', $target->venueId)
            ->setParameter('dayOfWeek', $target->dayOfWeek)
            ->setParameter('startTime', $target->startTime);

        $planId = $target->schedulePlanId;
        if (Reservation::class === $this->entityClass) {
            // La réservation porte son plan : borne EXACTE. Un créneau de saison n'emporte
            // que les réservations de base, un créneau de période que les siennes.
            $qb->andWhere('e.schedulePlanId = :planId')->setParameter('planId', $planId);
        } elseif (ScheduleSlotTemplate::class === $this->entityClass) {
            // Le verrou ne porte que sa VERSION, jamais un plan : on borne donc aux versions
            // de la COUCHE du créneau supprimé — celles de CE plan pour un créneau de période,
            // celles du plan de SAISON pour un créneau de socle.
            //
            // La borne va dans les DEUX SENS depuis #8, et c'est nécessaire : une période
            // possède désormais sa grille, une copie. Supprimer un créneau de saison ne
            // supprime PAS la copie qu'en détient la période — emporter au passage le verrou
            // que le gestionnaire y avait posé laissait la période avec un créneau toujours
            // offert mais son épinglage disparu, sans un mot.
            $scheduleIds = array_map(
                static fn (Schedule $s): string => $s->getId(),
                $entityManager->getRepository(Schedule::class)->findBy(['schedulePlanId' => $planId]),
            );
            if ([] === $scheduleIds) {
                return null;
            }
            $qb->andWhere('e.scheduleId IN (:scheduleIds)')->setParameter('scheduleIds', $scheduleIds);
        }
        if ($this->hardOnly) {
            $qb->andWhere('e.lockLevel = :hard')->setParameter('hard', LockLevel::HARD);
        }

        return $qb;
    }
}
