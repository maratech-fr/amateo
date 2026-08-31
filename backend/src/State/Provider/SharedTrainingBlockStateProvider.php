<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\SharedTrainingBlockResource;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use Symfony\Component\HttpFoundation\Request;

/**
 * @extends AbstractStateProvider<SharedTrainingBlock, SharedTrainingBlockResource>
 */
class SharedTrainingBlockStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    protected function getEntityClass(): string
    {
        return SharedTrainingBlock::class;
    }

    /**
     * @param SharedTrainingBlock $entity
     */
    protected function mapEntityToOutput(object $entity): SharedTrainingBlockResource
    {
        return SharedTrainingBlockResource::fromEntity($entity, $this->teamIdsOf($entity->getId()));
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, SharedTrainingBlockResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(SharedTrainingBlock::class, 'e');

        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            // Un filtre plan cible une période ; absence = socle ET périodes (le front trie).
            $schedulePlanId = $this->uuidQueryParam($request, 'schedulePlanId');
            if (null !== $schedulePlanId) {
                $qb->andWhere('e.schedulePlanId = :schedulePlanId')->setParameter('schedulePlanId', $schedulePlanId);
            }
        }

        $qb->orderBy('e.id', 'ASC');

        /** @var list<SharedTrainingBlock> $blocks */
        $blocks = $qb->getQuery()->getResult();

        // Les membres des blocs en UNE requête, pas une par bloc (patron SharedTrainingGroup).
        $teamIdsByBlock = $this->teamIdsOfMany(array_map(static fn (SharedTrainingBlock $b): string => $b->getId(), $blocks));

        return array_map(
            static fn (SharedTrainingBlock $block): SharedTrainingBlockResource => SharedTrainingBlockResource::fromEntity(
                $block,
                $teamIdsByBlock[$block->getId()] ?? [],
            ),
            $blocks,
        );
    }

    /**
     * Les ids d'équipe de PLUSIEURS blocs, en une requête.
     *
     * Même tri que {@see teamIdsOf} — `teamId` croissant — pour que la collection et l'item
     * rendent EXACTEMENT la même liste.
     *
     * @param list<string> $blockIds
     *
     * @return array<string, list<string>>
     */
    private function teamIdsOfMany(array $blockIds): array
    {
        if ([] === $blockIds) {
            return [];
        }

        /** @var list<array{blockId: string, teamId: string}> $rows */
        $rows = $this->entityManager->getRepository(SharedTrainingBlockTeam::class)
            ->createQueryBuilder('t')
            ->select('t.blockId', 't.teamId')
            ->where('t.blockId IN (:blockIds)')
            ->setParameter('blockIds', $blockIds)
            ->orderBy('t.teamId', 'ASC')
            ->getQuery()
            ->getScalarResult();

        $byBlock = [];
        foreach ($rows as $row) {
            $byBlock[$row['blockId']][] = $row['teamId'];
        }

        return $byBlock;
    }

    /**
     * Les ids d'équipe du bloc, triés (déterminisme : l'id d'une ligne membre est un UUID v4).
     *
     * @return list<string>
     */
    private function teamIdsOf(string $blockId): array
    {
        /** @var list<array{teamId: string}> $rows */
        $rows = $this->entityManager->getRepository(SharedTrainingBlockTeam::class)
            ->createQueryBuilder('t')
            ->select('t.teamId')
            ->where('t.blockId = :blockId')
            ->setParameter('blockId', $blockId)
            ->orderBy('t.teamId', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): string => $row['teamId'], $rows);
    }
}
