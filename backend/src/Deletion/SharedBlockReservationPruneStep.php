<?php

declare(strict_types=1);

namespace App\Deletion;

use App\Entity\Reservation;
use App\Entity\SharedTrainingBlockTeam;
use App\Service\ReservationGroupOccupancy;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P2-51 PR-7 — le bloc mort emporte les réservations « bloc-complètes » des AUTRES membres.
 *
 * Supprimer une équipe membre tue le bloc ENTIER ({@see SharedTrainingBlockPruneStep}). Les
 * réservations posées par le rail de réservation groupée (`SharedTrainingBlock` → N verrous d'UNE
 * case) deviendraient alors des verrous HARD orphelins : le bloc `sharedBlocks` qui les exemptait
 * ayant disparu, les N-1 verrous restants feraient déclencher au moteur un diagnostic de
 * sur-capacité FANTÔME (« le gymnase accueille 3 équipes… capacité 2 »), sans cause visible pour le
 * gestionnaire. On les supprime donc AVEC le bloc (D12, « tout défaire puis recréer »).
 *
 * ⚠ ORDRE : cette étape passe AVANT la purge des réservations de l'équipe supprimée
 * ({@see CascadePlan::forTeam}), parce qu'une case n'est « bloc-complète » que TANT que la
 * réservation de l'équipe supprimée y figure encore. On ne compte / supprime ici que les
 * réservations des AUTRES membres : celle de l'équipe supprimée part par l'étape `team_reservation`.
 *
 * ⚠ Sémantique « bloc-complète » vs individuelle : une case est bloc-complète quand l'ensemble des
 * équipes réservées y est EXACTEMENT le jeu de membres du bloc ({@see ReservationGroupOccupancy::reservationsOnGroupCompleteCases},
 * maison unique, pas de marqueur stocké). Une réservation INDIVIDUELLE posée à la main — sur une
 * case dont l'ensemble réservé n'égale pas le jeu de membres — n'est PAS une séance mutualisée :
 * elle SURVIT, même quand elle appartient à un membre du bloc.
 */
final readonly class SharedBlockReservationPruneStep implements CascadeStep
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
     * Les réservations des AUTRES membres, sur les cases « bloc-complètes » de chaque bloc où
     * l'équipe supprimée figure — dans la portée (socle vs période) propre à ce bloc.
     *
     * @return list<string>
     */
    private function batchReservationIds(EntityManagerInterface $entityManager, DeletionTarget $target): array
    {
        /** @var list<string> $blockIds */
        $blockIds = $entityManager->createQueryBuilder()
            ->select('DISTINCT t.blockId')
            ->from(SharedTrainingBlockTeam::class, 't')
            ->where('t.teamId = :teamId')
            ->andWhere('t.clubId = :clubId')
            ->andWhere('t.seasonId = :seasonId')
            ->setParameter('teamId', $target->id)
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId)
            ->getQuery()
            ->getSingleColumnResult();

        $ids = [];
        foreach ($blockIds as $blockId) {
            /** @var list<SharedTrainingBlockTeam> $members */
            $members = $entityManager->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $blockId]);
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
     * pendant la cascade — et le plan du bloc : socle `null` vs période).
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
