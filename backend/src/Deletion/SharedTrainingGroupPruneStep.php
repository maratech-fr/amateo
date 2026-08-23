<?php

declare(strict_types=1);

namespace App\Deletion;

use App\Entity\SharedTrainingGroup;
use App\Entity\SharedTrainingGroupTeam;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P2-27 / P2-46 PR-4 — la mutualisation : supprimer une équipe membre TUE le groupe ENTIER.
 *
 * « N équipes s'entraînent ensemble » n'a pas de sens quand l'une d'elles disparaît (décision
 * fondateur) : le groupe part avec TOUTES ses lignes {@see SharedTrainingGroupTeam}, pas
 * seulement celle de l'équipe supprimée. Une case autrefois « groupe-complète » ne pourrait
 * plus l'être — la garder ne ferait qu'y laisser des verrous orphelins que le moteur lit
 * encore. Les réservations de lot des AUTRES membres partent par leur propre étape
 * ({@see SharedGroupReservationPruneStep}), placée AVANT la purge des réservations de l'équipe.
 *
 * Le COMPTE annoncé — le nombre de groupes où l'équipe figure — est désormais EXACTEMENT ce qui
 * est détruit (chaque groupe où elle figure meurt), sans règle de survie à rejouer côté
 * compteur : la parité se lit d'un trait.
 */
final readonly class SharedTrainingGroupPruneStep implements CascadeStep
{
    public function __construct(private ImpactLabel $label) {}

    public function label(): ImpactLabel
    {
        return $this->label;
    }

    public function count(EntityManagerInterface $entityManager, DeletionTarget $target): int
    {
        return (int) $entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT t.groupId)')
            ->from(SharedTrainingGroupTeam::class, 't')
            ->where('t.teamId = :teamId')
            ->andWhere('t.clubId = :clubId')
            ->andWhere('t.seasonId = :seasonId')
            ->setParameter('teamId', $target->id)
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function execute(EntityManagerInterface $entityManager, DeletionTarget $target): void
    {
        /** @var list<string> $affectedGroupIds */
        $affectedGroupIds = $entityManager->createQueryBuilder()
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

        if ([] === $affectedGroupIds) {
            return;
        }

        // Toutes les lignes des groupes visés (celles des membres survivants comprises), puis les
        // groupes eux-mêmes : le groupe entier meurt, pas seulement la ligne de l'équipe supprimée.
        $entityManager->createQueryBuilder()
            ->delete(SharedTrainingGroupTeam::class, 't')
            ->where('t.groupId IN (:groupIds)')
            ->setParameter('groupIds', $affectedGroupIds)
            ->getQuery()
            ->execute();

        $entityManager->createQueryBuilder()
            ->delete(SharedTrainingGroup::class, 'g')
            ->where('g.id IN (:groupIds)')
            ->setParameter('groupIds', $affectedGroupIds)
            ->getQuery()
            ->execute();
    }
}
