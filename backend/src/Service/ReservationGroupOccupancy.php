<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Reservation;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * P2-46 PR-2 — MAISON UNIQUE de la règle d'occupation exclusive des créneaux de réservation.
 *
 * Une réservation de GROUPE s'éclate en N verrous HARD sur UNE case ; le moteur lit une case
 * entièrement verrouillée par un bloc déclaré comme UNE séance commune CERTAINE
 * (`add_shared_block_constraints`). Cinq règles gardent cette sémantique à l'ÉCRITURE,
 * là où le gestionnaire peut encore comprendre son geste — plutôt qu'en 422-FAILED du solve, loin
 * de sa cause (`engine/tests/semantic/test_shared_block_semantics.py:170-179`) :
 *
 *  (a) EXCLUSIVITÉ    — une réservation de bloc exige un créneau VIDE de toute autre réservation
 *                       de la même portée ;
 *  (b) RÉCIPROQUE     — une réservation INDIVIDUELLE est refusée si la case porte déjà un bloc
 *                       COMPLET (dérivé : l'ensemble des équipes réservées == les membres d'un
 *                       {@see SharedTrainingBlock} de la même portée — zéro marqueur stocké) ;
 *  (c) PLAFOND        — après écriture, le nombre de cases « bloc-complètes » d'un bloc reste
 *                       ≤ `commonSessions` (au-delà, `Σ b == commonSessions` est contradictoire → INFEASIBLE) ;
 *  (d) PLAFOND MEMBRE — chaque membre reste sous son `sessionsPerWeek` EFFECTIF
 *                       ({@see EffectiveTeamSessions}, override de période inclus) ;
 *  (e) CAPACITÉ       — une réservation individuelle ne dépasse pas la capacité du créneau, même
 *                       sémantique que le front (`capacity` sur gymnase divisible, sinon 1) — un
 *                       bloc complet compte pour UN occupant (cohérent avec l'exemption moteur) ;
 *  (f) BUDGET SOLO    — (P2-60) une réservation individuelle ne dépasse pas le résidu solo de
 *                       l'équipe R(T) = S(T) − B(T) ({@see SoloReservationBudget}). Réserver une
 *                       équipe dont TOUTES les séances viennent d'un bloc est un geste sans objet
 *                       que le moteur rendrait INFEASIBLE — on l'interdit à la source. Une
 *                       réservation qui COMPLÈTE une case bloc n'est pas individuelle (exemptée).
 *
 * La PORTÉE (socle `null` vs plan de période) borne toutes les lectures : deux mondes distincts,
 * jamais d'union (le filtre tenant Doctrine ajoute le club + la saison courants).
 *
 * P2-51 PR-7 — le {@see SharedTrainingBlock} est la SEULE notion de mutualisation (le modèle groupe
 * K est retiré) : le rail batch et le rail unitaire ne connaissent plus que le bloc.
 */
final class ReservationGroupOccupancy
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EffectiveTeamSessions $effectiveTeamSessions,
        private readonly SoloReservationBudget $soloReservationBudget,
    ) {}

    /**
     * MAISON UNIQUE de la dérivation « case complète » : les réservations posées sur les cases
     * (gymnase, jour, heure HH:MM) dont l'ensemble des équipes réservées est EXACTEMENT le jeu de
     * membres passé. Statique et pure — l'appelant borne d'abord les réservations à UNE portée (le
     * filtre tenant à l'écriture). Sert le plafond (c) du rail batch de bloc.
     *
     * @param list<Reservation>   $reservations réservations d'UNE portée déjà bornée par l'appelant
     * @param array<string, true> $memberSet    le jeu de membres du bloc
     *
     * @return list<Reservation>
     */
    public static function reservationsOnGroupCompleteCases(array $reservations, array $memberSet): array
    {
        if ([] === $memberSet) {
            return [];
        }

        $byCase = [];
        foreach ($reservations as $reservation) {
            $key = $reservation->getVenueId() . '|' . $reservation->getDayOfWeek() . '|' . $reservation->getStartTime()->format('H:i');
            $byCase[$key][] = $reservation;
        }

        $result = [];
        foreach ($byCase as $caseReservations) {
            $teamSet = [];
            foreach ($caseReservations as $reservation) {
                $teamSet[$reservation->getTeamId()] = true;
            }
            if (self::setsEqual($teamSet, $memberSet)) {
                foreach ($caseReservations as $reservation) {
                    $result[] = $reservation;
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string, true> $a
     * @param array<string, true> $b
     */
    private static function setsEqual(array $a, array $b): bool
    {
        return \count($a) === \count($b) && [] === array_diff_key($a, $b) && [] === array_diff_key($b, $a);
    }

    /**
     * P2-51 — rail BATCH de mutualisation par BLOC. Une séance de bloc s'éclate en N verrous d'UNE
     * case et doit rester UNE occupation. Règles (a) exclusivité, (c) plafond `commonSessions`,
     * (d) plafond par membre. Les règles (b)/(e) du rail unitaire comptent en plus un BLOC complet
     * comme UN occupant ({@see occupantCount}, {@see reservedSetMatchesABlock}).
     *
     * ⚠ Le plafond (c) réutilise {@see groupCompleteCaseCount} — pur, indexé sur le JEU DE MEMBRES,
     * pas sur l'identité bloc : « cases dont l'ensemble réservé est EXACTEMENT ces membres ».
     *
     * @param list<string> $memberTeamIds
     */
    public function assertBlockReservationAllowed(
        SharedTrainingBlock $block,
        array $memberTeamIds,
        string $venueId,
        int $dayOfWeek,
        DateTimeImmutable $startTime,
        ?string $schedulePlanId,
    ): void {
        // (a) EXCLUSIVITÉ — le créneau visé doit être VIDE de toute autre réservation.
        if ([] !== $this->reservationsOnCase($venueId, $dayOfWeek, $startTime, $schedulePlanId)) {
            throw new UnprocessableEntityHttpException('Ce créneau est déjà occupé par d\'autres réservations : un entraînement mutualisé demande un créneau entièrement libre. Choisissez un créneau vide, ou retirez d\'abord les réservations en place.');
        }

        // (c) PLAFOND — la case visée est vide (règle a), elle deviendra « bloc-complète » : les
        // cases déjà complètes + celle-ci ne doivent pas dépasser le nombre de séances communes.
        $memberSet = array_fill_keys($memberTeamIds, true);
        if ($this->groupCompleteCaseCount($memberSet, $schedulePlanId) + 1 > $block->getCommonSessions()) {
            throw new UnprocessableEntityHttpException(\sprintf('Cette mutualisation a déjà ses %d séance(s) commune(s) placée(s) : vous ne pouvez pas en réserver davantage. Retirez une séance mutualisée avant d\'en ajouter une autre.', $block->getCommonSessions()));
        }

        // (d) PLAFOND MEMBRE — la séance ajoute une réservation à CHAQUE membre : aucun ne doit
        // franchir son nombre de séances hebdomadaires effectif.
        $teamRepository = $this->entityManager->getRepository(Team::class);
        foreach ($memberTeamIds as $teamId) {
            $team = $teamRepository->findOneBy(['id' => $teamId]);
            if (!$team instanceof Team) {
                continue; // le contrôleur a résolu les membres sous le tenant ; défense de contrat.
            }
            $ceiling = $this->effectiveTeamSessions->perWeek($team, $schedulePlanId);
            if ($this->teamReservationCount($teamId, $schedulePlanId) + 1 > $ceiling) {
                throw new UnprocessableEntityHttpException(\sprintf('L\'équipe « %s » atteint déjà son nombre de séances par semaine (%d) : cette séance mutualisée le dépasserait. Réduisez ses autres réservations, ou augmentez son nombre de séances.', $team->getName(), $ceiling));
            }
        }
    }

    /**
     * Rail UNITAIRE : règles (b) réciproque, (e) capacité, (f) budget solo (P2-60).
     *
     * `$clubId`/`$seasonId` bornent le budget solo (règle f) ; `null` (contexte non-HTTP) la
     * neutralise, faute de portée club+saison à opposer.
     */
    public function assertIndividualReservationAllowed(
        string $teamId,
        string $venueId,
        int $dayOfWeek,
        DateTimeImmutable $startTime,
        ?string $schedulePlanId,
        ?string $clubId = null,
        ?string $seasonId = null,
    ): void {
        $reserved = $this->reservedTeamSetOnCase($venueId, $dayOfWeek, $startTime, $schedulePlanId);

        // (b) RÉCIPROQUE — la case porte-t-elle DÉJÀ un BLOC complet ? (dérivé, pas stocké :
        // l'ensemble réservé est EXACTEMENT le jeu de membres d'un bloc de la même portée). Un bloc
        // réservé occupe la case en entier.
        if ($this->reservedSetMatchesABlock($reserved, $schedulePlanId)) {
            throw new UnprocessableEntityHttpException('Ce créneau est réservé à un entraînement mutualisé — le bloc l\'occupe en entier : aucune autre équipe ne peut s\'y ajouter. Choisissez un autre créneau.');
        }

        // (e) CAPACITÉ — le nombre d'occupants APRÈS ajout ne dépasse pas la capacité effective du
        // créneau (un bloc complet compte pour UN occupant).
        $finalSet = $reserved;
        $finalSet[$teamId] = true;
        $capacity = $this->effectiveSlotCapacity($venueId, $dayOfWeek, $startTime, $schedulePlanId);
        if ($this->occupantCount($finalSet, $schedulePlanId) > $capacity) {
            throw new UnprocessableEntityHttpException(\sprintf('Ce créneau est déjà plein (capacité %d) : aucune équipe supplémentaire ne peut y être réservée. Choisissez un autre créneau, ou activez le partage de ce gymnase.', $capacity));
        }

        $this->assertSoloBudgetAllows($teamId, $finalSet, $schedulePlanId, $clubId, $seasonId);
    }

    /**
     * P2-62 — DÉCISION FONDATEUR « on ne retire pas une équipe d'un groupe, on supprime le groupe ».
     * Les réservations SŒURS à emporter avec `$reservation` : si sa case (gymnase, jour, heure HH:MM)
     * est « bloc-complète » pour un bloc de la portée dont son équipe est membre — l'ensemble réservé
     * y est EXACTEMENT le jeu de membres ({@see reservationsOnGroupCompleteCases}, MAISON UNIQUE) —
     * on rend TOUTES les réservations de cette case (les membres du bloc), `$reservation` comprise ;
     * sinon `[]` : la réservation est INDIVIDUELLE, elle se supprime seule.
     *
     * La portée du plan de `$reservation` (socle `null` vs période) borne toutes les lectures — le
     * filtre tenant Doctrine ajoute le club + la saison courants, comme {@see assertIndividualReservationAllowed}.
     *
     * @return list<Reservation>
     */
    public function blockCompleteCaseSiblings(Reservation $reservation): array
    {
        $schedulePlanId = $reservation->getSchedulePlanId();
        $caseKey = $this->caseKey($reservation);
        $reservationsInScope = $this->reservationsInScope($schedulePlanId);

        foreach ($this->blockMemberSetsInScope($schedulePlanId) as $memberSet) {
            if (!isset($memberSet[$reservation->getTeamId()])) {
                continue;
            }
            $siblings = [];
            foreach (self::reservationsOnGroupCompleteCases($reservationsInScope, $memberSet) as $onComplete) {
                if ($this->caseKey($onComplete) === $caseKey) {
                    $siblings[] = $onComplete;
                }
            }
            if ([] !== $siblings) {
                return $siblings;
            }
        }

        return [];
    }

    private function caseKey(Reservation $reservation): string
    {
        return $reservation->getVenueId() . '|' . $reservation->getDayOfWeek() . '|' . $reservation->getStartTime()->format('H:i');
    }

    /**
     * (f) BUDGET SOLO (P2-60) — une réservation INDIVIDUELLE ne dépasse pas le résidu solo de
     * l'équipe R(T) = S(T) − B(T) ({@see SoloReservationBudget}, MAISON UNIQUE de R).
     *
     * ⚠ Exception : la réservation posée ne compte comme individuelle que si elle RESTE
     * individuelle APRÈS ajout. Si `$finalSet` (l'ensemble réservé une fois cette équipe ajoutée)
     * égale EXACTEMENT les membres d'un bloc, elle COMPLÈTE une case bloc (SF2 rejoint SF1 déjà
     * posée) — ce n'est pas un créneau individuel, on ne l'oppose pas au résidu.
     *
     * @param array<string, true> $finalSet
     */
    private function assertSoloBudgetAllows(string $teamId, array $finalSet, ?string $schedulePlanId, ?string $clubId, ?string $seasonId): void
    {
        if (null === $clubId || null === $seasonId) {
            return; // hors portée club+saison (non-HTTP) : rien à opposer.
        }
        if ($this->reservedSetMatchesABlock($finalSet, $schedulePlanId)) {
            return; // cette réservation COMPLÈTE une case bloc : elle n'est pas individuelle.
        }

        $budget = $this->soloReservationBudget->forTeam($teamId, $clubId, $seasonId, $schedulePlanId);
        if (!$budget instanceof SoloBudget) {
            return; // équipe inconnue de la portée : défense de contrat.
        }

        if (0 === $budget->residual && $budget->inBlock) {
            throw new UnprocessableEntityHttpException($this->soloOnlyBlockMessage($teamId, $schedulePlanId));
        }
        if ($budget->individualUsed + 1 > $budget->residual) {
            throw new UnprocessableEntityHttpException(\sprintf('L\'équipe « %s » a déjà %d créneau(x) individuel(s) sur %d possible(s) : elle ne peut pas en réserver davantage. Retirez un créneau, ou augmentez son nombre de séances.', $this->teamName($teamId), $budget->individualUsed, $budget->residual));
        }
    }

    /**
     * « SF1 s'entraîne uniquement en groupe SF1 + SF2 : réservez le groupe » — le message de la
     * règle (f) quand le résidu est nul et l'équipe membre d'un bloc. Noms d'équipes, jamais d'ids.
     */
    private function soloOnlyBlockMessage(string $teamId, ?string $schedulePlanId): string
    {
        $groups = [];
        foreach ($this->blockMemberSetsInScope($schedulePlanId) as $memberSet) {
            if (!isset($memberSet[$teamId])) {
                continue;
            }
            $names = array_values(array_filter(array_map(
                fn (string $id): string => $this->teamName($id),
                array_keys($memberSet),
            )));
            sort($names);
            if ([] !== $names) {
                $groups[] = implode(' + ', $names);
            }
        }

        if ([] === $groups) {
            return \sprintf('L\'équipe « %s » s\'entraîne uniquement en groupe : réservez le groupe plutôt qu\'un créneau individuel.', $this->teamName($teamId));
        }

        return \sprintf(
            'L\'équipe « %s » s\'entraîne uniquement en groupe (%s) : réservez le groupe plutôt qu\'un créneau individuel.',
            $this->teamName($teamId),
            implode(', ', $groups),
        );
    }

    private function teamName(string $teamId): string
    {
        $team = $this->entityManager->getRepository(Team::class)->findOneBy(['id' => $teamId]);

        return $team instanceof Team ? $team->getName() : 'Cette équipe';
    }

    /**
     * Réservations posées sur EXACTEMENT cette case (gymnase, jour, heure HH:MM) et cette portée.
     * L'heure est comparée en HH:MM (patron `slotKey` du front) : réservation et créneau peuvent
     * porter des secondes différentes pour le même créneau.
     *
     * @return list<Reservation>
     */
    private function reservationsOnCase(string $venueId, int $dayOfWeek, DateTimeImmutable $startTime, ?string $schedulePlanId): array
    {
        $qb = $this->entityManager->getRepository(Reservation::class)->createQueryBuilder('r')
            ->where('r.venueId = :venueId')
            ->andWhere('r.dayOfWeek = :dow')
            ->setParameter('venueId', $venueId)
            ->setParameter('dow', $dayOfWeek);
        $this->scopePlan($qb, 'r', $schedulePlanId);

        $hhmm = $startTime->format('H:i');
        /** @var list<Reservation> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_values(array_filter($rows, static fn (Reservation $r): bool => $r->getStartTime()->format('H:i') === $hhmm));
    }

    /**
     * L'ensemble (teamId => true) des équipes réservées sur la case.
     *
     * @return array<string, true>
     */
    private function reservedTeamSetOnCase(string $venueId, int $dayOfWeek, DateTimeImmutable $startTime, ?string $schedulePlanId): array
    {
        $set = [];
        foreach ($this->reservationsOnCase($venueId, $dayOfWeek, $startTime, $schedulePlanId) as $reservation) {
            $set[$reservation->getTeamId()] = true;
        }

        return $set;
    }

    /**
     * Combien de cases de cette portée sont « bloc-complètes » pour CE jeu de membres : l'ensemble
     * des équipes réservées sur la case est EXACTEMENT l'ensemble des membres.
     *
     * @param array<string, true> $memberSet
     */
    private function groupCompleteCaseCount(array $memberSet, ?string $schedulePlanId): int
    {
        $cases = [];
        foreach (self::reservationsOnGroupCompleteCases($this->reservationsInScope($schedulePlanId), $memberSet) as $reservation) {
            $cases[$reservation->getVenueId() . '|' . $reservation->getDayOfWeek() . '|' . $reservation->getStartTime()->format('H:i')] = true;
        }

        return \count($cases);
    }

    /**
     * Nombre d'OCCUPANTS d'une case : un bloc entièrement présent compte pour UN, chaque équipe
     * hors d'un bloc complet compte pour UNE (cohérent avec l'exemption moteur du bloc).
     *
     * @param array<string, true> $teamSet
     */
    private function occupantCount(array $teamSet, ?string $schedulePlanId): int
    {
        $accountedFor = [];
        $groupCount = 0;

        // P2-51 — un BLOC entièrement présent compte pour UN occupant. La multi-appartenance est
        // permise : on ne re-compte pas un bloc dont TOUS les membres sont déjà attribués (à un
        // autre bloc déjà folié), sinon une même case physique pèserait deux fois. Aucun bloc en
        // portée ⇒ boucle vide ⇒ occupants == équipes distinctes.
        foreach ($this->blockMemberSetsInScope($schedulePlanId) as $memberSet) {
            if ([] === $memberSet) {
                continue;
            }
            $fullyPresent = true;
            foreach (array_keys($memberSet) as $teamId) {
                if (!isset($teamSet[$teamId])) {
                    $fullyPresent = false;
                    break;
                }
            }
            if (!$fullyPresent) {
                continue;
            }
            $newlyAccounted = false;
            foreach (array_keys($memberSet) as $teamId) {
                if (!isset($accountedFor[$teamId])) {
                    $newlyAccounted = true;
                }
                $accountedFor[$teamId] = true;
            }
            if ($newlyAccounted) {
                ++$groupCount;
            }
        }

        $loners = 0;
        foreach (array_keys($teamSet) as $teamId) {
            if (!isset($accountedFor[$teamId])) {
                ++$loners;
            }
        }

        return $groupCount + $loners;
    }

    /**
     * Capacité EFFECTIVE de la case : `capacity` du créneau sur un gymnase divisible, sinon 1
     * (`effectiveSlotCapacity`, `reservationSlots.ts`). Aucun créneau correspondant → 1 (défaut sûr).
     */
    private function effectiveSlotCapacity(string $venueId, int $dayOfWeek, DateTimeImmutable $startTime, ?string $schedulePlanId): int
    {
        $venue = $this->entityManager->getRepository(Venue::class)->findOneBy(['id' => $venueId]);
        if (!$venue instanceof Venue || !$venue->getCanSplit()) {
            return 1;
        }
        $slot = $this->slotOnCase($venueId, $dayOfWeek, $startTime, $schedulePlanId);

        return $slot instanceof VenueTrainingSlot ? $slot->getCapacity() : 1;
    }

    private function slotOnCase(string $venueId, int $dayOfWeek, DateTimeImmutable $startTime, ?string $schedulePlanId): ?VenueTrainingSlot
    {
        $qb = $this->entityManager->getRepository(VenueTrainingSlot::class)->createQueryBuilder('s')
            ->where('s.venueId = :venueId')
            ->andWhere('s.dayOfWeek = :dow')
            ->setParameter('venueId', $venueId)
            ->setParameter('dow', $dayOfWeek);
        $this->scopePlan($qb, 's', $schedulePlanId);

        $hhmm = $startTime->format('H:i');
        /** @var list<VenueTrainingSlot> $slots */
        $slots = $qb->getQuery()->getResult();
        foreach ($slots as $slot) {
            if ($slot->getStartTime()->format('H:i') === $hhmm) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Toutes les réservations de la portée (le filtre tenant borne club + saison).
     *
     * @return list<Reservation>
     */
    private function reservationsInScope(?string $schedulePlanId): array
    {
        $qb = $this->entityManager->getRepository(Reservation::class)->createQueryBuilder('r');
        $this->scopePlan($qb, 'r', $schedulePlanId);
        /** @var list<Reservation> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * Les jeux de membres des BLOCS de la portée (P2-51). Le filtre tenant borne club + saison ;
     * la multi-appartenance est permise, d'où des jeux qui peuvent se recouvrir — les lecteurs
     * ({@see occupantCount}, {@see reservedSetMatchesABlock}) gèrent le recouvrement par case.
     *
     * @return list<array<string, true>>
     */
    private function blockMemberSetsInScope(?string $schedulePlanId): array
    {
        $qb = $this->entityManager->getRepository(SharedTrainingBlock::class)->createQueryBuilder('b');
        $this->scopePlan($qb, 'b', $schedulePlanId);
        /** @var list<SharedTrainingBlock> $blocks */
        $blocks = $qb->getQuery()->getResult();

        $result = [];
        $memberRepository = $this->entityManager->getRepository(SharedTrainingBlockTeam::class);
        foreach ($blocks as $block) {
            $set = [];
            foreach ($memberRepository->findBy(['blockId' => $block->getId()]) as $member) {
                $set[$member->getTeamId()] = true;
            }
            if ([] !== $set) {
                $result[] = $set;
            }
        }

        return $result;
    }

    /**
     * L'ensemble réservé sur la case est-il EXACTEMENT le jeu de membres d'un bloc de la portée ?
     * (règle b — dérivé, jamais un marqueur stocké).
     *
     * @param array<string, true> $reservedSet
     */
    private function reservedSetMatchesABlock(array $reservedSet, ?string $schedulePlanId): bool
    {
        if ([] === $reservedSet) {
            return false;
        }
        foreach ($this->blockMemberSetsInScope($schedulePlanId) as $memberSet) {
            if (self::setsEqual($memberSet, $reservedSet)) {
                return true;
            }
        }

        return false;
    }

    private function teamReservationCount(string $teamId, ?string $schedulePlanId): int
    {
        $qb = $this->entityManager->getRepository(Reservation::class)->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.teamId = :teamId')
            ->setParameter('teamId', $teamId);
        $this->scopePlan($qb, 'r', $schedulePlanId);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Borne une requête à la portée du plan : socle (`schedulePlanId IS NULL`) et période
     * (`= :planId`) sont deux mondes distincts, jamais d'union (ADR-0002).
     */
    private function scopePlan(QueryBuilder $qb, string $alias, ?string $schedulePlanId): void
    {
        if (null === $schedulePlanId) {
            $qb->andWhere($alias . '.schedulePlanId IS NULL');

            return;
        }
        $qb->andWhere($alias . '.schedulePlanId = :planId')->setParameter('planId', $schedulePlanId);
    }
}
