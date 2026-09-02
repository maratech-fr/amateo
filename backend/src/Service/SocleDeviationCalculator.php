<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\ScheduleSlotTemplate;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P2-44 PR-5 (ADR-0004) — NOMME les écarts entre une version affichée d'un plan de PÉRIODE de type
 * FERMETURE et la version POINTÉE du socle EN VIGUEUR AU MOMENT DE LA LECTURE. Re-appelable, sans
 * mémoire, aucune colonne neuve : le diff se recalcule à chaque appel contre le socle courant.
 *
 * ── Identité d'un placement ────────────────────────────────────────────────────────────────────
 * La clé EXISTANTE `venue:day:HH:MM` (au sein d'une équipe) — la même famille de clé que le
 * transcriber et l'import. La DURÉE et le COACH n'entrent PAS dans l'identité : ce diff parle de
 * PLACEMENT (où, quand), pas de contenu de séance.
 *
 * ── L'appariement EST la règle déterministe assumée ────────────────────────────────────────────
 * Par équipe (les équipes désactivées pour la période sont exclues d'entrée — une équipe en pause
 * n'a aucun écart, miroir de {@see PeriodPlanTranscriber}) :
 *   1. on retire les correspondances EXACTES de clé (socle == période) → INCHANGÉES, non rapportées ;
 *   2. on trie les restants des DEUX côtés par (jour, heure, gymnase, id) CROISSANTS et on apparie
 *      POSITIONNELLEMENT min(|socle|,|période|) paires → DÉPLACÉES (`from` socle → `to` période) ;
 *   3. le reliquat côté socle → NON REPLACÉES (le tri croissant met le reliquat en QUEUE : une
 *      équipe réduite laisse donc ses DERNIÈRES séances de la semaine non replacées — même
 *      déterminisme que la réduction du transcriber, le diff redit ce que la transcription a
 *      annoncé, il n'arbitre pas différemment) ;
 *   4. le reliquat côté période → séances NOUVELLES, non rapportées (décision fondateur : deux
 *      catégories seulement).
 * Le serveur n'INVENTE pas « qui est allée où » (cas de deux séances échangées en croix compris) :
 * il apparie dans l'ordre de la semaine. C'est la règle, assumée.
 *
 * ── La RAISON d'une non-replacée vient de la sélection EXISTANTE, jamais d'un second calcul ─────
 * Dérivée de {@see PeriodConstraintSelector::selectForPeriodPlan}, MÊME précédence que le transcriber
 * ({@see PeriodPlanTranscriber} l. 195-210) : `team_reduced` (les dernières de la semaine, plafonné
 * à count(socle) − sessionOverrides[team]) > `venue_disabled` > `venue_closed` > NULL quand la
 * sélection n'explique pas l'absence (suppression manuelle, solve qui n'a pas replacé). Un NULL
 * honnête, JAMAIS une raison fabriquée — le front rend alors la ligne sans étiquette.
 *
 * LECTURE PURE : aucun persist, aucun flush.
 */
final readonly class SocleDeviationCalculator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PeriodConstraintSelector $periodConstraintSelector,
    ) {}

    public function calculate(string $clubId, string $seasonId, string $schedulePlanId, CalendarEntry $entry, string $chosenSocleId, string $periodScheduleId): SocleDeviationResult
    {
        // La sélection de période EXISTANTE : le filtre partagé (gate + payload + transcription).
        // On en dérive uniquement la RAISON d'une absence — jamais un second calcul de règle.
        $selection = $this->periodConstraintSelector->selectForPeriodPlan($clubId, $seasonId, $schedulePlanId, $entry);
        $deactivatedTeamIds = $selection->deactivatedTeamIds;
        $disabledVenueIds = $selection->disabledVenueIds;
        $closedWeekdaysByVenue = $selection->effectiveClosedWeekdaysByVenue;

        /** @var list<ScheduleSlotTemplate> $socleSlots */
        $socleSlots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $chosenSocleId], ['id' => 'ASC']);
        /** @var list<ScheduleSlotTemplate> $periodSlots */
        $periodSlots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $periodScheduleId], ['id' => 'ASC']);

        // Réduction d'équipe : quelles séances du socle sont retirées (les dernières de la semaine) —
        // même déterminisme que le transcriber (répliqué, le transcriber n'est pas touché).
        $reducedAwaySlotIds = $this->slotsRemovedByReduction($socleSlots, $deactivatedTeamIds, $selection->sessionOverrides);

        $socleByTeam = $this->groupByTeam($socleSlots, $deactivatedTeamIds);
        $periodByTeam = $this->groupByTeam($periodSlots, $deactivatedTeamIds);

        $teamIds = array_keys($socleByTeam + $periodByTeam);
        sort($teamIds); // sortie déterministe

        $moved = [];
        $unplaced = [];
        foreach ($teamIds as $teamId) {
            $socleForTeam = $socleByTeam[$teamId] ?? [];
            $periodForTeam = $periodByTeam[$teamId] ?? [];

            // (1) retirer les correspondances EXACTES de clé (multiset) → inchangées.
            $periodByKey = [];
            foreach ($periodForTeam as $slot) {
                $periodByKey[$this->placementKey($slot)][] = $slot;
            }
            $socleRemaining = [];
            foreach ($socleForTeam as $slot) {
                $key = $this->placementKey($slot);
                if (isset($periodByKey[$key]) && [] !== $periodByKey[$key]) {
                    array_shift($periodByKey[$key]); // une occurrence appariée → inchangée

                    continue;
                }
                $socleRemaining[] = $slot;
            }
            $periodRemaining = [];
            foreach ($periodByKey as $slots) {
                foreach ($slots as $slot) {
                    $periodRemaining[] = $slot;
                }
            }

            // (2) trier les restants et apparier POSITIONNELLEMENT.
            usort($socleRemaining, $this->chronological(...));
            usort($periodRemaining, $this->chronological(...));

            $pairs = min(\count($socleRemaining), \count($periodRemaining));
            for ($i = 0; $i < $pairs; ++$i) {
                $moved[] = [
                    'teamId' => $teamId,
                    // `from` = le socle, non affiché par la grille (pas de slotId). `to` = la
                    // séance de PÉRIODE que la grille rend : elle porte son `slotId` (l'id du
                    // ScheduleSlotTemplate de la période, ce que la carte expose en `cell.slotId`)
                    // pour que le front sache QUELLE carte marquer.
                    'from' => $this->placement($socleRemaining[$i]),
                    'to' => [...$this->placement($periodRemaining[$i]), 'slotId' => $periodRemaining[$i]->getId()],
                ];
            }

            // (3) reliquat côté socle → non replacées, avec la raison de la sélection.
            foreach (\array_slice($socleRemaining, $pairs) as $slot) {
                $unplaced[] = [
                    'teamId' => $teamId,
                    'dayOfWeek' => $slot->getDayOfWeek(),
                    'startTime' => $slot->getStartTime()->format('H:i'),
                    'venueId' => $slot->getVenueId(),
                    'reason' => $this->reasonFor($slot, $reducedAwaySlotIds, $disabledVenueIds, $closedWeekdaysByVenue),
                ];
            }
            // (4) reliquat côté période = séances NOUVELLES : non rapportées (décision fondateur).
        }

        return new SocleDeviationResult($chosenSocleId, $moved, $unplaced);
    }

    /**
     * La raison d'une séance du socle NON replacée — même précédence que le transcriber. NULL quand
     * la sélection n'explique pas l'absence (suppression manuelle, solve qui n'a pas replacé).
     *
     * @param array<string, true>             $reducedAwaySlotIds
     * @param array<string, true>             $disabledVenueIds
     * @param array<string, array<int, true>> $closedWeekdaysByVenue
     */
    private function reasonFor(ScheduleSlotTemplate $slot, array $reducedAwaySlotIds, array $disabledVenueIds, array $closedWeekdaysByVenue): ?string
    {
        if (isset($reducedAwaySlotIds[$slot->getId()])) {
            return PeriodPlanTranscriber::SKIP_TEAM_REDUCED;
        }
        if (isset($disabledVenueIds[$slot->getVenueId()])) {
            return PeriodPlanTranscriber::SKIP_VENUE_DISABLED;
        }
        if (isset($closedWeekdaysByVenue[$slot->getVenueId()][$slot->getDayOfWeek()])) {
            return PeriodPlanTranscriber::SKIP_VENUE_CLOSED;
        }

        return null;
    }

    /**
     * Groupe les créneaux par équipe, en EXCLUANT les équipes désactivées pour la période (miroir
     * de {@see PeriodPlanTranscriber} : une équipe en pause n'a aucun écart).
     *
     * @param list<ScheduleSlotTemplate> $slots
     * @param array<string, true>        $deactivatedTeamIds
     *
     * @return array<string, list<ScheduleSlotTemplate>>
     */
    private function groupByTeam(array $slots, array $deactivatedTeamIds): array
    {
        $byTeam = [];
        foreach ($slots as $slot) {
            if (isset($deactivatedTeamIds[$slot->getTeamId()])) {
                continue;
            }
            $byTeam[$slot->getTeamId()][] = $slot;
        }

        return $byTeam;
    }

    /**
     * Les ids des séances du socle RETIRÉES par la réduction de séances — RÉPLIQUE littérale de
     * {@see PeriodPlanTranscriber::slotsRemovedByReduction} (le transcriber n'est PAS refactoré) :
     * les DERNIÈRES de la semaine (tri jour puis heure DÉCROISSANTS), plafonné à count(socle) −
     * override. Le diff redit ce que la transcription a annoncé.
     *
     * @param list<ScheduleSlotTemplate> $socleSlots
     * @param array<string, true>        $deactivatedTeamIds
     * @param array<string, int>         $sessionOverrides
     *
     * @return array<string, true>
     */
    private function slotsRemovedByReduction(array $socleSlots, array $deactivatedTeamIds, array $sessionOverrides): array
    {
        $byTeam = [];
        foreach ($socleSlots as $slot) {
            $teamId = $slot->getTeamId();
            if (isset($deactivatedTeamIds[$teamId]) || !isset($sessionOverrides[$teamId])) {
                continue;
            }
            $byTeam[$teamId][] = $slot;
        }

        $removed = [];
        foreach ($byTeam as $teamId => $slots) {
            $removeCount = \count($slots) - $sessionOverrides[$teamId];
            if ($removeCount <= 0) {
                continue;
            }
            usort($slots, static fn (ScheduleSlotTemplate $a, ScheduleSlotTemplate $b): int => [$b->getDayOfWeek(), $b->getStartTime()->format('H:i')] <=> [$a->getDayOfWeek(), $a->getStartTime()->format('H:i')]);
            foreach (\array_slice($slots, 0, $removeCount) as $slot) {
                $removed[$slot->getId()] = true;
            }
        }

        return $removed;
    }

    /** Tri (jour, heure, gymnase, id) CROISSANTS — l'ordre de la semaine qui fixe l'appariement. */
    private function chronological(ScheduleSlotTemplate $a, ScheduleSlotTemplate $b): int
    {
        return [$a->getDayOfWeek(), $a->getStartTime()->format('H:i'), $a->getVenueId(), $a->getId()]
            <=> [$b->getDayOfWeek(), $b->getStartTime()->format('H:i'), $b->getVenueId(), $b->getId()];
    }

    /** L'identité de PLACEMENT au sein d'une équipe : gymnase:jour:HH:MM (ni durée ni coach). */
    private function placementKey(ScheduleSlotTemplate $slot): string
    {
        return \sprintf('%s:%d:%s', $slot->getVenueId(), $slot->getDayOfWeek(), $slot->getStartTime()->format('H:i'));
    }

    /**
     * @return array{dayOfWeek: int, startTime: string, venueId: string}
     */
    private function placement(ScheduleSlotTemplate $slot): array
    {
        return [
            'dayOfWeek' => $slot->getDayOfWeek(),
            'startTime' => $slot->getStartTime()->format('H:i'),
            'venueId' => $slot->getVenueId(),
        ];
    }
}
