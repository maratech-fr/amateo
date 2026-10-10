<?php

declare(strict_types=1);

namespace App\Export;

use App\Entity\Coach;
use App\Entity\PriorityTier;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Service\SchedulePlanProvisioner;
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
        foreach ($this->entityManager->getRepository(Coach::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $coach) {
            $coachNames[$coach->getId()] = trim(\sprintf('%s %s', $coach->getFirstName(), $coach->getLastName()));
        }

        // Defined-but-unfilled venue windows ("créneaux vides"): the same view the
        // on-screen grid shows. A window is "filled" as soon as ≥1 team is placed
        // on the same venue+day+start (capacity is not split — MVP, matches the UI).
        $filled = [];
        // Gymnases RÉELLEMENT utilisés par cette version (≥ 1 séance placée) — sert à faire
        // DISPARAÎTRE de l'export d'ensemble un gymnase dont AUCUNE séance n'a été placée
        // (décision fondateur : ses créneaux « vide » encombraient la grille). Un gymnase utilisé
        // garde ses cellules « vide ». ⚠ L'export d'UN seul gymnase (`$venueId`) n'applique pas ce
        // filtre : on consulte alors sa grille complète, vides compris.
        $usedVenues = [];
        foreach ($slots as $slot) {
            $filled[$slot->getVenueId() . '|' . $slot->getDayOfWeek() . '|' . $slot->getStartTime()->format('H:i')] = true;
            $usedVenues[$slot->getVenueId()] = true;
        }
        // #8 — LA COUCHE DE LA VERSION EXPORTÉE, et elle seule. Depuis que chaque période
        // possède sa grille (copie du modèle de saison à la naissance du plan), un club à
        // trois périodes a quatre exemplaires du même créneau en base : sans ce filtre,
        // l'export d'un planning quelconque affichait chaque créneau vide quatre fois.
        // Un plan de saison lit les créneaux du socle (schedulePlanId NULL), une période
        // les siens — exactement la règle que suit le payload envoyé à l'engine.
        $trainingCriteria = ['clubId' => $clubId, 'seasonId' => $seasonId, 'schedulePlanId' => $this->slotLayerOf($schedule)];
        if (null !== $venueId) {
            $trainingCriteria['venueId'] = $venueId;
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
            if (!isset($filled[$key])) {
                $emptySlots[] = new ExportEmptyWindow($window->getVenueId(), $window->getDayOfWeek(), $window->getStartTime(), $window->getDurationMinutes());
            }
        }

        return new ScheduleExportData($slots, $teamNames, $teamCategories, $venues, $coachNames, $emptySlots, $teamRanks, $groupLabels);
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
