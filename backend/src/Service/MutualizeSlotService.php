<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\TeamPeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Exception\MutualizationRefusedException;
use App\Exception\ScheduleGenerationInProgressException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

/**
 * Lot 9 — MUTUALISER DEPUIS LA GÉNÉRATION EN GARDANT LE CRÉNEAU.
 *
 * Depuis la fiche d'une séance (la case d'une équipe SOURCE, p.ex. U11F1 lundi 18h au gymnase X),
 * le gestionnaire déclare un bloc de mutualisation ANCRÉ à cette case : un ensemble d'équipes qui
 * s'entraînent ENSEMBLE là, une seule séance commune (`commonSessions = 1`), sans quitter le
 * créneau choisi. Retouche EN PLACE d'un plan de PÉRIODE (ADR-0002 — le bloc meurt avec le plan) ;
 * pas de V+1, pas de verdict moteur.
 *
 * L'ANCRAGE que le solveur comprend déjà (aucun changement de contrat) : chaque séance des membres
 * est co-localisée à la case ET verrouillée HARD/MANUAL — `model._extract_hard_locks` lit le niveau
 * HARD, `joins_pinned_partner`/`fully_pinned_case_bvars` font tenir le bloc sur cette case à la
 * régénération comme au comblement. Le bloc `SharedTrainingBlock` (sérialisé en `sharedBlocks`)
 * dit au solveur que ces équipes forment UNE occupation. L'invariant est gardé par
 * `CrossStack/SharedBlockAnchoredCaseGateTest`.
 *
 * Ce que le geste écrit, en UNE transaction (tout-ou-rien) :
 *  - un {@see SharedTrainingBlock} de PORTÉE PLAN (`schedulePlanId` = le plan de période),
 *    `commonSessions = 1`, nom optionnel ;
 *  - la séance de la SOURCE verrouillée HARD/MANUAL sur place ;
 *  - pour chaque équipe rejoignante DÉJÀ placée : la séance qu'elle DÉSIGNE (Q1 — choix conscient)
 *    est DÉPLACÉE sur la case et verrouillée HARD/MANUAL ;
 *  - pour chaque équipe rejoignante à 0 séance dans le plan : un override de période l'ACTIVE
 *    (1 séance, `source = mutualisation`) et une séance NEUVE naît sur la case, verrouillée.
 *
 * Refus 422 NOMMÉS (rien n'est écrit) : socle (plan de période seulement), équipe inactive, équipe
 * inconnue, ensemble déjà déclaré, case déjà occupée (capacité), Σ des séances communes dépassant
 * le volume d'une équipe, équipe déjà placée sans séance désignée.
 */
final class MutualizeSlotService
{
    /** Marqueur générique posé sur l'override créé par le geste (colonne réutilisable — cf. migration). */
    public const string OVERRIDE_SOURCE = 'mutualisation';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubGenerationLock $clubGenerationLock,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly SoloReservationBudget $soloReservationBudget,
        private readonly ScheduleProgressPublisher $progressPublisher,
    ) {}

    /**
     * @param list<string> $teamIds         les membres du bloc (la SOURCE y est ajoutée si absente)
     * @param list<string> $replacedSlotIds une séance par équipe rejoignante DÉJÀ placée, à remplacer
     *
     * @throws ScheduleGenerationInProgressException une génération tourne pour ce club (→ 409)
     * @throws MutualizationRefusedException         un refus nommé (→ 422), rien n'est écrit
     * @throws InvalidArgumentException              la séance d'ancrage n'a pas de planning parent
     *
     * @return array{blockId: string, movedSlotIds: list<string>, createdSlotIds: list<string>, activatedTeamIds: list<string>}
     */
    public function mutualize(ScheduleSlotTemplate $anchor, array $teamIds, ?string $label, array $replacedSlotIds): array
    {
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($anchor->getScheduleId());
        if (!$schedule instanceof Schedule) {
            throw new InvalidArgumentException('The anchor slot has no parent schedule.');
        }

        // Une génération réécrit le planning : mutualiser maintenant écraserait son résultat.
        if ($this->clubGenerationLock->isGenerating($anchor->getClubId())) {
            throw new ScheduleGenerationInProgressException;
        }

        // Q5 — plans de PÉRIODE seulement : le socle de saison n'est pas un plan sur lequel on
        // mutualise à la main (il pilote la génération de base, pas une retouche de période).
        if ($this->schedulePlanProvisioner->isSeasonSchedule($schedule)) {
            throw new MutualizationRefusedException('La mutualisation depuis la génération se fait sur un plan de période, pas sur le planning de saison.');
        }

        $clubId = $anchor->getClubId();
        $seasonId = $anchor->getSeasonId();
        $planId = $schedule->getSchedulePlanId();
        $normalizedLabel = null === $label || '' === trim($label) ? null : trim($label);

        return $this->entityManager->wrapInTransaction(function () use ($anchor, $schedule, $clubId, $seasonId, $planId, $teamIds, $replacedSlotIds, $normalizedLabel): array {
            $members = $this->normalizeMembers($teamIds, $anchor->getTeamId());
            if (\count($members) < 2) {
                throw new MutualizationRefusedException('Un bloc de mutualisation compte au moins 2 équipes.');
            }
            if (\count($members) > 10) {
                throw new MutualizationRefusedException('Un bloc de mutualisation compte au plus 10 équipes.');
            }

            // Chaque membre doit être connu du club+saison ET seasonnièrement actif (une équipe qui
            // ne s'entraîne pas de la saison ne se mutualise pas — parité SharedTrainingBlockStateProcessor).
            $teamRepository = $this->entityManager->getRepository(Team::class);
            foreach ($members as $teamId) {
                $team = $teamRepository->findOneBy(['id' => $teamId, 'clubId' => $clubId, 'seasonId' => $seasonId]);
                if (!$team instanceof Team) {
                    throw new MutualizationRefusedException('Une équipe du bloc est inconnue de cette saison.');
                }
                if (!$team->getIsActive()) {
                    throw new MutualizationRefusedException('Une équipe du bloc est inactive et ne peut pas être mutualisée.');
                }
            }

            // Deux blocs au MÊME ensemble d'équipes dans la même portée sèmeraient la confusion.
            if ($this->sameTeamSetAlreadyDeclared($clubId, $seasonId, $planId, $members)) {
                throw new MutualizationRefusedException('Un bloc de mutualisation portant exactement ces équipes existe déjà pour cette portée.');
            }

            $venueId = $anchor->getVenueId();
            $dayOfWeek = $anchor->getDayOfWeek();
            $startTime = $anchor->getStartTime();
            $durationMinutes = $anchor->getDurationMinutes();

            // CAPACITÉ — après le geste, le bloc tient la case comme UNE occupation (les membres
            // s'entraînent ensemble) ; s'y ajoutent les séances d'équipes NON-membres déjà posées.
            // Si le total dépasse la capacité de la fenêtre, la case ne peut pas accueillir la
            // séance commune.
            $this->assertCaseHasRoom($schedule, $members, $venueId, $dayOfWeek, $startTime, $planId, $clubId, $seasonId);

            // Résoudre l'action de chaque équipe REJOIGNANTE (hors source) : déplacer sa séance
            // désignée, ou l'activer si elle n'a aucune séance dans ce planning.
            $slotRepository = $this->entityManager->getRepository(ScheduleSlotTemplate::class);
            $toMove = [];        // list<ScheduleSlotTemplate> — séances remplacées à déplacer+verrouiller
            $toActivate = [];    // list<string> — équipes à 0 séance à activer
            foreach ($members as $teamId) {
                if ($teamId === $anchor->getTeamId()) {
                    continue; // la source garde sa séance (elle DEVIENT la commune), simplement verrouillée
                }
                $existing = $slotRepository->findBy(['scheduleId' => $schedule->getId(), 'teamId' => $teamId]);
                if ([] === $existing) {
                    $toActivate[] = $teamId;

                    continue;
                }
                $replaced = $this->pickReplacedSlot($existing, $replacedSlotIds);
                if (!$replaced instanceof ScheduleSlotTemplate) {
                    throw new MutualizationRefusedException('Choisissez la séance que la séance commune remplace pour chaque équipe déjà placée.');
                }
                $toMove[] = $replaced;
            }

            // Activer les équipes à 0 séance (override de période : 1 séance, marquée « mutualisation »)
            // AVANT la garde Σ, pour que leur volume effectif reflète le geste.
            foreach ($toActivate as $teamId) {
                $this->activateTeamForPeriod($clubId, $seasonId, $planId, $teamId);
            }
            $this->entityManager->flush();

            // GARDE Σ (P2-60) — avec le bloc VIRTUEL ajouté : pour chaque équipe, Σ des séances
            // communes de ses blocs ≤ ses séances hebdomadaires EFFECTIVES (override compris), et les
            // réservations individuelles existantes ne débordent pas du résidu.
            $this->assertSumWithinBudget($members, $clubId, $seasonId, $planId);

            // Le bloc de portée PLAN + ses lignes membres.
            $block = new SharedTrainingBlock;
            $block->setClubId($clubId);
            $block->setSeasonId($seasonId);
            $block->setSchedulePlanId($planId);
            $block->setCommonSessions(1);
            $block->setLabel($normalizedLabel);
            $this->entityManager->persist($block);
            foreach ($members as $teamId) {
                $member = new SharedTrainingBlockTeam;
                $member->setClubId($clubId);
                $member->setSeasonId($seasonId);
                $member->setSchedulePlanId($planId);
                $member->setBlockId($block->getId());
                $member->setTeamId($teamId);
                $this->entityManager->persist($member);
            }

            // ANCRAGE — la source verrouillée sur place, les remplacées déplacées+verrouillées, les
            // activées créées+verrouillées : toutes co-localisées HARD/MANUAL à la case.
            $this->lockOnCase($anchor);

            $movedSlotIds = [];
            foreach ($toMove as $slot) {
                $slot->setVenueId($venueId)->setDayOfWeek($dayOfWeek)->setStartTime($startTime)->setDurationMinutes($durationMinutes);
                $this->lockOnCase($slot);
                $movedSlotIds[] = $slot->getId();
            }

            $createdSlotIds = [];
            foreach ($toActivate as $teamId) {
                $slot = (new ScheduleSlotTemplate)
                    ->setClubId($clubId)
                    ->setSeasonId($seasonId)
                    ->setScheduleId($schedule->getId())
                    ->setTeamId($teamId)
                    ->setVenueId($venueId)
                    ->setDayOfWeek($dayOfWeek)
                    ->setStartTime($startTime)
                    ->setDurationMinutes($durationMinutes)
                    ->setLockLevel(LockLevel::HARD)
                    ->setLockOrigin(LockOrigin::MANUAL);
                $this->entityManager->persist($slot);
                $createdSlotIds[] = $slot->getId();
            }

            $schedule->setManuallyEditedSinceGeneration(true);
            $this->entityManager->flush();

            // Les autres gestionnaires voient le planning bouger (best-effort, comme la génération).
            $this->progressPublisher->publishSafely($schedule, []);

            return [
                'blockId' => $block->getId(),
                'movedSlotIds' => $movedSlotIds,
                'createdSlotIds' => $createdSlotIds,
                'activatedTeamIds' => $toActivate,
            ];
        });
    }

    /**
     * Les membres, SOURCE comprise (ajoutée si le client l'a omise — elle est pré-cochée côté UI),
     * dédupliqués en préservant l'ordre.
     *
     * @param list<string> $teamIds
     *
     * @return list<string>
     */
    private function normalizeMembers(array $teamIds, string $anchorTeamId): array
    {
        $seen = [];
        $members = [];
        foreach ([$anchorTeamId, ...$teamIds] as $teamId) {
            if ('' === $teamId || isset($seen[$teamId])) {
                continue;
            }
            $seen[$teamId] = true;
            $members[] = $teamId;
        }

        return $members;
    }

    /**
     * La séance remplacée pour une équipe DÉJÀ placée : celle que le client a désignée (parmi les
     * siennes dans ce planning), ou — s'il n'en a désigné aucune mais qu'elle n'a qu'UNE séance —
     * cette unique séance (annoncée, Q1). Plusieurs séances et aucune désignée → null (refus).
     *
     * @param list<ScheduleSlotTemplate> $existing        les séances de l'équipe dans ce planning
     * @param list<string>               $replacedSlotIds les ids désignés par le client
     */
    private function pickReplacedSlot(array $existing, array $replacedSlotIds): ?ScheduleSlotTemplate
    {
        $designated = array_fill_keys($replacedSlotIds, true);
        foreach ($existing as $slot) {
            if (isset($designated[$slot->getId()])) {
                return $slot;
            }
        }

        return 1 === \count($existing) ? $existing[0] : null;
    }

    /**
     * Après le geste, la case tient le bloc comme UNE occupation + les séances des équipes
     * NON-membres déjà posées. Refus si ce total dépasse la capacité de la fenêtre de gymnase.
     *
     * @param list<string> $members
     */
    private function assertCaseHasRoom(Schedule $schedule, array $members, string $venueId, int $dayOfWeek, DateTimeImmutable $startTime, ?string $planId, string $clubId, string $seasonId): void
    {
        $memberSet = array_fill_keys($members, true);
        $slotsAtCase = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy([
            'scheduleId' => $schedule->getId(),
            'venueId' => $venueId,
            'dayOfWeek' => $dayOfWeek,
            'startTime' => $startTime,
        ]);

        $nonMemberTeams = [];
        foreach ($slotsAtCase as $slot) {
            if (!isset($memberSet[$slot->getTeamId()])) {
                $nonMemberTeams[$slot->getTeamId()] = true;
            }
        }

        $occupations = 1 + \count($nonMemberTeams); // le bloc (1) + chaque équipe non-membre présente
        $capacity = $this->caseCapacity($clubId, $seasonId, $planId, $venueId, $dayOfWeek, $startTime);
        if ($occupations > $capacity) {
            throw new MutualizationRefusedException('La case visée est déjà occupée : elle ne peut pas accueillir la séance commune.');
        }
    }

    /** La capacité de la fenêtre de gymnase à la case, dans la COUCHE du plan de période (sa grille). */
    private function caseCapacity(string $clubId, string $seasonId, ?string $planId, string $venueId, int $dayOfWeek, DateTimeImmutable $startTime): int
    {
        $window = $this->entityManager->getRepository(VenueTrainingSlot::class)->findOneBy([
            'clubId' => $clubId,
            'seasonId' => $seasonId,
            'schedulePlanId' => $planId,
            'venueId' => $venueId,
            'dayOfWeek' => $dayOfWeek,
            'startTime' => $startTime,
        ]);

        return $window instanceof VenueTrainingSlot ? $window->getCapacity() : 1;
    }

    /**
     * Active une équipe dans le plan de période : override isActive + 1 séance, marqué
     * « mutualisation ». Crée l'override s'il manque, le relève s'il existe (p.ex. désactivé).
     */
    private function activateTeamForPeriod(string $clubId, string $seasonId, ?string $planId, string $teamId): void
    {
        if (null === $planId) {
            return; // défense : Q5 borne déjà au plan de période (planId non-null)
        }

        $override = $this->entityManager->getRepository(TeamPeriodOverride::class)
            ->findOneBy(['schedulePlanId' => $planId, 'teamId' => $teamId]);
        if (!$override instanceof TeamPeriodOverride) {
            $override = (new TeamPeriodOverride)
                ->setClubId($clubId)
                ->setSeasonId($seasonId)
                ->setSchedulePlanId($planId)
                ->setTeamId($teamId);
            $this->entityManager->persist($override);
        }
        $override->setIsActive(true)->setSessionsPerWeek(1)->setSource(self::OVERRIDE_SOURCE);
    }

    /**
     * GARDE Σ (porte 2, {@see SoloReservationBudget}) avec le bloc VIRTUEL {$members, commonSessions 1}.
     *
     * @param list<string> $members
     */
    private function assertSumWithinBudget(array $members, string $clubId, string $seasonId, ?string $planId): void
    {
        $budgets = $this->soloReservationBudget->forTeamsWithBlockSubstituted($members, $clubId, $seasonId, $planId, null, 1);
        foreach ($budgets as $budget) {
            if ($budget->block > $budget->effective) {
                throw new MutualizationRefusedException(\sprintf('Le total des séances communes des blocs d\'une équipe (%d) dépasse son nombre de séances hebdomadaires (%d).', $budget->block, $budget->effective));
            }
        }
        foreach ($budgets as $budget) {
            if ($budget->individualUsed > $budget->residual) {
                throw new MutualizationRefusedException('Cette mutualisation ferait passer des créneaux individuels existants au-dessus du résidu autorisé. Retirez ces réservations individuelles avant de mutualiser.');
            }
        }
    }

    /** Verrouille une séance HARD/MANUAL : c'est ce que le solveur lit pour ancrer le bloc à la case. */
    private function lockOnCase(ScheduleSlotTemplate $slot): void
    {
        $slot->setLockLevel(LockLevel::HARD)->setLockOrigin(LockOrigin::MANUAL);
    }

    /**
     * Existe-t-il déjà, dans la même portée, un bloc dont l'ensemble d'équipes est EXACTEMENT celui
     * demandé ? (Parité {@see SharedTrainingBlockStateProcessor::sameTeamSetAlreadyDeclared}.).
     *
     * @param list<string> $teamIds
     */
    private function sameTeamSetAlreadyDeclared(string $clubId, string $seasonId, ?string $planId, array $teamIds): bool
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
}
