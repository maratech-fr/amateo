<?php

declare(strict_types=1);

namespace App\Deletion;

use App\Entity\MatchSlotRotation;
use App\Entity\MatchSlotRotationTeam;
use Doctrine\ORM\EntityManagerInterface;

/**
 * RMM-5 (P2-49) — supprimer une équipe la retire de ses créneaux de match partagés, et
 * SUPPRIME ceux qui tombent sous 2 membres (un créneau partagé à une équipe n'alterne plus).
 *
 * Deux gestes, une seule règle : la rotation qui GARDE au moins 2 membres SURVIT — l'équipe
 * la quitte, rien d'autre ne change (« purge du membre ») ; la rotation qui tomberait à 1
 * membre MEURT, parent et lignes membres (« groupe < 2 → supprimé »). C'est la différence
 * avec {@see SharedTrainingBlockPruneStep}, où le bloc entier meurt sans seuil de survie.
 *
 * ⚑ Ce que la ligne d'impact ANNONCE et COMPTE, c'est le nombre de créneaux partagés
 * SUPPRIMÉS (ceux qui tombent < 2). Le retrait de l'équipe d'un créneau qui survit n'est pas
 * la destruction d'un objet — c'est l'équipe qui s'en va, la conséquence directe de sa
 * suppression, comme ses habitudes ou ses liens partent avec elle. Compte et destruction se
 * lisent d'un trait : count() = créneaux < 2, execute() supprime EXACTEMENT ceux-là.
 */
final readonly class MatchSlotRotationTeamPruneStep implements CascadeStep
{
    public function __construct(private ImpactLabel $label) {}

    public function label(): ImpactLabel
    {
        return $this->label;
    }

    public function count(EntityManagerInterface $entityManager, DeletionTarget $target): int
    {
        return \count($this->dyingRotationIds($entityManager, $target));
    }

    public function execute(EntityManagerInterface $entityManager, DeletionTarget $target): void
    {
        $dying = $this->dyingRotationIds($entityManager, $target);

        if ([] !== $dying) {
            // Le créneau meurt entier : toutes ses lignes membres (survivants compris), puis le
            // parent.
            $entityManager->createQueryBuilder()
                ->delete(MatchSlotRotationTeam::class, 't')
                ->where('t.rotationId IN (:ids)')
                ->setParameter('ids', $dying)
                ->getQuery()
                ->execute();
            $entityManager->createQueryBuilder()
                ->delete(MatchSlotRotation::class, 'r')
                ->where('r.id IN (:ids)')
                ->setParameter('ids', $dying)
                ->getQuery()
                ->execute();
        }

        // Les créneaux SURVIVANTS (≥ 2 après le départ) ne perdent QUE la ligne de l'équipe
        // supprimée. Les lignes des rotations mortes sont déjà parties ci-dessus.
        $entityManager->createQueryBuilder()
            ->delete(MatchSlotRotationTeam::class, 't')
            ->where('t.teamId = :teamId')
            ->andWhere('t.clubId = :clubId')
            ->andWhere('t.seasonId = :seasonId')
            ->setParameter('teamId', $target->id)
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId)
            ->getQuery()
            ->execute();
    }

    /**
     * Les rotations où l'équipe figure et qui tomberaient sous 2 membres si elle partait
     * (état PRÉ-suppression — le compteur et l'exécuteur le lisent identiquement).
     *
     * @return list<string>
     */
    private function dyingRotationIds(EntityManagerInterface $entityManager, DeletionTarget $target): array
    {
        /** @var list<string> $rotationIds */
        $rotationIds = $entityManager->createQueryBuilder()
            ->select('DISTINCT t.rotationId')
            ->from(MatchSlotRotationTeam::class, 't')
            ->where('t.teamId = :teamId')
            ->andWhere('t.clubId = :clubId')
            ->andWhere('t.seasonId = :seasonId')
            ->setParameter('teamId', $target->id)
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId)
            ->getQuery()
            ->getSingleColumnResult();

        $dying = [];
        foreach ($rotationIds as $rotationId) {
            $members = (int) $entityManager->createQueryBuilder()
                ->select('COUNT(t.id)')
                ->from(MatchSlotRotationTeam::class, 't')
                ->where('t.rotationId = :rotationId')
                ->setParameter('rotationId', $rotationId)
                ->getQuery()
                ->getSingleScalarResult();
            if ($members - 1 < 2) {
                $dying[] = $rotationId;
            }
        }

        return $dying;
    }
}
