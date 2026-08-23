<?php

declare(strict_types=1);

namespace App\Deletion;

use App\Entity\Reservation;
use App\Entity\SharedTrainingGroupTeam;
use App\Service\ReservationGroupOccupancy;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P2-46 PR-4 — le groupe mort emporte les réservations de lot des AUTRES membres.
 *
 * Supprimer une équipe membre tue le groupe entier ({@see SharedTrainingGroupPruneStep}). Les
 * réservations posées sur ses cases « groupe-complètes » deviendraient alors des verrous HARD
 * orphelins : le bloc `sharedTrainings` qui les exemptait (PR-1) ayant disparu, les N-1 verrous
 * restants feraient déclencher au moteur un diagnostic de sur-capacité FANTÔME (« le gymnase
 * accueille 3 équipes… capacité 2 »), sans cause visible pour le gestionnaire. On les supprime
 * donc AVEC le groupe.
 *
 * ⚠ ORDRE : cette étape passe AVANT la purge des réservations de l'équipe supprimée
 * ({@see CascadePlan::forTeam}), parce qu'une case n'est « groupe-complète » que TANT que la
 * réservation de l'équipe supprimée y figure encore. On ne compte / supprime ici que les
 * réservations des AUTRES membres : celle de l'équipe supprimée part par l'étape `team_reservation`.
 *
 * La dérivation « case groupe-complète » n'est PAS réécrite : elle vient de sa maison unique,
 * {@see ReservationGroupOccupancy::reservationsOnGroupCompleteCases}.
 */
final readonly class SharedGroupReservationPruneStep implements CascadeStep
{
    public function __construct(private ImpactLabel $label) {}

    public function label(): ImpactLabel
    {
        return $this->label;
    }

    public function count(EntityManagerInterface $entityManager, DeletionTarget $target): int
    {
        return \count($this->batchReservationIds($entityManager, $target));
    }

    public function execute(EntityManagerInterface $entityManager, DeletionTarget $target): void
    {
        $ids = $this->batchReservationIds($entityManager, $target);
        if ([] === $ids) {
            return;
        }

        $entityManager->createQueryBuilder()
            ->delete(Reservation::class, 'r')
            ->where('r.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    /**
     * Les réservations des AUTRES membres, sur les cases « groupe-complètes » de chaque groupe où
     * l'équipe supprimée figure — dans la portée (socle vs période) propre à ce groupe.
     *
     * @return list<string>
     */
    private function batchReservationIds(EntityManagerInterface $entityManager, DeletionTarget $target): array
    {
        /** @var list<string> $groupIds */
        $groupIds = $entityManager->createQueryBuilder()
            ->select('DISTINCT t.groupId')
            ->from(SharedTrainingGroupTeam::class, 't')
            ->where('t.teamId = :teamId')
            ->andWhere('t.clubId = :clubId')
            ->andWhere('t.seasonId = :seasonId')
            ->setParameter('teamId', $target->id)
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId)
            ->getQuery()
            ->getSingleColumnResult();

        $ids = [];
        foreach ($groupIds as $groupId) {
            /** @var list<SharedTrainingGroupTeam> $members */
            $members = $entityManager->getRepository(SharedTrainingGroupTeam::class)->findBy(['groupId' => $groupId]);
            if ([] === $members) {
                continue;
            }
            $memberSet = [];
            foreach ($members as $member) {
                $memberSet[$member->getTeamId()] = true;
            }

            $reservations = $this->reservationsInScope($entityManager, $target, $members[0]->getSchedulePlanId());
            foreach (ReservationGroupOccupancy::reservationsOnGroupCompleteCases($reservations, $memberSet) as $reservation) {
                if ($reservation->getTeamId() === $target->id) {
                    continue; // celle de l'équipe supprimée : comptée / détruite par `team_reservation`
                }
                $ids[$reservation->getId()] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Réservations d'UNE portée (club + saison explicites — les filtres tenant sont désactivés
     * pendant la cascade — et le plan du groupe : socle `null` vs période).
     *
     * @return list<Reservation>
     */
    private function reservationsInScope(EntityManagerInterface $entityManager, DeletionTarget $target, ?string $schedulePlanId): array
    {
        $qb = $entityManager->getRepository(Reservation::class)->createQueryBuilder('r')
            ->where('r.clubId = :clubId')
            ->andWhere('r.seasonId = :seasonId')
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId);

        if (null === $schedulePlanId) {
            $qb->andWhere('r.schedulePlanId IS NULL');
        } else {
            $qb->andWhere('r.schedulePlanId = :planId')->setParameter('planId', $schedulePlanId);
        }

        /** @var list<Reservation> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }
}
