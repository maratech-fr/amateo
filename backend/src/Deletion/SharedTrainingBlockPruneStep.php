<?php

declare(strict_types=1);

namespace App\Deletion;

use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P2-51 — le bloc de mutualisation : supprimer une équipe membre TUE le bloc ENTIER.
 *
 * « cet ensemble d'équipes se comporte comme une équipe » n'a plus de sens quand l'une d'elles
 * disparaît (décision fondateur 2026-08-31 : « tout défaire puis recréer ») : le bloc part avec
 * TOUTES ses lignes {@see SharedTrainingBlockTeam}, pas seulement celle de l'équipe supprimée.
 * Mort ENTIÈRE, PAS le seuil de survie à 2 de la rotation match. Le bloc part avec ses seules
 * lignes membres ; ses réservations « bloc-complètes » partent, elles, par deux rails
 * complémentaires : celle de l'équipe supprimée par `team_reservation`, celles des AUTRES membres
 * par {@see SharedBlockReservationPruneStep} (qui passe AVANT, tant que la case est encore complète).
 *
 * Le COMPTE annoncé — le nombre de blocs où l'équipe figure — est EXACTEMENT ce qui est détruit
 * (chaque bloc où elle figure meurt), sans règle de survie à rejouer côté compteur.
 */
final readonly class SharedTrainingBlockPruneStep implements CascadeStep
{
    public function __construct(private ImpactLabel $label) {}

    public function label(): ImpactLabel
    {
        return $this->label;
    }

    public function count(EntityManagerInterface $entityManager, DeletionTarget $target): int
    {
        return (int) $entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT t.blockId)')
            ->from(SharedTrainingBlockTeam::class, 't')
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
        /** @var list<string> $affectedBlockIds */
        $affectedBlockIds = $entityManager->createQueryBuilder()
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

        if ([] === $affectedBlockIds) {
            return;
        }

        // Toutes les lignes des blocs visés (celles des membres survivants comprises), puis les
        // blocs eux-mêmes : le bloc entier meurt, pas seulement la ligne de l'équipe supprimée.
        $entityManager->createQueryBuilder()
            ->delete(SharedTrainingBlockTeam::class, 't')
            ->where('t.blockId IN (:blockIds)')
            ->setParameter('blockIds', $affectedBlockIds)
            ->getQuery()
            ->execute();

        $entityManager->createQueryBuilder()
            ->delete(SharedTrainingBlock::class, 'b')
            ->where('b.id IN (:blockIds)')
            ->setParameter('blockIds', $affectedBlockIds)
            ->getQuery()
            ->execute();
    }
}
