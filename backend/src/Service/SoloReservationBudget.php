<?php

declare(strict_types=1);

namespace App\Service;

use App\ApiResource\TeamSoloBudgetResource;
use App\Entity\Reservation;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\State\Processor\SharedTrainingBlockStateProcessor;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P2-60 — MAISON UNIQUE du résidu solo R(T) = S(T) − B(T) et du décompte des réservations
 * INDIVIDUELLES d'une équipe, par portée (socle `schedulePlanId = null` OU plan de période, jamais
 * d'union — ADR-0002). Trois consommateurs en dépendent, et trois définitions qui divergeraient
 * laisseraient passer, ici ou là, un état que le solveur refuserait ensuite loin de sa cause :
 *
 *  - la garde de RÉSERVATION individuelle ({@see ReservationGroupOccupancy}, porte 1) ;
 *  - la garde de DÉCLARATION/ÉDITION de bloc ({@see SharedTrainingBlockStateProcessor},
 *    porte 2) — via la variante hypothétique {@see forTeamsWithBlockSubstituted}, qui remplace la
 *    garde Σ≤S historique ;
 *  - la LECTURE ({@see TeamSoloBudgetResource}) pour que le front n'expose que ce
 *    qui reste réservable.
 *
 * B(T) et « case bloc-complète » suivent {@see ReservationGroupOccupancy::reservationsOnGroupCompleteCases}
 * (statique, pure) : une réservation posée VIA un bloc (case dont le jeu réservé est EXACTEMENT les
 * membres d'un bloc) n'est jamais comptée comme individuelle. La multi-appartenance est gérée par
 * union sur les blocs de T, sans double compte (ensemble d'ids de réservations).
 */
final class SoloReservationBudget
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EffectiveTeamSessions $effectiveTeamSessions,
    ) {}

    /**
     * Le budget d'UNE équipe sur la portée. `null` si l'équipe est inconnue de ce club+saison :
     * il n'y a alors aucun résidu à opposer (défense de contrat — le rail unitaire n'applique pas
     * sa garde solo dans ce cas, comme {@see ReservationGroupOccupancy} pour ses autres règles).
     */
    public function forTeam(string $teamId, string $clubId, string $seasonId, ?string $planId): ?SoloBudget
    {
        $team = $this->entityManager->getRepository(Team::class)
            ->findOneBy(['id' => $teamId, 'clubId' => $clubId, 'seasonId' => $seasonId]);
        if (!$team instanceof Team) {
            return null;
        }

        return $this->budgetFor(
            $team,
            $planId,
            $this->blocksInScope($clubId, $seasonId, $planId),
            $this->reservationsInScope($clubId, $seasonId, $planId),
        );
    }

    /**
     * Les budgets de TOUTES les équipes du club+saison sur la portée (la lecture par portée).
     *
     * @return list<SoloBudget>
     */
    public function forScope(string $clubId, string $seasonId, ?string $planId): array
    {
        $blocks = $this->blocksInScope($clubId, $seasonId, $planId);
        $reservations = $this->reservationsInScope($clubId, $seasonId, $planId);

        /** @var list<Team> $teams */
        $teams = $this->entityManager->getRepository(Team::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]);

        return array_map(fn (Team $team): SoloBudget => $this->budgetFor($team, $planId, $blocks, $reservations), $teams);
    }

    /**
     * Variante HYPOTHÉTIQUE (porte 2) : les budgets des équipes de `$newMemberIds` évalués sur
     * l'état POST-changement de la portée — le jeu de blocs réel avec le bloc édité RETIRÉ
     * (`$excludeBlockId`, pour un PUT) et un bloc virtuel AJOUTÉ (`$newMemberIds`,
     * `$newCommonSessions`). Sert à refuser un bloc qui ferait déborder des réservations
     * individuelles EXISTANTES, AVANT de l'écrire.
     *
     * @param list<string> $newMemberIds
     *
     * @return list<SoloBudget> un budget par équipe de `$newMemberIds` connue du club+saison
     */
    public function forTeamsWithBlockSubstituted(
        array $newMemberIds,
        string $clubId,
        string $seasonId,
        ?string $planId,
        ?string $excludeBlockId,
        int $newCommonSessions,
    ): array {
        $blocks = $this->blocksInScope($clubId, $seasonId, $planId);
        if (null !== $excludeBlockId) {
            $blocks = array_values(array_filter($blocks, static fn (array $block): bool => $block['blockId'] !== $excludeBlockId));
        }
        $blocks[] = ['blockId' => '', 'commonSessions' => $newCommonSessions, 'members' => array_fill_keys($newMemberIds, true)];

        $reservations = $this->reservationsInScope($clubId, $seasonId, $planId);
        $teamRepository = $this->entityManager->getRepository(Team::class);

        $budgets = [];
        foreach ($newMemberIds as $teamId) {
            $team = $teamRepository->findOneBy(['id' => $teamId, 'clubId' => $clubId, 'seasonId' => $seasonId]);
            if (!$team instanceof Team) {
                continue; // l'appelant a déjà refusé une équipe inconnue ; défense de contrat.
            }
            $budgets[] = $this->budgetFor($team, $planId, $blocks, $reservations);
        }

        return $budgets;
    }

    /**
     * @param list<array{blockId: string, commonSessions: int, members: array<string, true>}> $blocks
     * @param list<Reservation>                                                               $reservations
     */
    private function budgetFor(Team $team, ?string $planId, array $blocks, array $reservations): SoloBudget
    {
        $teamId = $team->getId();
        $effective = $this->effectiveTeamSessions->perWeek($team, $planId);

        $block = 0;
        $inBlock = false;
        $blockCompleteReservationIdsOfTeam = [];
        foreach ($blocks as $blockRow) {
            if (!isset($blockRow['members'][$teamId])) {
                continue;
            }
            $inBlock = true;
            $block += $blockRow['commonSessions'];
            foreach (ReservationGroupOccupancy::reservationsOnGroupCompleteCases($reservations, $blockRow['members']) as $reservation) {
                if ($reservation->getTeamId() === $teamId) {
                    $blockCompleteReservationIdsOfTeam[$reservation->getId()] = true;
                }
            }
        }

        $reservationsOfTeam = 0;
        foreach ($reservations as $reservation) {
            if ($reservation->getTeamId() === $teamId) {
                ++$reservationsOfTeam;
            }
        }

        $individualUsed = $reservationsOfTeam - \count($blockCompleteReservationIdsOfTeam);
        $residual = max(0, $effective - $block);

        return new SoloBudget($teamId, $effective, $block, $residual, $individualUsed, $inBlock);
    }

    /**
     * Les blocs de la portée + leur jeu de membres. Club + saison explicites (le filtre tenant peut
     * être inactif — seed, contexte non-HTTP) ; la portée sépare socle et période.
     *
     * @return list<array{blockId: string, commonSessions: int, members: array<string, true>}>
     */
    private function blocksInScope(string $clubId, string $seasonId, ?string $planId): array
    {
        $qb = $this->entityManager->getRepository(SharedTrainingBlock::class)->createQueryBuilder('b')
            ->where('b.clubId = :clubId')
            ->andWhere('b.seasonId = :seasonId')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId);
        if (null === $planId) {
            $qb->andWhere('b.schedulePlanId IS NULL');
        } else {
            $qb->andWhere('b.schedulePlanId = :planId')->setParameter('planId', $planId);
        }

        /** @var list<SharedTrainingBlock> $blocks */
        $blocks = $qb->getQuery()->getResult();

        $memberRepository = $this->entityManager->getRepository(SharedTrainingBlockTeam::class);
        $result = [];
        foreach ($blocks as $block) {
            $members = [];
            foreach ($memberRepository->findBy(['blockId' => $block->getId()]) as $member) {
                $members[$member->getTeamId()] = true;
            }
            $result[] = ['blockId' => $block->getId(), 'commonSessions' => $block->getCommonSessions(), 'members' => $members];
        }

        return $result;
    }

    /**
     * Toutes les réservations de la portée (club + saison explicites ; socle vs période).
     *
     * @return list<Reservation>
     */
    private function reservationsInScope(string $clubId, string $seasonId, ?string $planId): array
    {
        $qb = $this->entityManager->getRepository(Reservation::class)->createQueryBuilder('r')
            ->where('r.clubId = :clubId')
            ->andWhere('r.seasonId = :seasonId')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId);
        if (null === $planId) {
            $qb->andWhere('r.schedulePlanId IS NULL');
        } else {
            $qb->andWhere('r.schedulePlanId = :planId')->setParameter('planId', $planId);
        }

        /** @var list<Reservation> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }
}
