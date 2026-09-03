<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\SharedTrainingBlockResource;
use App\Dto\SharedTrainingBlockInput;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Service\SoloReservationBudget;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * P2-51 — écriture d'un bloc de mutualisation. Un bloc = un parent {@see SharedTrainingBlock}
 * (avec SES ``commonSessions``) + N lignes {@see SharedTrainingBlockTeam} (club/saison/plan
 * dénormalisés). Les invariants de FORME (2..10 équipes, doublon DANS le bloc) vivent dans le
 * DTO ; les 422 MÉTIER portés ici :
 *  - une équipe inconnue du club+saison, ou une ancre de plan inexistante ;
 *  - une équipe INACTIVE (une équipe qui ne s'entraîne pas ne se mutualise pas) ;
 *  - la GARDE CENTRALE : pour chaque équipe, Σ des ``commonSessions`` de ses blocs de MÊME
 *    portée (le bloc écrit compris) > ses séances/semaine EFFECTIVES
 *    ({@see EffectiveTeamSessions}, override de période inclus) ;
 *  - un bloc portant EXACTEMENT le même ensemble d'équipes existe déjà pour cette portée
 *    (deux blocs identiques sèmeraient la confusion).
 *
 * La multi-appartenance ENTRE blocs est PERMISE (décision fondateur 2026-08-31) : on ne porte
 * donc PAS le refus « équipe déjà dans un autre groupe » du modèle historique — c'est la garde Σ
 * qui borne le cumul, pas l'unicité.
 *
 * @extends AbstractStateProcessor<SharedTrainingBlock, SharedTrainingBlockInput, SharedTrainingBlockResource>
 */
class SharedTrainingBlockStateProcessor extends AbstractStateProcessor
{
    use AssertsSchedulePlanExistsTrait;

    private SoloReservationBudget $soloReservationBudget;

    #[Required]
    public function setSoloReservationBudget(SoloReservationBudget $soloReservationBudget): void
    {
        $this->soloReservationBudget = $soloReservationBudget;
    }

    protected function getEntityClass(): string
    {
        return SharedTrainingBlock::class;
    }

    /**
     * @param SharedTrainingBlockInput $input
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        return $this->entityManager->wrapInTransaction(function () use ($input, $clubId, $seasonId): object {
            $resolvedSeasonId = $this->resolveSeasonId($clubId, $seasonId);
            $planId = $input->schedulePlanId;

            if (null !== $planId) {
                $this->assertSchedulePlanExists($this->entityManager, $planId);
            }
            $this->assertBlockValid($clubId, $resolvedSeasonId, $planId, $input->teamIds, (int) $input->commonSessions, null);

            $block = new SharedTrainingBlock;
            if (null !== $clubId) {
                $block->setClubId($clubId);
            }
            if (null !== $resolvedSeasonId) {
                $block->setSeasonId($resolvedSeasonId);
            }
            $block->setSchedulePlanId($planId);
            $block->setCommonSessions((int) $input->commonSessions);
            $this->entityManager->persist($block);

            $this->addMembers($block, $input->teamIds, $clubId, $resolvedSeasonId, $planId);

            $this->entityManager->flush();

            return $this->mapEntityToOutput($block);
        });
    }

    /**
     * Un bloc = parent + N lignes membres : la création passe par {@see processPost} (qui écrit
     * les deux atomiquement), jamais par le template `createEntityFromInput`/persist du parent.
     * Cette méthode reste exigée par le contrat abstrait ; elle ne bâtit que le parent.
     *
     * @param SharedTrainingBlockInput $input
     */
    protected function createEntityFromInput(object $input): SharedTrainingBlock
    {
        $block = new SharedTrainingBlock;
        $block->setSchedulePlanId($input->schedulePlanId);
        $block->setCommonSessions((int) $input->commonSessions);

        return $block;
    }

    /**
     * @param SharedTrainingBlock      $entity
     * @param SharedTrainingBlockInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // Le plan est fixé à la création : un PUT ne le remappe pas (identité de la ligne).
        $planId = $entity->getSchedulePlanId();
        $clubId = $entity->getClubId();
        $seasonId = $entity->getSeasonId();

        $this->assertBlockValid($clubId, $seasonId, $planId, $input->teamIds, (int) $input->commonSessions, $entity->getId());

        $entity->setCommonSessions((int) $input->commonSessions);
        $entity->touch();
        $this->syncMembership($entity, $input->teamIds, $clubId, $seasonId, $planId);
    }

    /**
     * @param SharedTrainingBlock $entity
     */
    protected function cascadeBeforeDelete(object $entity): void
    {
        // Pas de cascade ORM : on purge les lignes membres à la main avant la suppression du
        // parent. PR-1 : le bloc n'a AUCUNE réservation/placement (PR-3/4) — rien d'autre à
        // dénouer ici, contrairement au groupe qui purge ses réservations de lot.
        foreach ($this->membershipRows($entity->getId()) as $row) {
            $this->entityManager->remove($row);
        }
    }

    /**
     * @param SharedTrainingBlock $entity
     */
    protected function mapEntityToOutput(object $entity): SharedTrainingBlockResource
    {
        $teamIds = array_map(
            static fn (SharedTrainingBlockTeam $row): string => $row->getTeamId(),
            $this->membershipRows($entity->getId()),
        );
        sort($teamIds);

        return SharedTrainingBlockResource::fromEntity($entity, $teamIds);
    }

    /**
     * @param list<string> $teamIds
     */
    private function assertBlockValid(?string $clubId, ?string $seasonId, ?string $planId, array $teamIds, int $commonSessions, ?string $excludeBlockId): void
    {
        if (null === $clubId || null === $seasonId) {
            return; // contexte sans tenant (non-HTTP) : rien à vérifier contre la base
        }

        $teamRepo = $this->entityManager->getRepository(Team::class);
        foreach ($teamIds as $teamId) {
            $team = $teamRepo->findOneBy(['id' => $teamId, 'clubId' => $clubId, 'seasonId' => $seasonId]);
            if (!$team instanceof Team) {
                $this->refuse('Une équipe du bloc est inconnue de cette saison.');
            }
            if (!$team->getIsActive()) {
                $this->refuse('Une équipe du bloc est inactive et ne peut pas être mutualisée.');
            }
        }

        // État POST-changement de la portée : le jeu de blocs réel avec CE bloc substitué
        // ({@see SoloReservationBudget}, MAISON UNIQUE de B(T) et R(T) — P2-60).
        $budgets = $this->soloReservationBudget->forTeamsWithBlockSubstituted($teamIds, $clubId, $seasonId, $planId, $excludeBlockId, $commonSessions);

        // GARDE CENTRALE — Σ des séances communes des blocs de l'équipe (celui-ci compris) ≤ ses
        // séances EFFECTIVES (B(T) ≤ S(T)). L'override de PÉRIODE peut RÉDUIRE S : le budget le suit,
        // sinon on laisserait passer un cumul que le solveur refusera.
        foreach ($budgets as $budget) {
            if ($budget->block > $budget->effective) {
                $this->refuse(\sprintf('Le total des séances communes des blocs d\'une équipe (%d) dépasse son nombre de séances hebdomadaires (%d).', $budget->block, $budget->effective));
            }
        }

        // P2-60 — le nouveau B(T) ne doit pas faire passer des réservations INDIVIDUELLES
        // EXISTANTES au-dessus du résidu (sinon l'infaisabilité entrerait par l'autre porte).
        $overflow = [];
        foreach ($budgets as $budget) {
            if ($budget->individualUsed > $budget->residual) {
                $overflow[] = $this->teamName($budget->teamId, $clubId, $seasonId);
            }
        }
        if ([] !== $overflow) {
            sort($overflow);
            $this->refuse(\sprintf('Cette mutualisation ferait passer des créneaux individuels existants au-dessus du résidu autorisé pour : %s. Retirez ces réservations individuelles avant de mutualiser.', implode(', ', $overflow)));
        }

        // Deux blocs au MÊME ensemble d'équipes dans la même portée sèmeraient la confusion : 422.
        if ($this->sameTeamSetAlreadyDeclared($clubId, $seasonId, $planId, $teamIds, $excludeBlockId)) {
            $this->refuse('Un bloc de mutualisation portant exactement ces équipes existe déjà pour cette portée.');
        }
    }

    private function teamName(string $teamId, string $clubId, string $seasonId): string
    {
        $team = $this->entityManager->getRepository(Team::class)->findOneBy(['id' => $teamId, 'clubId' => $clubId, 'seasonId' => $seasonId]);

        return $team instanceof Team ? $team->getName() : 'une équipe';
    }

    /**
     * Existe-t-il déjà, dans la même portée, un bloc dont l'ensemble d'équipes est EXACTEMENT
     * celui demandé ? (Le bloc en cours d'édition est exclu.).
     *
     * @param list<string> $teamIds
     */
    private function sameTeamSetAlreadyDeclared(string $clubId, string $seasonId, ?string $planId, array $teamIds, ?string $excludeBlockId): bool
    {
        $wanted = $teamIds;
        sort($wanted);

        $qb = $this->entityManager->getRepository(SharedTrainingBlockTeam::class)->createQueryBuilder('t')
            ->select('t.blockId AS blockId', 't.teamId AS teamId')
            ->where('t.clubId = :clubId')
            ->andWhere('t.seasonId = :seasonId')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId);

        if (null === $planId) {
            $qb->andWhere('t.schedulePlanId IS NULL');
        } else {
            $qb->andWhere('t.schedulePlanId = :planId')->setParameter('planId', $planId);
        }

        if (null !== $excludeBlockId) {
            $qb->andWhere('t.blockId <> :excludeBlockId')->setParameter('excludeBlockId', $excludeBlockId);
        }

        /** @var list<array{blockId: string, teamId: string}> $rows */
        $rows = $qb->getQuery()->getScalarResult();

        $byBlock = [];
        foreach ($rows as $row) {
            $byBlock[$row['blockId']][] = $row['teamId'];
        }

        foreach ($byBlock as $teams) {
            sort($teams);
            if ($teams === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $teamIds
     */
    private function addMembers(SharedTrainingBlock $block, array $teamIds, ?string $clubId, ?string $seasonId, ?string $planId): void
    {
        foreach ($teamIds as $teamId) {
            $member = new SharedTrainingBlockTeam;
            if (null !== $clubId) {
                $member->setClubId($clubId);
            }
            if (null !== $seasonId) {
                $member->setSeasonId($seasonId);
            }
            $member->setSchedulePlanId($planId);
            $member->setBlockId($block->getId());
            $member->setTeamId($teamId);
            $this->entityManager->persist($member);
        }
    }

    /**
     * Réconcilie la composition : retire les équipes parties, ajoute les nouvelles. On ne
     * supprime-recrée PAS les inchangées (l'unicité `(block_id, team_id)` casserait sur un
     * delete+insert de même clé dans le même flush).
     *
     * @param list<string> $teamIds
     */
    private function syncMembership(SharedTrainingBlock $block, array $teamIds, ?string $clubId, ?string $seasonId, ?string $planId): void
    {
        $existing = $this->membershipRows($block->getId());
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
            $member = new SharedTrainingBlockTeam;
            if (null !== $clubId) {
                $member->setClubId($clubId);
            }
            if (null !== $seasonId) {
                $member->setSeasonId($seasonId);
            }
            $member->setSchedulePlanId($planId);
            $member->setBlockId($block->getId());
            $member->setTeamId($teamId);
            $this->entityManager->persist($member);
        }
    }

    /**
     * @return list<SharedTrainingBlockTeam>
     */
    private function membershipRows(string $blockId): array
    {
        return $this->entityManager->getRepository(SharedTrainingBlockTeam::class)
            ->findBy(['blockId' => $blockId], ['teamId' => 'ASC']);
    }
}
