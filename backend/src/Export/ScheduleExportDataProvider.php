<?php

declare(strict_types=1);

namespace App\Export;

use App\Entity\Coach;
use App\Entity\PriorityTier;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\SharedTrainingBlock;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\TeamCoachRole;
use App\Service\PlanVenueClosures;
use App\Service\SchedulePlanProvisioner;
use App\Service\VenueWindowOccupancy;
use App\Support\CoachShortName;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Loads the data a schedule export needs: the slots (optionally scoped to a
 * single venue) plus the tenant-scoped team / venue / coach name maps. One home
 * for the queries both export generators used to duplicate.
 */
final class ScheduleExportDataProvider
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly PlanVenueClosures $planVenueClosures,
    ) {}

    public function load(Schedule $schedule, ?string $venueId = null): ScheduleExportData
    {
        $criteria = ['scheduleId' => $schedule->getId()];
        if (null !== $venueId) {
            // Scope the query itself instead of over-fetching then filtering in PHP.
            $criteria['venueId'] = $venueId;
        }
        $slots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy($criteria);

        $clubId = $schedule->getClubId();
        $seasonId = $schedule->getSeasonId();
        // La COUCHE de la version exportée (NULL = socle, l'id du plan = période) : lue UNE fois,
        // elle borne les créneaux lus et pilote l'exclusion des gymnases fermés (lot B).
        $layer = $this->slotLayerOf($schedule);

        $categoryNames = [];
        foreach ($this->entityManager->getRepository(SportCategory::class)->findBy(['clubId' => $clubId]) as $category) {
            $categoryNames[$category->getId()] = $category->getName();
        }

        // Priority tiers are global (S→A→B→C→D, id 1..5) — not club-scoped. Mapped once so the
        // PDF matrix can group team rows by rank and print each tier's subtitle.
        $tiers = [];
        foreach ($this->entityManager->getRepository(PriorityTier::class)->findAll() as $tier) {
            $tiers[$tier->getId()] = ['label' => $tier->getLabel(), 'name' => $tier->getName()];
        }

        $teamNames = [];
        $teamCategories = [];
        $teamRanks = [];
        foreach ($this->entityManager->getRepository(Team::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $team) {
            $teamNames[$team->getId()] = $team->getName();
            $teamCategories[$team->getId()] = $categoryNames[$team->getSportCategoryId()] ?? '';
            // tierRank = the tier id itself : the seed orders S=1 … D=5, so ascending id IS the
            // S→A→B→C→D group order the founder froze. tierOrder = position WITHIN the tier
            // (the two together are the team's global rank — Team.php).
            $tierId = $team->getPriorityTierId();
            $teamRanks[$team->getId()] = [
                'label' => $tiers[$tierId]['label'] ?? '',
                'name' => $tiers[$tierId]['name'] ?? '',
                'tierRank' => $tierId,
                'tierOrder' => $team->getTierOrder(),
            ];
        }

        $venues = [];
        foreach ($this->entityManager->getRepository(Venue::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $venue) {
            $venues[$venue->getId()] = ['name' => $venue->getName(), 'color' => $venue->getColor()];
        }

        $coachNames = [];
        // Chantier E — en plus du nom plein (Excel), le nom COURT pour la grille du PDF, construit
        // depuis LES MÊMES coachs tenant-scopés. Un coach sans nom n'entre pas dans la map courte
        // (CoachShortName rend null) → sa ligne est omise à l'export (« pas de coach → rien »).
        $coachShortNames = [];
        foreach ($this->entityManager->getRepository(Coach::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $coach) {
            $coachNames[$coach->getId()] = trim(\sprintf('%s %s', $coach->getFirstName(), $coach->getLastName()));
            $shortName = CoachShortName::from($coach->getFirstName(), $coach->getLastName());
            if (null !== $shortName) {
                $coachShortNames[$coach->getId()] = $shortName;
            }
        }

        // Chantier E — la carte équipe → 1er coach MAIN, repli du coach affiché quand une séance n'a
        // pas de coach à elle (233 séances dans ce cas sur la base locale du fondateur). On lit LE MÊME
        // critère que l'écran : liens `team_coach` MAIN seuls, scopés club+saison, et ORDONNÉS par id de
        // LIEN ASC — exactement l'ordre que sert l'endpoint `team_coach` (AbstractStateProvider, tri
        // déterministe sur la PK) que consomme `grid.ts::slotCoachId`. On garde le 1er lien par équipe.
        // ⚠ On N'ordonne PAS via `CoachDoubleBookingDetector` (sa résolution « 1er MAIN » relève d'un
        // autre chantier) : la requête ordonnée vit ici, pour l'export.
        $teamMainCoachIds = [];
        $teamCoachIds = [];
        foreach ($this->entityManager->getRepository(TeamCoach::class)->findBy(
            ['clubId' => $clubId, 'seasonId' => $seasonId, 'role' => TeamCoachRole::MAIN],
            ['id' => 'ASC'],
        ) as $link) {
            $teamId = $link->getTeamId();
            if (!isset($teamMainCoachIds[$teamId])) {
                $teamMainCoachIds[$teamId] = $link->getCoachId();
            }
            $teamCoachIds[$teamId][] = $link->getCoachId();
        }

        // Defined-but-unfilled venue windows ("créneaux vides"): the same view the
        // on-screen grid shows. A window is "filled" as soon as ≥1 session on the same venue+day
        // OVERLAPS it in time (capacity is not split — MVP, matches the UI). Overlap, not exact
        // start : la grille d'une période peut être RETAILLÉE après la génération, donc une séance
        // verrouillée garde un début qui ne coïncide plus avec celui de la fenêtre (prod BCCL :
        // fenêtre 17:30-18:45, séance 17:15-18:45). La RÈGLE vit dans App\Service\VenueWindowOccupancy,
        // miroir déclaré de `frontend/.../emptySlots.ts` (parité EmptySlotOverlapMirrorParityTest).
        $filled = [];
        // Les séances placées, prêtes pour le test de chevauchement (venue+day+[début, fin)).
        $occupants = [];
        // Gymnases RÉELLEMENT utilisés par cette version (≥ 1 séance placée) — sert à faire
        // DISPARAÎTRE de l'export d'ensemble un gymnase dont AUCUNE séance n'a été placée
        // (décision fondateur : ses créneaux « vide » encombraient la grille). Un gymnase utilisé
        // garde ses cellules « vide ». ⚠ L'export d'UN seul gymnase (`$venueId`) n'applique pas ce
        // filtre : on consulte alors sa grille complète, vides compris.
        $usedVenues = [];
        foreach ($slots as $slot) {
            $occupants[] = [
                'venueId' => $slot->getVenueId(),
                'dayOfWeek' => $slot->getDayOfWeek(),
                'startTime' => $slot->getStartTime()->format('H:i'),
                'durationMinutes' => $slot->getDurationMinutes(),
            ];
            $usedVenues[$slot->getVenueId()] = true;
        }

        // Lot 4bis — les CRÉNEAUX LIBRES réservés (réservation sans équipe) de la MÊME couche que la
        // version exportée (socle NULL / période). Ils occupent leur case (nommée) : on les marque
        // « filled » + « used » pour qu'ils ne retombent pas en case « vide », et on les rend à
        // l'export comme cases occupées. La case d'un créneau libre a perdu sa place au payload, donc
        // aucune séance n'y est jamais placée — c'est bien un occupant nommé, pas un trou.
        $freeSlotCriteria = ['clubId' => $clubId, 'seasonId' => $seasonId, 'schedulePlanId' => $layer, 'teamId' => null];
        if (null !== $venueId) {
            $freeSlotCriteria['venueId'] = $venueId;
        }
        $freeSlots = [];
        foreach ($this->entityManager->getRepository(Reservation::class)->findBy($freeSlotCriteria) as $reservation) {
            $filled[$reservation->getVenueId() . '|' . $reservation->getDayOfWeek() . '|' . $reservation->getStartTime()->format('H:i')] = true;
            $usedVenues[$reservation->getVenueId()] = true;
            $freeSlots[] = new ExportReservedWindow(
                $reservation->getVenueId(),
                $reservation->getDayOfWeek(),
                $reservation->getStartTime(),
                $reservation->getDurationMinutes(),
                $reservation->getLabel() ?? 'Créneau réservé',
            );
        }
        // #8 — LA COUCHE DE LA VERSION EXPORTÉE, et elle seule. Depuis que chaque période
        // possède sa grille (copie du modèle de saison à la naissance du plan), un club à
        // trois périodes a quatre exemplaires du même créneau en base : sans ce filtre,
        // l'export d'un planning quelconque affichait chaque créneau vide quatre fois.
        // Un plan de saison lit les créneaux du socle (schedulePlanId NULL), une période
        // les siens — exactement la règle que suit le payload envoyé à l'engine.
        $trainingCriteria = ['clubId' => $clubId, 'seasonId' => $seasonId, 'schedulePlanId' => $layer];
        if (null !== $venueId) {
            $trainingCriteria['venueId'] = $venueId;
        }

        // Lot B — à l'export d'ENSEMBLE d'une période, un gymnase DÉSACTIVÉ ou effectivement
        // FERMÉ-TOTAL pour ce plan n'offre plus rien à placer : ses fenêtres VIDES disparaissent de
        // la grille, comme un gymnase jamais utilisé (#1142). Les séances déjà placées dessus restent
        // (`$slots`) — l'export ne nie jamais ce qui existe. L'état effectif vient de la MAISON UNIQUE
        // `PlanVenueClosures` (incident COMPOSÉ avec les réglages du plan) : un jour simplement rouvert
        // à la main (masque OPEN) ne rend pas le gymnase fermé-total, ses vides subsistent donc.
        // ⚠ L'écran, lui, n'écarte à ce jour que les gymnases DÉSACTIVÉS
        // (`CalendarEntryConflictsController`) — l'export est donc un peu plus juste (il tient compte
        // du fermé-total effectif). Le socle (layer NULL) n'a pas de fermeture datée et le mono-gymnase
        // (`$venueId`) garde sa grille entière : dans ces deux cas l'ensemble reste vide.
        $hiddenVenueIds = [];
        if (null === $venueId && null !== $layer) {
            $state = $this->planVenueClosures->effectiveStateForPlan($layer);
            $hiddenVenueIds = $state['disabledVenueIds'] + $state['fullyClosedVenueIds'];
        }

        $emptySlots = [];
        // "venue|day|H:i" → group label ("CEC3"), read from the SAME layer's windows. A window's
        // identity (that key) is what a placement shares with its window, so the export can title a
        // filled cell OR tag an empty one by looking the label up. Only labelled windows are kept.
        $groupLabels = [];
        foreach ($this->entityManager->getRepository(VenueTrainingSlot::class)->findBy($trainingCriteria) as $window) {
            $key = $window->getVenueId() . '|' . $window->getDayOfWeek() . '|' . $window->getStartTime()->format('H:i');
            $label = $window->getGroupLabel();
            if (null !== $label && '' !== $label) {
                $groupLabels[$key] = $label;
            }
            // Un gymnase ENTIÈREMENT vide (aucune séance placée) disparaît de l'export d'ensemble :
            // ses fenêtres vides ne créent ni colonne (PDF) ni ligne « (vide) » (Excel). Sur un
            // export mono-gymnase (`$venueId`), on garde toute sa grille, vides compris.
            if (null === $venueId && !isset($usedVenues[$window->getVenueId()])) {
                continue;
            }
            // Lot B — gymnase désactivé / effectivement fermé-total pour ce plan : ses fenêtres vides
            // ne paraissent pas à l'export d'ensemble (les séances placées, elles, restent via `$slots`).
            // `$hiddenVenueIds` est déjà vide hors de l'export d'ensemble d'une période.
            if (isset($hiddenVenueIds[$window->getVenueId()])) {
                continue;
            }
            // Vide = ni occupée par une réservation à l'identique (`$filled`, clé exacte) NI chevauchée
            // par une séance du même gymnase+jour (la règle partagée avec la grille à l'écran).
            $isFilledByOverlap = VenueWindowOccupancy::windowIsFilled([
                'venueId' => $window->getVenueId(),
                'dayOfWeek' => $window->getDayOfWeek(),
                'startTime' => $window->getStartTime()->format('H:i'),
                'durationMinutes' => $window->getDurationMinutes(),
            ], $occupants);
            if (!isset($filled[$key]) && !$isFilledByOverlap) {
                $emptySlots[] = new ExportEmptyWindow($window->getVenueId(), $window->getDayOfWeek(), $window->getStartTime(), $window->getDurationMinutes());
            }
        }

        // Lot 9 — le NOM d'un bloc de mutualisation PRIME sur le libellé de la fenêtre : une case qui
        // porte une séance de groupe affiche le nom du bloc (grille / fiche / PDF / Excel). On lit le
        // lien que la séance porte (`schedule_slot_template.shared_training_block_id`, option B) et on
        // recouvre la clé de case correspondante, APRÈS la boucle des fenêtres pour primer dessus.
        foreach ($this->blockLabelsByCase($slots) as $key => $label) {
            $groupLabels[$key] = $label;
        }

        return new ScheduleExportData($slots, $teamNames, $teamCategories, $venues, $coachNames, $emptySlots, $teamRanks, $groupLabels, $freeSlots, $coachShortNames, $teamMainCoachIds, $teamCoachIds);
    }

    /**
     * Les noms de bloc à afficher, par case "venueId|day|H:i", lus du lien porté par chaque séance.
     * Seuls les blocs NOMMÉS paraissent (un bloc sans nom laisse la case à son libellé de fenêtre).
     *
     * @param list<ScheduleSlotTemplate> $slots
     *
     * @return array<string, string>
     */
    private function blockLabelsByCase(array $slots): array
    {
        $blockIds = [];
        foreach ($slots as $slot) {
            $blockId = $slot->getSharedTrainingBlockId();
            if (null !== $blockId) {
                $blockIds[$blockId] = true;
            }
        }
        if ([] === $blockIds) {
            return [];
        }

        $labels = [];
        foreach ($this->entityManager->getRepository(SharedTrainingBlock::class)->findBy(['id' => array_keys($blockIds)]) as $block) {
            $label = $block->getLabel();
            if (null !== $label && '' !== $label) {
                $labels[$block->getId()] = $label;
            }
        }

        $byCase = [];
        foreach ($slots as $slot) {
            $blockId = $slot->getSharedTrainingBlockId();
            if (null !== $blockId && isset($labels[$blockId])) {
                $byCase[$slot->getVenueId() . '|' . $slot->getDayOfWeek() . '|' . $slot->getStartTime()->format('H:i')] = $labels[$blockId];
            }
        }

        return $byCase;
    }

    /**
     * La couche de créneaux d'une version : NULL pour le socle (les créneaux de saison),
     * l'id du plan pour une période (sa grille à elle).
     */
    private function slotLayerOf(Schedule $schedule): ?string
    {
        return $this->schedulePlanProvisioner->isSeasonSchedule($schedule) ? null : $schedule->getSchedulePlanId();
    }
}
