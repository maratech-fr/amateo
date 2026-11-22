<?php

declare(strict_types=1);

namespace App\Deletion;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Étape la plus courante : « toutes les lignes de X dont le champ Y vaut l'id supprimé
 * partent ». Reprend mot pour mot le `deleteByField` historique d'`EntityCascadeDeleter`,
 * bornes comprises (saison toujours, club sauf pour une table scopée par la seule saison ;
 * aucune étape ne l'est aujourd'hui).
 */
final readonly class DeleteByFieldStep implements CascadeStep
{
    /**
     * @param class-string $entityClass
     */
    public function __construct(
        private string $entityClass,
        private string $field,
        private ?ImpactLabel $label,
        /** `false` pour une table scopée par la SAISON seule (pas de colonne club). */
        private bool $clubScoped = true,
    ) {}

    public function label(): ?ImpactLabel
    {
        return $this->label;
    }

    public function count(EntityManagerInterface $entityManager, DeletionTarget $target): int
    {
        $qb = $entityManager->createQueryBuilder()->select('COUNT(e.id)')->from($this->entityClass, 'e');

        return (int) $this->scope($qb, $target)->getQuery()->getSingleScalarResult();
    }

    public function execute(EntityManagerInterface $entityManager, DeletionTarget $target): void
    {
        $qb = $entityManager->createQueryBuilder()->delete($this->entityClass, 'e');
        $this->scope($qb, $target)->getQuery()->execute();
    }

    private function scope(QueryBuilder $qb, DeletionTarget $target): QueryBuilder
    {
        $qb->where(\sprintf('e.%s = :value', $this->field))
            ->andWhere('e.seasonId = :seasonId')
            ->setParameter('value', $target->id)
            ->setParameter('seasonId', $target->seasonId);
        if ($this->clubScoped) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $target->clubId);
        }

        return $qb;
    }
}
