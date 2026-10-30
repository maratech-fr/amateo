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
 * Disponible au planning de SAISON (socle) comme sur un plan de PÉRIODE (le socle se comporte comme
 * la saison, décision fondateur 2026-10-13). La PORTÉE du bloc suit l'écran : NULL au socle (comme
 * les blocs de saison), le plan de période sinon. Seule l'ACTIVATION d'une équipe à 0 séance reste
 * propre à la période — au socle c'est un refus nommé.
 *
 * Ce que le geste écrit, en UNE transaction (tout-ou-rien) :
 *  - un {@see SharedTrainingBlock} de PORTÉE adaptée à l'écran (`schedulePlanId` = le plan de période,
 *    ou NULL au socle), `commonSessions = 1`, nom optionnel ;
 *  - la séance de la SOURCE verrouillée HARD/MANUAL sur place ;
 *  - pour chaque équipe rejoignante DÉJÀ placée : la séance qu'elle DÉSIGNE (Q1 — choix conscient)
 *    est DÉPLACÉE sur la case et verrouillée HARD/MANUAL ;
 *  - pour chaque équipe rejoignante à 0 séance dans le plan : un override de période l'ACTIVE
 *    (1 séance, `source = mutualisation`) et une séance NEUVE naît sur la case, verrouillée.
 *
 * Refus 422 NOMMÉS (rien n'est écrit) : équipe à 0 séance activée AU SOCLE (période seulement),
 * équipe inactive, équipe inconnue, ensemble déjà déclaré, case déjà occupée (capacité), Σ des
 * séances communes dépassant le volume d'une équipe, équipe déjà placée sans séance désignée, équipe
 * rejoignante siégeant déjà à la case d'ancrage par une AUTRE séance (une équipe ne siège jamais
 * deux fois à la même case).
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
        private readonly TeamCaseOccupancyGuard $teamCaseOccupancyGuard,
        private readonly ReservationGroupOccupancy $reservationGroupOccupancy,
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

        // « Mutualiser… » est disponible au planning de SAISON comme sur un plan de période — le
        // socle se comporte comme la saison (décision fondateur 2026-10-13). La PORTÉE du geste suit
        // l'écran : au socle, le bloc et tout ce qu'il scope (grille de gymnase, budget Σ, unicité de
        // l'ensemble d'équipes) vivent à portée NULL — exactement comme les blocs de saison que
        // `SchedulePlanProvisioner::copySocleSharedBlocks` recopie vers les fermetures. Sur un plan de
        // période, la portée est ce plan. ⚠ `Schedule::getSchedulePlanId()` d'un socle rend l'id du
        // plan SEASON (non-null) — c'est précisément ce qu'on NE veut PAS écrire dans le bloc : au
        // socle on force `null`. Seule l'ACTIVATION d'une équipe à 0 séance reste propre à la période
        // (refus nommé au socle, cf. activateTeamForPeriod).
        $isSocle = $this->schedulePlanProvisioner->isSeasonSchedule($schedule);

        $clubId = $anchor->getClubId();
        $seasonId = $anchor->getSeasonId();
        $planId = $isSocle ? null : $schedule->getSchedulePlanId();
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
                // Règle fondateur : la MÊME équipe ne siège jamais deux fois à la même case. La séance
                // désignée va être DÉPLACÉE sur la case d'ancrage ; si l'équipe possède à cette case une
                // AUTRE séance (que la désignée), elle y siégerait deux fois — refus NOMMÉ, rien n'écrit.
                $conflict = $this->teamCaseOccupancyGuard->conflictSentence($schedule->getId(), $teamId, $venueId, $dayOfWeek, $startTime, [$replaced->getId()]);
                if (null !== $conflict) {
                    throw new MutualizationRefusedException($conflict . '.');
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
            // Le bloc doit EXISTER en base avant que les séances le référencent : la colonne
            // `shared_training_block_id` porte une FK et Doctrine n'ordonne pas l'INSERT du bloc
            // avant l'UPDATE des séances (colonne `guid` nue, pas une association). D'où ce flush.
            $this->entityManager->flush();

            // ANCRAGE — la source verrouillée sur place, les remplacées déplacées+verrouillées, les
            // activées créées+verrouillées : toutes co-localisées HARD/MANUAL à la case ET LIÉES au
            // bloc (le lien EST ce qui fait disparaître ces séances quand le bloc est supprimé).
            $this->lockOnCase($anchor, $block->getId());

            $movedSlotIds = [];
            foreach ($toMove as $slot) {
                $slot->setVenueId($venueId)->setDayOfWeek($dayOfWeek)->setStartTime($startTime)->setDurationMinutes($durationMinutes);
                $this->lockOnCase($slot, $block->getId());
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
                    ->setLockOrigin(LockOrigin::MANUAL)
                    ->setSharedTrainingBlockId($block->getId());
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
     * Lot 2 — MUTUALISER DEPUIS LE DÉPÔT (« on répartit, on ne crée rien », décision fondateur
     * 2026-10-13). Le gestionnaire DÉPOSE une séance (SOURCE) sur une case OCCUPÉE (TARGET) et choisit
     * « Mutualiser » : les deux séances deviennent UNE séance commune sur la case de la CIBLE (l'ancre).
     * Le geste porte sur UNE séance de chaque côté ; chaque équipe garde son total de séances.
     *
     * Quatre combinaisons, UNE règle (la cible EST l'ancre, où C siège) :
     *  - équipe → équipe   : C = {source, cible}, séance neuve ;
     *  - équipe → groupe   : rejoindre — C = groupe ∪ {source} ;
     *  - groupe → équipe   : rejoindre symétrique — C = groupe ∪ {cible} ;
     *  - groupe → groupe   : fusion — C = groupeA ∪ groupeB.
     *
     * Les deux côtés doivent être DISJOINTS (aucune équipe des deux côtés) : une équipe déjà présente
     * en face est déjà mutualisée avec l'autre — rien à répartir. Cette disjonction subsume « même
     * équipe déjà dans la cible » ET « déjà le même groupe », et garantit que l'ensemble C est
     * STRICTEMENT plus grand que chacun des deux — donc le bloc destination D ne peut jamais être l'un
     * des deux blocs d'origine (leurs décomptes de séances communes ne se télescopent pas).
     *
     * Écriture (UNE transaction, tout-ou-rien) :
     *  - D = le bloc existant portant EXACTEMENT l'ensemble C (il passe alors à +1 séance commune, ce
     *    qui remplace le refus « ensemble déjà déclaré » sur ce chemin) OU un bloc NEUF (1 séance) ;
     *  - les séances de la SOURCE sont DÉPLACÉES sur la case de l'ancre, verrouillées HARD/MANUAL, et
     *    LIÉES à D ; les séances de la CIBLE sont verrouillées HARD/MANUAL et liées à D (sur place) ;
     *  - chaque bloc d'origine (source, cible) perd 1 séance commune ; tombé à 0, il MEURT.
     *
     * ⚠ FK `ON DELETE CASCADE` ({@see ScheduleSlotTemplate} shared_training_block_id) : on RELIE les
     * séances à D AVANT de supprimer un ancien bloc, sinon la cascade emporterait les séances qu'on
     * vient de répartir. Σ est INCHANGÉE par construction (chaque équipe garde son total) — pas de
     * garde Σ sur ce chemin ; seules ≤ 10 équipes et la capacité de la case restent.
     *
     * @throws ScheduleGenerationInProgressException une génération tourne pour ce club (→ 409)
     * @throws MutualizationRefusedException         un refus nommé (→ 422), rien n'est écrit
     * @throws InvalidArgumentException              la séance source n'a pas de planning parent
     *
     * `$dryRun` : mêmes gardes (un refus de garde lève comme en vrai), puis retour AVANT toute
     * écriture — la réponse porte `dryRun => true` et la case source qui SERAIT libérée
     *
     * @return array{blockId: string, movedSlotIds: list<string>, lockedSlotIds: list<string>, removedBlockIds: list<string>, teamIds: list<string>, freedSourceCase: array{venueId: string, dayOfWeek: int, startTime: string}, dryRun?: bool}
     */
    public function mutualizeAtCase(ScheduleSlotTemplate $source, ScheduleSlotTemplate $target, ?string $label, bool $dryRun = false): array
    {
        if ($source->getScheduleId() !== $target->getScheduleId()) {
            throw new MutualizationRefusedException('Les deux séances n\'appartiennent pas au même planning.');
        }
        if ($source->getId() === $target->getId()) {
            throw new MutualizationRefusedException('On ne peut pas mutualiser une séance avec elle-même.');
        }

        $schedule = $this->entityManager->getRepository(Schedule::class)->find($source->getScheduleId());
        if (!$schedule instanceof Schedule) {
            throw new InvalidArgumentException('The source slot has no parent schedule.');
        }

        // Une génération réécrit le planning : mutualiser maintenant écraserait son résultat.
        if ($this->clubGenerationLock->isGenerating($source->getClubId())) {
            throw new ScheduleGenerationInProgressException;
        }

        // Portée du bloc = l'écran (NULL au socle, le plan de période sinon — patron mutualize()).
        $isSocle = $this->schedulePlanProvisioner->isSeasonSchedule($schedule);
        $clubId = $source->getClubId();
        $seasonId = $source->getSeasonId();
        $planId = $isSocle ? null : $schedule->getSchedulePlanId();
        $normalizedLabel = null === $label || '' === trim($label) ? null : trim($label);

        $sourceBlockId = $source->getSharedTrainingBlockId();
        $targetBlockId = $target->getSharedTrainingBlockId();

        // Les séances de CHAQUE côté : le seul créneau pour une équipe simple, ou les N membres du
        // bloc qui siègent À LA CASE pour une séance de groupe (le serveur les résout, jamais le client).
        $sourceSlots = $this->sessionSlotsAtCase($schedule, $source);
        $targetSlots = $this->sessionSlotsAtCase($schedule, $target);

        // Verrou GESTIONNAIRE souverain (D4) : un créneau simple verrouillé à la main (lockLevel ≠ NONE
        // ET sans bloc) n'entre pas dans une mutualisation. Un verrou d'ANCRAGE (avec bloc) est au
        // contraire attendu — c'est une séance de groupe qu'on re-mutualise.
        foreach ([...$sourceSlots, ...$targetSlots] as $slot) {
            if (LockLevel::NONE !== $slot->getLockLevel() && null === $slot->getSharedTrainingBlockId()) {
                throw new MutualizationRefusedException('Une des séances est verrouillée à la main — déverrouillez-la d\'abord.');
            }
        }

        $sourceTeamIds = $this->teamIdsOf($sourceSlots);
        $targetTeamIds = $this->teamIdsOf($targetSlots);

        // DISJONCTION : aucune équipe des deux côtés. Subsume « déjà dans la cible » / « déjà le même
        // groupe » et garantit C ⊋ chaque côté (donc D ≠ bloc d'origine).
        foreach ($sourceTeamIds as $teamId) {
            if (\in_array($teamId, $targetTeamIds, true)) {
                throw new MutualizationRefusedException('Une équipe est déjà présente dans la séance d\'arrivée : elle est déjà mutualisée avec celle-ci.');
            }
        }

        $members = [...$sourceTeamIds, ...$targetTeamIds]; // disjoints ⇒ déjà sans doublon
        if (\count($members) < 2) {
            throw new MutualizationRefusedException('Un bloc de mutualisation compte au moins 2 équipes.');
        }
        if (\count($members) > 10) {
            throw new MutualizationRefusedException('Un bloc de mutualisation compte au plus 10 équipes.');
        }

        // Chaque membre connu du club+saison ET actif (parité mutualize() / SharedTrainingBlockStateProcessor).
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

        // La case d'ANCRAGE est celle de la CIBLE : le bloc fusionné y siège, la source vient l'y rejoindre.
        $venueId = $target->getVenueId();
        $dayOfWeek = $target->getDayOfWeek();
        $startTime = $target->getStartTime();
        $durationMinutes = $target->getDurationMinutes();

        // Aucune équipe du bloc ne doit déjà siéger à la case d'arrivée par une AUTRE séance (hors les
        // créneaux impliqués, qui SONT le geste) — règle fondateur « jamais deux fois à la même case ».
        $involvedIds = array_map(static fn (ScheduleSlotTemplate $s): string => $s->getId(), [...$sourceSlots, ...$targetSlots]);
        foreach ($members as $teamId) {
            $conflict = $this->teamCaseOccupancyGuard->conflictSentence($schedule->getId(), $teamId, $venueId, $dayOfWeek, $startTime, $involvedIds);
            if (null !== $conflict) {
                throw new MutualizationRefusedException($conflict . '.');
            }
        }

        // CAPACITÉ : après le geste, le bloc tient la case en UNE occupation + les séances des équipes
        // non-membres déjà posées (patron assertCaseHasRoom).
        $this->assertCaseHasRoom($schedule, $members, $venueId, $dayOfWeek, $startTime, $planId, $clubId, $seasonId);

        // Décision fondateur 2026-10-30 — les anciens blocs perdent une séance commune : refusé s'il leur
        // resterait moins de séances que de cases réservées. On refuse plutôt que de retirer une
        // réservation en silence.
        foreach ([$sourceBlockId, $targetBlockId] as $oldBlockId) {
            if (null !== $oldBlockId) {
                $this->assertOldBlockKeepsItsReservations($oldBlockId);
            }
        }

        // La case SOURCE qui sera libérée (bandeau côté UI) — celle du créneau source.
        $freedSourceCase = [
            'venueId' => $source->getVenueId(),
            'dayOfWeek' => $source->getDayOfWeek(),
            'startTime' => $source->getStartTime()->format('H:i'),
        ];

        if ($dryRun) {
            // Gardes passées, RIEN écrit : le front confirme avant de committer.
            return [
                'blockId' => '',
                'movedSlotIds' => array_map(static fn (ScheduleSlotTemplate $s): string => $s->getId(), $sourceSlots),
                'lockedSlotIds' => array_map(static fn (ScheduleSlotTemplate $s): string => $s->getId(), $targetSlots),
                'removedBlockIds' => [],
                'teamIds' => $members,
                'freedSourceCase' => $freedSourceCase,
                'dryRun' => true,
            ];
        }

        return $this->entityManager->wrapInTransaction(function () use ($schedule, $clubId, $seasonId, $planId, $members, $normalizedLabel, $sourceSlots, $targetSlots, $sourceBlockId, $targetBlockId, $venueId, $dayOfWeek, $startTime, $durationMinutes, $freedSourceCase): array {
            // D = le bloc existant portant EXACTEMENT C (+1 séance commune) OU un bloc neuf (1 séance).
            $existingBlockId = $this->findBlockIdWithExactTeamSet($clubId, $seasonId, $planId, $members);
            if (null !== $existingBlockId) {
                $block = $this->entityManager->getRepository(SharedTrainingBlock::class)->find($existingBlockId);
                if (!$block instanceof SharedTrainingBlock) {
                    throw new MutualizationRefusedException('Le bloc de mutualisation ciblé est introuvable.');
                }
                $block->setCommonSessions($block->getCommonSessions() + 1);
            } else {
                $block = new SharedTrainingBlock;
                $block->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($planId)->setCommonSessions(1)->setLabel($normalizedLabel);
                $this->entityManager->persist($block);
                foreach ($members as $teamId) {
                    $member = (new SharedTrainingBlockTeam)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($planId)->setBlockId($block->getId())->setTeamId($teamId);
                    $this->entityManager->persist($member);
                }
            }
            // Le bloc doit EXISTER en base avant que les séances le référencent (FK, patron mutualize()).
            $this->entityManager->flush();

            // ⚠ RELIER les séances à D AVANT toute suppression d'ancien bloc (FK ON DELETE CASCADE).
            $movedSlotIds = [];
            foreach ($sourceSlots as $slot) {
                $slot->setVenueId($venueId)->setDayOfWeek($dayOfWeek)->setStartTime($startTime)->setDurationMinutes($durationMinutes);
                $this->lockOnCase($slot, $block->getId());
                $movedSlotIds[] = $slot->getId();
            }
            $lockedSlotIds = [];
            foreach ($targetSlots as $slot) {
                $this->lockOnCase($slot, $block->getId());
                $lockedSlotIds[] = $slot->getId();
            }
            $this->entityManager->flush();

            // Les anciens blocs perdent 1 séance commune ; tombés à 0, ils meurent (leurs séances de
            // CETTE case sont déjà reliées à D, la cascade ne les emporte pas).
            $removedBlockIds = [];
            foreach ([$sourceBlockId, $targetBlockId] as $oldBlockId) {
                if (null === $oldBlockId || $oldBlockId === $block->getId()) {
                    continue;
                }
                $old = $this->entityManager->getRepository(SharedTrainingBlock::class)->find($oldBlockId);
                if (!$old instanceof SharedTrainingBlock) {
                    continue;
                }
                $old->setCommonSessions($old->getCommonSessions() - 1);
                if ($old->getCommonSessions() <= 0) {
                    foreach ($this->entityManager->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $old->getId()]) as $row) {
                        $this->entityManager->remove($row);
                    }
                    $this->entityManager->remove($old);
                    $removedBlockIds[] = $oldBlockId;
                }
            }

            $schedule->setManuallyEditedSinceGeneration(true);
            $this->entityManager->flush();

            // Les autres gestionnaires voient le planning bouger (best-effort, comme la génération).
            $this->progressPublisher->publishSafely($schedule, []);

            return [
                'blockId' => $block->getId(),
                'movedSlotIds' => $movedSlotIds,
                'lockedSlotIds' => $lockedSlotIds,
                'removedBlockIds' => $removedBlockIds,
                'teamIds' => $members,
                'freedSourceCase' => $freedSourceCase,
            ];
        });
    }

    /**
     * Les séances d'un côté du geste de mutualisation au dépôt : le seul créneau si c'est une équipe
     * simple (`sharedTrainingBlockId` NULL), ou les N membres du bloc qui siègent EXACTEMENT à la case
     * de ce créneau si c'est une séance de groupe (le serveur résout, jamais des slotIds client).
     *
     * @return list<ScheduleSlotTemplate>
     */
    private function sessionSlotsAtCase(Schedule $schedule, ScheduleSlotTemplate $slot): array
    {
        $blockId = $slot->getSharedTrainingBlockId();
        if (null === $blockId) {
            return [$slot];
        }

        $block = $this->entityManager->getRepository(SharedTrainingBlock::class)->find($blockId);
        if (!$block instanceof SharedTrainingBlock) {
            // Un créneau porte un blockId dont le bloc n'existe plus : on retombe sur le seul créneau.
            return [$slot];
        }

        $teamIds = array_map(
            static fn (SharedTrainingBlockTeam $row): string => $row->getTeamId(),
            $this->entityManager->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $block->getId()], ['teamId' => 'ASC']),
        );
        if ([] === $teamIds) {
            return [$slot];
        }

        $slots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy([
            'scheduleId' => $schedule->getId(),
            'venueId' => $slot->getVenueId(),
            'dayOfWeek' => $slot->getDayOfWeek(),
            'startTime' => $slot->getStartTime(),
            'teamId' => $teamIds,
        ]);

        return [] === $slots ? [$slot] : $slots;
    }

    /**
     * Les ids d'équipe DISTINCTS d'une liste de créneaux (une équipe ne siège jamais deux fois à la
     * même case, mais on dédoublonne par sûreté), ordre préservé.
     *
     * @param list<ScheduleSlotTemplate> $slots
     *
     * @return list<string>
     */
    private function teamIdsOf(array $slots): array
    {
        $seen = [];
        $out = [];
        foreach ($slots as $slot) {
            $teamId = $slot->getTeamId();
            if (isset($seen[$teamId])) {
                continue;
            }
            $seen[$teamId] = true;
            $out[] = $teamId;
        }

        return $out;
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
            // Au socle (planId NULL), il n'y a pas d'override de période pour donner une séance à une
            // équipe qui n'en a aucune : on REFUSE (l'UI ne propose déjà pas ces équipes au socle, ce
            // refus est le filet). Activer une équipe à 0 séance reste propre aux plans de période.
            throw new MutualizationRefusedException('Au planning de saison, on ne peut pas mutualiser une équipe qui n\'a aucune séance : donnez-lui d\'abord des séances dans la saisie.');
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

    /**
     * Fait d'une séance une séance de GROUPE du bloc : verrou HARD/MANUAL (ce que le solveur lit
     * pour ancrer le bloc à la case) ET lien au bloc (ce qui l'emporte quand le bloc est supprimé).
     */
    private function lockOnCase(ScheduleSlotTemplate $slot, string $blockId): void
    {
        $slot->setLockLevel(LockLevel::HARD)->setLockOrigin(LockOrigin::MANUAL)->setSharedTrainingBlockId($blockId);
    }

    /**
     * Existe-t-il déjà, dans la même portée, un bloc dont l'ensemble d'équipes est EXACTEMENT celui
     * demandé ? (Parité {@see SharedTrainingBlockStateProcessor::sameTeamSetAlreadyDeclared}.).
     *
     * @param list<string> $teamIds
     */
    private function sameTeamSetAlreadyDeclared(string $clubId, string $seasonId, ?string $planId, array $teamIds): bool
    {
        return null !== $this->findBlockIdWithExactTeamSet($clubId, $seasonId, $planId, $teamIds);
    }

    /**
     * L'id du bloc de la portée donnée dont l'ensemble d'équipes est EXACTEMENT celui demandé, ou null.
     * Fondation de {@see sameTeamSetAlreadyDeclared} ET du chemin « +1 séance commune » de
     * {@see mutualizeAtCase} (un dépôt dont C égale un bloc existant alimente ce bloc au lieu d'un doublon).
     *
     * @param list<string> $teamIds
     */
    private function findBlockIdWithExactTeamSet(string $clubId, string $seasonId, ?string $planId, array $teamIds): ?string
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

        foreach ($byBlock as $blockId => $teams) {
            sort($teams);
            if ($teams === $wanted) {
                return $blockId;
            }
        }

        return null;
    }

    private function assertOldBlockKeepsItsReservations(string $blockId): void
    {
        $block = $this->entityManager->getRepository(SharedTrainingBlock::class)->find($blockId);
        if (!$block instanceof SharedTrainingBlock) {
            return;
        }
        $memberSet = [];
        foreach ($this->entityManager->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $blockId]) as $row) {
            $memberSet[$row->getTeamId()] = true;
        }
        $reservedSessions = $this->reservationGroupOccupancy->groupCompleteCaseCount($memberSet, $block->getSchedulePlanId());
        if ($reservedSessions > $block->getCommonSessions() - 1) {
            throw new MutualizationRefusedException(ReservationGroupOccupancy::belowReservedSessionsMessage($reservedSessions));
        }
    }
}
