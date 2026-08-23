<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\SharedTrainingGroupResource;
use App\Dto\SharedTrainingGroupInput;
use App\Entity\SharedTrainingGroup;
use App\Entity\SharedTrainingGroupTeam;
use App\Entity\Team;
use App\Service\EffectiveTeamSessions;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * P2-27 — écriture d'une déclaration de mutualisation. Un groupe = un parent
 * {@see SharedTrainingGroup} + N lignes {@see SharedTrainingGroupTeam} (club/saison/plan
 * dénormalisés). Les 422 métier (les invariants de FORME — 2..10 équipes, doublon — vivent
 * dans le DTO) :
 *  - une équipe déjà dans un AUTRE groupe DU MÊME PLAN ;
 *  - K > le plus petit ``sessionsPerWeek`` effectif des membres (override de période inclus) ;
 *  - une équipe inconnue du club+saison, ou une ancre de plan inexistante.
 *
 * @extends AbstractStateProcessor<SharedTrainingGroup, SharedTrainingGroupInput, SharedTrainingGroupResource>
 */
class SharedTrainingGroupStateProcessor extends AbstractStateProcessor
{
    use AssertsSchedulePlanExistsTrait;

    private EffectiveTeamSessions $effectiveTeamSessions;

    #[Required]
    public function setEffectiveTeamSessions(EffectiveTeamSessions $effectiveTeamSessions): void
    {
        $this->effectiveTeamSessions = $effectiveTeamSessions;
    }

    protected function getEntityClass(): string
    {
        return SharedTrainingGroup::class;
    }

    /**
     * @param SharedTrainingGroupInput $input
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        return $this->entityManager->wrapInTransaction(function () use ($input, $clubId, $seasonId): object {
            $resolvedSeasonId = $this->resolveSeasonId($clubId, $seasonId);
            $planId = $input->schedulePlanId;

            if (null !== $planId) {
                $this->assertSchedulePlanExists($this->entityManager, $planId);
            }
            $this->assertDeclarationValid($clubId, $resolvedSeasonId, $planId, $input->teamIds, (int) $input->commonSessions, null);

            $group = new SharedTrainingGroup;
            if (null !== $clubId) {
                $group->setClubId($clubId);
            }
            if (null !== $resolvedSeasonId) {
                $group->setSeasonId($resolvedSeasonId);
            }
            $group->setSchedulePlanId($planId);
            $group->setCommonSessions((int) $input->commonSessions);
            $this->entityManager->persist($group);

            $this->addMembers($group, $input->teamIds, $clubId, $resolvedSeasonId, $planId);

            $this->entityManager->flush();

            return $this->mapEntityToOutput($group);
        });
    }

    /**
     * Un groupe = parent + N lignes membres : la création passe par {@see processPost} (qui
     * écrit les deux atomiquement), jamais par le template `createEntityFromInput`/persist du
     * parent. Cette méthode reste exigée par le contrat abstrait ; elle ne bâtit que le parent.
     *
     * @param SharedTrainingGroupInput $input
     */
    protected function createEntityFromInput(object $input): SharedTrainingGroup
    {
        $group = new SharedTrainingGroup;
        $group->setSchedulePlanId($input->schedulePlanId);
        $group->setCommonSessions((int) $input->commonSessions);

        return $group;
    }

    /**
     * @param SharedTrainingGroup      $entity
     * @param SharedTrainingGroupInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // Le plan est fixé à la création : un PUT ne le remappe pas (identité de la ligne).
        $planId = $entity->getSchedulePlanId();
        $clubId = $entity->getClubId();
        $seasonId = $entity->getSeasonId();

        $this->assertDeclarationValid($clubId, $seasonId, $planId, $input->teamIds, (int) $input->commonSessions, $entity->getId());

        $entity->setCommonSessions((int) $input->commonSessions);
        $entity->touch();
        $this->syncMembership($entity, $input->teamIds, $clubId, $seasonId, $planId);
    }

    /**
     * @param SharedTrainingGroup $entity
     */
    protected function cascadeBeforeDelete(object $entity): void
    {
        // Pas de cascade ORM : on purge les lignes membres à la main avant la suppression du parent.
        foreach ($this->membershipRows($entity->getId()) as $row) {
            $this->entityManager->remove($row);
        }
    }

    /**
     * @param SharedTrainingGroup $entity
     */
    protected function mapEntityToOutput(object $entity): SharedTrainingGroupResource
    {
        $teamIds = array_map(
            static fn (SharedTrainingGroupTeam $row): string => $row->getTeamId(),
            $this->membershipRows($entity->getId()),
        );
        sort($teamIds);

        return SharedTrainingGroupResource::fromEntity($entity, $teamIds);
    }

    /**
     * @param list<string> $teamIds
     */
    private function assertDeclarationValid(?string $clubId, ?string $seasonId, ?string $planId, array $teamIds, int $commonSessions, ?string $excludeGroupId): void
    {
        if (null === $clubId || null === $seasonId) {
            return; // contexte sans tenant (non-HTTP) : rien à vérifier contre la base
        }

        $teamRepo = $this->entityManager->getRepository(Team::class);
        $minEffective = null;
        foreach ($teamIds as $teamId) {
            $team = $teamRepo->findOneBy(['id' => $teamId, 'clubId' => $clubId, 'seasonId' => $seasonId]);
            if (!$team instanceof Team) {
                throw new ValidationException('Une équipe du groupe est inconnue de cette saison.');
            }
            $effective = $this->effectiveSessionsPerWeek($team, $planId);
            $minEffective = null === $minEffective ? $effective : min($minEffective, $effective);
        }

        // K borné au plus petit sessionsPerWeek effectif : au-delà, la mutualisation exige plus
        // de séances communes qu'une équipe n'en a → jamais satisfiable.
        if (null !== $minEffective && $commonSessions > $minEffective) {
            throw new ValidationException(\sprintf('Le nombre de séances communes (%d) dépasse le nombre de séances d\'une des équipes du groupe (%d).', $commonSessions, $minEffective));
        }

        // Une équipe ne peut appartenir qu'à un seul groupe pour un plan donné.
        foreach ($this->teamIdsAlreadyGrouped($clubId, $seasonId, $planId, $teamIds, $excludeGroupId) as $_alreadyGrouped) {
            throw new ValidationException('Une équipe fait déjà partie d\'un autre groupe mutualisé pour cette portée.');
        }
    }

    private function effectiveSessionsPerWeek(Team $team, ?string $planId): int
    {
        // Maison unique de la notion (partagée avec la garde d'occupation) : {@see EffectiveTeamSessions}.
        return $this->effectiveTeamSessions->perWeek($team, $planId);
    }

    /**
     * @param list<string> $teamIds
     *
     * @return list<string> teamIds déjà pris dans un AUTRE groupe du même plan (portée NULL = socle)
     */
    private function teamIdsAlreadyGrouped(string $clubId, string $seasonId, ?string $planId, array $teamIds, ?string $excludeGroupId): array
    {
        if ([] === $teamIds) {
            return [];
        }

        $qb = $this->entityManager->getRepository(SharedTrainingGroupTeam::class)->createQueryBuilder('t')
            ->select('t.teamId')
            ->where('t.clubId = :clubId')
            ->andWhere('t.seasonId = :seasonId')
            ->andWhere('t.teamId IN (:teamIds)')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->setParameter('teamIds', $teamIds);

        // Même portée de plan : NULL (socle) et un UUID (période) sont deux mondes distincts.
        if (null === $planId) {
            $qb->andWhere('t.schedulePlanId IS NULL');
        } else {
            $qb->andWhere('t.schedulePlanId = :planId')->setParameter('planId', $planId);
        }

        if (null !== $excludeGroupId) {
            $qb->andWhere('t.groupId <> :excludeGroupId')->setParameter('excludeGroupId', $excludeGroupId);
        }

        /** @var list<array{teamId: string}> $rows */
        $rows = $qb->getQuery()->getScalarResult();

        return array_map(static fn (array $row): string => $row['teamId'], $rows);
    }

    /**
     * @param list<string> $teamIds
     */
    private function addMembers(SharedTrainingGroup $group, array $teamIds, ?string $clubId, ?string $seasonId, ?string $planId): void
    {
        foreach ($teamIds as $teamId) {
            $member = new SharedTrainingGroupTeam;
            if (null !== $clubId) {
                $member->setClubId($clubId);
            }
            if (null !== $seasonId) {
                $member->setSeasonId($seasonId);
            }
            $member->setSchedulePlanId($planId);
            $member->setGroupId($group->getId());
            $member->setTeamId($teamId);
            $this->entityManager->persist($member);
        }
    }

    /**
     * Réconcilie la composition : retire les équipes parties, ajoute les nouvelles. On ne
     * supprime-recrée PAS les inchangées (l'unicité `(group_id, team_id)` casserait sur un
     * delete+insert de même clé dans le même flush).
     *
     * @param list<string> $teamIds
     */
    private function syncMembership(SharedTrainingGroup $group, array $teamIds, ?string $clubId, ?string $seasonId, ?string $planId): void
    {
        $existing = $this->membershipRows($group->getId());
        $wanted = array_fill_keys($teamIds, true);

        $keep = [];
        foreach ($existing as $row) {
            if (isset($wanted[$row->getTeamId()])) {
                $keep[$row->getTeamId()] = true;

                continue;
            }
            $this->entityManager->remove($row);
        }

        foreach ($teamIds as $teamId) {
            if (isset($keep[$teamId])) {
                continue;
            }
            $member = new SharedTrainingGroupTeam;
            if (null !== $clubId) {
                $member->setClubId($clubId);
            }
            if (null !== $seasonId) {
                $member->setSeasonId($seasonId);
            }
            $member->setSchedulePlanId($planId);
            $member->setGroupId($group->getId());
            $member->setTeamId($teamId);
            $this->entityManager->persist($member);
        }
    }

    /**
     * @return list<SharedTrainingGroupTeam>
     */
    private function membershipRows(string $groupId): array
    {
        return $this->entityManager->getRepository(SharedTrainingGroupTeam::class)
            ->findBy(['groupId' => $groupId], ['teamId' => 'ASC']);
    }
}
