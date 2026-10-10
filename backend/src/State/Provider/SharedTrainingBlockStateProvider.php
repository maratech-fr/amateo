<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\SharedTrainingBlockResource;
use App\ApiResource\SharedTrainingBlockSession;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\Venue;
use DateTimeImmutable;
use Doctrine\ORM\Query\Expr\Join;
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
        return SharedTrainingBlockResource::fromEntity(
            $entity,
            $this->teamIdsOf($entity->getId()),
            $this->sessionsOfMany([$entity->getId()])[$entity->getId()] ?? [],
        );
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

        // Les membres ET les séances placées des blocs en UNE requête CHACUN, pas une par bloc.
        $blockIds = array_map(static fn (SharedTrainingBlock $b): string => $b->getId(), $blocks);
        $teamIdsByBlock = $this->teamIdsOfMany($blockIds);
        $sessionsByBlock = $this->sessionsOfMany($blockIds);

        return array_map(
            static fn (SharedTrainingBlock $block): SharedTrainingBlockResource => SharedTrainingBlockResource::fromEntity(
                $block,
                $teamIdsByBlock[$block->getId()] ?? [],
                $sessionsByBlock[$block->getId()] ?? [],
            ),
            $blocks,
        );
    }

    /**
     * Les SÉANCES PLACÉES de plusieurs blocs (créneaux dont `shared_training_block_id` cible le
     * bloc), en une requête — jamais une par bloc. Le join passe par le filtre tenant Doctrine
     * (`ScheduleSlotTemplate`/`Team`/`Venue` sont tous scopés club) : aucun créneau d'un autre club
     * ne remonte. Un bloc socle ou sans séance liée n'a simplement aucune ligne ici → `[]`.
     *
     * @param list<string> $blockIds
     *
     * @return array<string, list<SharedTrainingBlockSession>>
     */
    private function sessionsOfMany(array $blockIds): array
    {
        if ([] === $blockIds) {
            return [];
        }

        /** @var list<array{blockId: string, teamId: string, teamName: string, dayOfWeek: int, startTime: DateTimeImmutable, durationMinutes: int, venueName: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                's.sharedTrainingBlockId AS blockId',
                's.teamId AS teamId',
                't.name AS teamName',
                's.dayOfWeek AS dayOfWeek',
                's.startTime AS startTime',
                's.durationMinutes AS durationMinutes',
                'v.name AS venueName',
            )
            ->from(ScheduleSlotTemplate::class, 's')
            ->innerJoin(Team::class, 't', Join::WITH, 't.id = s.teamId')
            ->innerJoin(Venue::class, 'v', Join::WITH, 'v.id = s.venueId')
            ->where('s.sharedTrainingBlockId IN (:blockIds)')
            ->setParameter('blockIds', $blockIds)
            ->orderBy('s.dayOfWeek', 'ASC')
            ->addOrderBy('s.startTime', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        $byBlock = [];
        foreach ($rows as $row) {
            $byBlock[$row['blockId']][] = SharedTrainingBlockSession::of(
                $row['teamId'],
                $row['teamName'],
                $row['dayOfWeek'],
                $row['startTime'],
                $row['startTime']->modify(\sprintf('+%d minutes', $row['durationMinutes'])),
                $row['venueName'],
            );
        }

        return $byBlock;
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
