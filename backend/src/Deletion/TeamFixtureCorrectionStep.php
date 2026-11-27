<?php

declare(strict_types=1);

namespace App\Deletion;

use App\Entity\FbiCorrection;
use App\Entity\Fixture;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Les entrées « à corriger dans FBI » des matchs de l'équipe supprimée partent avant ses matchs : un match supprimé
 * n'a plus rien à corriger, et une entrée orpheline compterait dans « à corriger » sans jamais s'afficher. Registre
 * interne : l'étape n'est pas annoncée, le match l'est.
 */
final readonly class TeamFixtureCorrectionStep implements CascadeStep
{
    public function label(): ?ImpactLabel
    {
        return null;
    }

    public function count(EntityManagerInterface $entityManager, DeletionTarget $target): int
    {
        return (int) $this->scope($entityManager->createQueryBuilder()->select('COUNT(c.id)')->from(FbiCorrection::class, 'c'), $entityManager, $target)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function execute(EntityManagerInterface $entityManager, DeletionTarget $target): void
    {
        $this->scope($entityManager->createQueryBuilder()->delete(FbiCorrection::class, 'c'), $entityManager, $target)
            ->getQuery()
            ->execute();
    }

    private function scope(QueryBuilder $qb, EntityManagerInterface $entityManager, DeletionTarget $target): QueryBuilder
    {
        $fixtures = $entityManager->createQueryBuilder()
            ->select('f.id')
            ->from(Fixture::class, 'f')
            ->where('f.teamId = :teamId')
            ->andWhere('f.clubId = :clubId')
            ->andWhere('f.seasonId = :seasonId');

        return $qb->where($qb->expr()->in('c.fixtureId', $fixtures->getDQL()))
            ->andWhere('c.clubId = :clubId')
            ->andWhere('c.seasonId = :seasonId')
            ->setParameter('teamId', $target->id)
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId);
    }
}
