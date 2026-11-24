<?php

declare(strict_types=1);

namespace App\Deletion;

use App\Entity\Constraint;
use App\Entity\PlanConstraint;
use App\Enum\ConstraintScope;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les contraintes qui VISENT l'entité supprimée (portée `TEAM`/`FACILITY`/`COACH` pointant
 * son id), et leurs lignes de règle dans les plans de période.
 *
 * ⚠ L'ordre compte et il est repris tel quel de l'implémentation historique : les lignes
 * `PlanConstraint` partent AVANT leurs contraintes, sinon elles pendent sur un
 * `constraintId` mort (même contrat « aucun orphelin » que
 * `ConstraintStateProcessor::cascadeBeforeDelete`).
 *
 * Le COMPTE annoncé est celui des contraintes — pas celui des lignes : c'est la règle que
 * le gestionnaire a écrite et qu'il reconnaîtra, la ligne n'est qu'un réglage qui la suit.
 */
final readonly class ScopedConstraintStep implements CascadeStep
{
    public function __construct(
        private ConstraintScope $scope,
        private ImpactLabel $label,
    ) {}

    public function label(): ImpactLabel
    {
        return $this->label;
    }

    public function count(EntityManagerInterface $entityManager, DeletionTarget $target): int
    {
        return (int) $entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(Constraint::class, 'e')
            ->where('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->andWhere('e.scope = :scope')
            ->andWhere('e.scopeTargetId = :target')
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId)
            ->setParameter('scope', $this->scope)
            ->setParameter('target', $target->id)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function execute(EntityManagerInterface $entityManager, DeletionTarget $target): void
    {
        /** @var list<string> $constraintIds */
        $constraintIds = $entityManager->createQueryBuilder()
            ->select('e.id')
            ->from(Constraint::class, 'e')
            ->where('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->andWhere('e.scope = :scope')
            ->andWhere('e.scopeTargetId = :target')
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId)
            ->setParameter('scope', $this->scope)
            ->setParameter('target', $target->id)
            ->getQuery()
            ->getSingleColumnResult();

        if ([] !== $constraintIds) {
            $entityManager->createQueryBuilder()
                ->delete(PlanConstraint::class, 'o')
                ->where('o.constraintId IN (:ids)')
                ->setParameter('ids', $constraintIds)
                ->getQuery()
                ->execute();
        }

        $entityManager->createQueryBuilder()
            ->delete(Constraint::class, 'e')
            ->where('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->andWhere('e.scope = :scope')
            ->andWhere('e.scopeTargetId = :target')
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId)
            ->setParameter('scope', $this->scope)
            ->setParameter('target', $target->id)
            ->getQuery()
            ->execute();
    }
}
