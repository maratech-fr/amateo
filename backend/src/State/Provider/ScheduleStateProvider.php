<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use App\ApiResource\ScheduleResource;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\ScheduleStructureSnapshot;
use App\Entity\Season;
use App\Enum\LockLevel;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use App\Service\ScheduleCapabilityResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @extends AbstractStateProvider<Schedule, ScheduleResource>
 */
class ScheduleStateProvider extends AbstractStateProvider
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        Pagination $pagination,
        private readonly ScheduleCapabilityResolver $capabilityResolver,
    ) {
        parent::__construct($entityManager, $requestStack, $pagination);
    }

    /**
     * Enrich the mapped DTO(s) with `hasStructurePhoto` (D3 gating), `isLiveContext`
     * (★), `isChosen` (ADR-0002: its plan points at it), `planType` (ADR-0002 C4:
     * SEASON = socle vs overlay) and the P2-8 `capabilities` block (permissions computed
     * server-side by the SAME code as the write guards) — all in BATCH, so a per-DTO
     * lookup never N+1s the schedules collection. All lookups are tenant-scoped by the
     * Doctrine tenant_filter (ScheduleStructureSnapshot / Season / SchedulePlan / Schedule
     * each own a club_id), so they never cross clubs.
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $result = parent::provide($operation, $uriVariables, $context);
        if (null === $result) {
            return null;
        }

        /** @var iterable<ScheduleResource> $dtos */
        $dtos = $result instanceof ScheduleResource ? [$result] : $result;
        $ids = [];
        foreach ($dtos as $dto) {
            $ids[] = $dto->id;
        }
        if ([] === $ids) {
            return $result;
        }

        $withPhoto = $this->scheduleIdsWithPhoto($ids);
        $liveContext = $this->liveContextScheduleIds($ids);
        $chosen = $this->chosenScheduleIds($ids);
        $planType = $this->planTypeByScheduleId($ids);
        $lockSource = $this->lockSourceScheduleIds($ids);
        $hardLockCount = $this->hardLockCountByScheduleId($ids);
        // P2-8 : le résolveur travaille sur les ENTITÉS (il lui faut club/saison, absents
        // du DTO). Une seule requête pour tout le lot (identity-map déjà chaude), puis les
        // ensembles déjà calculés ci-dessus sont réutilisés — pas de double interrogation.
        $capabilities = $this->capabilityResolver->forSchedules(
            $this->entityManager->getRepository(Schedule::class)->findBy(['id' => $ids]),
            $chosen,
            $planType,
            $withPhoto,
        );
        foreach ($dtos as $dto) {
            $dto->hasStructurePhoto = isset($withPhoto[$dto->id]);
            $dto->isLiveContext = isset($liveContext[$dto->id]);
            $dto->isChosen = isset($chosen[$dto->id]);
            $dto->planType = $planType[$dto->id] ?? null;
            $dto->isLockSource = isset($lockSource[$dto->id]);
            $dto->hardLockCount = $hardLockCount[$dto->id] ?? 0;
            $dto->capabilities = $capabilities[$dto->id] ?? null;
        }

        return $result;
    }

    protected function getEntityClass(): string
    {
        return Schedule::class;
    }

    /**
     * @param Schedule $entity
     */
    protected function mapEntityToOutput(object $entity): ScheduleResource
    {
        return ScheduleResource::fromEntity($entity);
    }

    /**
     * @param list<string> $scheduleIds
     *
     * @return array<string, true> set of schedule ids that own a structure photo
     */
    private function scheduleIdsWithPhoto(array $scheduleIds): array
    {
        return $this->idSetFromColumn(ScheduleStructureSnapshot::class, 'scheduleId', $scheduleIds);
    }

    /**
     * @param list<string> $scheduleIds
     *
     * @return array<string, true> set of schedule ids that ARE some season's loaded context (★)
     */
    private function liveContextScheduleIds(array $scheduleIds): array
    {
        return $this->idSetFromColumn(Season::class, 'liveContextScheduleId', $scheduleIds);
    }

    /**
     * @param list<string> $scheduleIds
     *
     * @return array<string, true> set of schedule ids their plan POINTS at (ADR-0002 inv. 1)
     */
    private function chosenScheduleIds(array $scheduleIds): array
    {
        return $this->idSetFromColumn(SchedulePlan::class, 'chosenScheduleId', $scheduleIds);
    }

    /**
     * ADR-0002 C4 : le type de plan de chaque version — SEASON | CLOSURE | HOLIDAY,
     * la seule vérité du « socle ? ». Un JOIN batché (Schedule.schedulePlanId →
     * SchedulePlan.type), tenant-scopé par le tenant_filter (les deux entités portent
     * un club_id), donc jamais cross-club. Une version sans plan (anomalie) est
     * absente du JOIN → planType null côté DTO.
     *
     * @param list<string> $scheduleIds
     *
     * @return array<string, string> scheduleId → type de plan
     */
    private function planTypeByScheduleId(array $scheduleIds): array
    {
        /** @var list<array{id: string, planType: string|SchedulePlanType}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('s.id AS id', 'p.type AS planType')
            ->from(Schedule::class, 's')
            ->innerJoin(SchedulePlan::class, 'p', 'WITH', 'p.id = s.schedulePlanId')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $scheduleIds)
            ->getQuery()
            ->getScalarResult();

        $map = [];
        foreach ($rows as $row) {
            // getScalarResult peut rendre l'enum OU sa valeur scalaire selon la voie
            // d'hydratation Doctrine — on normalise vers la valeur string du DTO.
            $type = $row['planType'];
            $map[$row['id']] = $type instanceof SchedulePlanType ? $type->value : $type;
        }

        return $map;
    }

    /**
     * V1 — les versions du lot qui sont la SOURCE des verrous de leur plan (ADR-0002 : les données
     * « en cours », d'où « Régénérer » repart). MÊME définition que la maison unique {@see
     * \App\Service\SchedulePlanProvisioner::lockSourceScheduleIdOfPlan} : la version COMPLETED
     * lexicographiquement maximale sur (createdAt, versionNumber) de son plan — ici en BATCH par un
     * `NOT EXISTS` de pair strictement postérieur, tenant-filtré (Schedule porte un club_id), jamais
     * cross-club. versionNumber étant unique par plan, le max est unique.
     *
     * @param list<string> $scheduleIds
     *
     * @return array<string, true>
     */
    private function lockSourceScheduleIds(array $scheduleIds): array
    {
        /** @var list<array{id: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('s.id AS id')
            ->from(Schedule::class, 's')
            ->where('s.id IN (:ids)')
            ->andWhere('s.status = :completed')
            ->andWhere('NOT EXISTS (SELECT 1 FROM ' . Schedule::class . ' s2 WHERE s2.schedulePlanId = s.schedulePlanId AND s2.status = :completed AND (s2.createdAt > s.createdAt OR (s2.createdAt = s.createdAt AND s2.versionNumber > s.versionNumber)))')
            ->setParameter('ids', $scheduleIds)
            ->setParameter('completed', ScheduleStatus::COMPLETED)
            ->getQuery()
            ->getScalarResult();

        $set = [];
        foreach ($rows as $row) {
            $set[$row['id']] = true;
        }

        return $set;
    }

    /**
     * V1 — le nombre de verrous HARD (épingles de work-loop) de chaque version du lot, en UN batch
     * groupé. Tenant-filtré (ScheduleSlotTemplate porte un club_id). Une version sans verrou HARD est
     * absente de la carte (→ 0 côté DTO).
     *
     * @param list<string> $scheduleIds
     *
     * @return array<string, int>
     */
    private function hardLockCountByScheduleId(array $scheduleIds): array
    {
        /** @var list<array{sid: string, cnt: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('t.scheduleId AS sid', 'COUNT(t.id) AS cnt')
            ->from(ScheduleSlotTemplate::class, 't')
            ->where('t.scheduleId IN (:ids)')
            ->andWhere('t.lockLevel = :hard')
            ->groupBy('t.scheduleId')
            ->setParameter('ids', $scheduleIds)
            ->setParameter('hard', LockLevel::HARD)
            ->getQuery()
            ->getScalarResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['sid']] = (int) $row['cnt'];
        }

        return $map;
    }

    /**
     * The set of `$ids` that appear in `$entityClass.$column` — one tenant-scoped
     * batch query folded into a lookup set. (A NULL column value never matches an
     * IN (:ids) clause, so no null guard is needed.).
     *
     * @param class-string $entityClass
     * @param list<string> $ids
     *
     * @return array<string, true>
     */
    private function idSetFromColumn(string $entityClass, string $column, array $ids): array
    {
        /** @var list<array<string, string>> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('e.' . $column)
            ->from($entityClass, 'e')
            ->where('e.' . $column . ' IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getScalarResult();

        $set = [];
        foreach ($rows as $row) {
            $set[$row[$column]] = true;
        }

        return $set;
    }
}
