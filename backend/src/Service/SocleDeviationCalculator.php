<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\PriorityTier;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
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
 *   4. le reliquat côté période → séances NOUVELLES, jamais listées (décision fondateur : deux
 *      catégories de détail seulement) — comptées dans le bilan et servies dans `changedSlotIds`.
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
 * ── Le BILAN par équipe et par rang : une seule source, les lignes ci-dessus ─────────────────────
 * Par équipe : `keptSessions` = socle − |non replacées| (appariées exactes + déplacées) ;
 * `reducedByChoice` = non replacées `team_reduced` ; `lost` = les autres non replacées (raison
 * fermeture, gymnase désactivé ou NULL) ; `newSessions` = le reliquat côté période ; l'override de
 * séances n'est lu que pour le TEXTE (`chosenSessions`), jamais pour compter. Une équipe en pause est
 * dans le bilan (`paused`, jamais `changed`), lue AVANT l'exclusion. `daysKept` = FALSE dès
 * qu'une séance déplacée change de jour, TRUE sinon, NULL en pause ou sans séance tenue ni déplacée
 * (nouvelles et non replacées n'y entrent pas). Rangs :
 * sommes HORS pauses, triés poids décroissant puis libellé ; équipes triées rang, tierOrder, nom, id.
 *
 * ── « Commune avec » : la règle du radar/doléances, jamais `sharedTrainingBlockId` ─────────────────
 * Une séance S est commune avec T' quand une AUTRE séance de la MÊME version, d'une AUTRE équipe T',
 * tient la même clé de placement ET que les deux équipes sont dans un bloc DÉCLARÉ du périmètre
 * ({@see CoachDoubleBookingDetector::declaredGroups}, filtre club + saison + plan ;
 * {@see CoachDoubleBookingDetector::sameGroup}) — règle de `CoachDoubleBookingDetector`
 * (holdCandidates). Socle : blocs de saison, toutes les séances (une perdue commune avec une équipe
 * en pause la nomme) ; période : blocs du plan, partenaires en pause EXCLUS. Deux équipes co-placées
 * sans bloc ne sont jamais communes. Les blocs lus sont ceux d'AUJOURD'HUI (même limite que le
 * radar). Asymétrie assumée : la ligne d'une équipe compte SES séances tenues communes (`sharedKept`),
 * une séance restée en place dont le partenaire est parti ne porte rien (la ligne du partenaire le
 * dit). Aucune mention « plus commune » : l'appariement positionnel la rendrait arbitraire.
 *
 * LECTURE PURE : aucun persist, aucun flush.
 */
final readonly class SocleDeviationCalculator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PeriodConstraintSelector $periodConstraintSelector,
        private CoachDoubleBookingDetector $coachDoubleBookingDetector,
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

        // « Commune avec » : socle = blocs de saison, toutes les séances ; période = blocs du plan,
        // partenaires en pause exclus.
        $socleSharedWith = $this->sharedPartners($socleSlots, $this->coachDoubleBookingDetector->declaredGroups($clubId, $seasonId, null), []);
        $periodSharedWith = $this->sharedPartners($periodSlots, $this->coachDoubleBookingDetector->declaredGroups($clubId, $seasonId, $schedulePlanId), $deactivatedTeamIds);

        $socleByTeam = $this->groupByTeam($socleSlots, $deactivatedTeamIds);
        $periodByTeam = $this->groupByTeam($periodSlots, $deactivatedTeamIds);

        $teamIds = array_keys($socleByTeam + $periodByTeam);
        sort($teamIds); // sortie déterministe

        $moved = [];
        $unplaced = [];
        $changedSlotIds = [];
        /** @var array<string, array{socle: int, kept: int, lost: int, reduced: int, moved: int, new: int, daysKept: bool|null, sharedKept: int, sharedWith: list<string>}> $countsByTeam */
        $countsByTeam = [];
        foreach ($teamIds as $teamId) {
            $teamId = (string) $teamId;
            $socleForTeam = $socleByTeam[$teamId] ?? [];
            $periodForTeam = $periodByTeam[$teamId] ?? [];
            /** @var list<ScheduleSlotTemplate> $keptPeriodSlots séances de période tenues (appariées exactes) ou déplacées */
            $keptPeriodSlots = [];

            // (1) retirer les correspondances EXACTES de clé (multiset) → inchangées.
            $periodByKey = [];
            foreach ($periodForTeam as $slot) {
                $periodByKey[$this->placementKey($slot)][] = $slot;
            }
            $socleRemaining = [];
            foreach ($socleForTeam as $slot) {
                $key = $this->placementKey($slot);
                if (isset($periodByKey[$key]) && [] !== $periodByKey[$key]) {
                    $keptPeriodSlots[] = array_shift($periodByKey[$key]); // une occurrence appariée → inchangée

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
            /** @var list<array{0: ScheduleSlotTemplate, 1: ScheduleSlotTemplate}> $movedPairs (socle, période) */
            $movedPairs = [];
            for ($i = 0; $i < $pairs; ++$i) {
                $to = $periodRemaining[$i];
                $movedPairs[] = [$socleRemaining[$i], $to];
                $moved[] = [
                    'teamId' => $teamId,
                    // `from` = le socle, non affiché par la grille (pas de slotId). `to` = la
                    // séance de PÉRIODE que la grille rend : elle porte son `slotId` (l'id du
                    // ScheduleSlotTemplate de la période, ce que la carte expose en `cell.slotId`)
                    // pour que le front sache QUELLE carte marquer.
                    'from' => [...$this->placement($socleRemaining[$i]), 'sharedWith' => $socleSharedWith[$socleRemaining[$i]->getId()] ?? []],
                    'to' => [...$this->placement($to), 'slotId' => $to->getId(), 'sharedWith' => $periodSharedWith[$to->getId()] ?? []],
                ];
                $keptPeriodSlots[] = $to;
                $changedSlotIds[] = $to->getId();
            }

            // (3) reliquat côté socle → non replacées, avec la raison de la sélection.
            $lost = 0;
            $reduced = 0;
            foreach (\array_slice($socleRemaining, $pairs) as $slot) {
                $reason = $this->reasonFor($slot, $reducedAwaySlotIds, $disabledVenueIds, $closedWeekdaysByVenue);
                $unplaced[] = [
                    'teamId' => $teamId,
                    'dayOfWeek' => $slot->getDayOfWeek(),
                    'startTime' => $slot->getStartTime()->format('H:i'),
                    'venueId' => $slot->getVenueId(),
                    'reason' => $reason,
                    'sharedWith' => $socleSharedWith[$slot->getId()] ?? [],
                ];
                if (PeriodPlanTranscriber::SKIP_TEAM_REDUCED === $reason) {
                    ++$reduced;
                } else {
                    ++$lost;
                }
            }

            // (4) reliquat côté période = séances NOUVELLES : jamais listées, comptées et filtrables.
            $newSlots = \array_slice($periodRemaining, $pairs);
            foreach ($newSlots as $slot) {
                $changedSlotIds[] = $slot->getId();
            }

            $sharedKept = 0;
            $sharedWith = [];
            foreach ($keptPeriodSlots as $slot) {
                $partners = $periodSharedWith[$slot->getId()] ?? [];
                if ([] !== $partners) {
                    ++$sharedKept;
                    $sharedWith = [...$sharedWith, ...$partners];
                }
            }
            $sharedWith = array_values(array_unique($sharedWith));
            sort($sharedWith);

            $countsByTeam[$teamId] = [
                'socle' => \count($socleForTeam),
                'kept' => \count($socleForTeam) - $lost - $reduced,
                'lost' => $lost,
                'reduced' => $reduced,
                'moved' => $pairs,
                'new' => \count($newSlots),
                'daysKept' => $this->daysKept($keptPeriodSlots, $movedPairs),
                'sharedKept' => $sharedKept,
                'sharedWith' => $sharedWith,
            ];
        }

        [$teams, $ranks] = $this->report($socleSlots, $periodSlots, $deactivatedTeamIds, $selection->sessionOverrides, $countsByTeam);

        return new SocleDeviationResult(
            $chosenSocleId,
            ['startDate' => $entry->getStartDate()->format('Y-m-d'), 'endDate' => $entry->getEndDate()->format('Y-m-d')],
            $moved,
            $unplaced,
            $changedSlotIds,
            $teams,
            $ranks,
        );
    }

    /**
     * Les lignes d'équipe puis de rang. Équipes = celles qui ont au moins une séance d'un côté
     * (pauses comprises, lues avant l'exclusion) ; rangs : sommes HORS pauses.
     *
     * @param list<ScheduleSlotTemplate>                                                                                                                                 $socleSlots
     * @param list<ScheduleSlotTemplate>                                                                                                                                 $periodSlots
     * @param array<string, true>                                                                                                                                        $deactivatedTeamIds
     * @param array<string, int>                                                                                                                                         $sessionOverrides
     * @param array<string, array{socle: int, kept: int, lost: int, reduced: int, moved: int, new: int, daysKept: bool|null, sharedKept: int, sharedWith: list<string>}> $countsByTeam
     *
     * @return array{0: list<array{teamId: string, tierLabel: string, tierWeight: int, tierOrder: int, paused: bool, socleSessions: int, keptSessions: int, lost: int, reducedByChoice: int, movedSessions: int, newSessions: int, chosenSessions: int|null, daysKept: bool|null, changed: bool, sharedKept: int, sharedWith: list<string>}>, 1: list<array{tierLabel: string, tierWeight: int, teams: int, socleSessions: int, keptSessions: int, lost: int, chosenReductions: int, newSessions: int, paused: int, unchanged: int}>}
     */
    private function report(array $socleSlots, array $periodSlots, array $deactivatedTeamIds, array $sessionOverrides, array $countsByTeam): array
    {
        $socleCountByTeam = [];
        foreach ($socleSlots as $slot) {
            $socleCountByTeam[$slot->getTeamId()] = ($socleCountByTeam[$slot->getTeamId()] ?? 0) + 1;
        }
        $allTeamIds = [];
        foreach ([...$socleSlots, ...$periodSlots] as $slot) {
            $allTeamIds[$slot->getTeamId()] = true;
        }
        $allTeamIds = array_map(strval(...), array_keys($allTeamIds));

        $teamsById = [];
        if ([] !== $allTeamIds) {
            foreach ($this->entityManager->getRepository(Team::class)->findBy(['id' => $allTeamIds]) as $team) {
                $teamsById[$team->getId()] = $team;
            }
        }
        $tiers = [];
        foreach ($this->entityManager->getRepository(PriorityTier::class)->findAll() as $tier) {
            $tiers[$tier->getId()] = $tier;
        }

        $rows = [];
        foreach ($allTeamIds as $teamId) {
            $team = $teamsById[$teamId] ?? null;
            $tier = $team instanceof Team ? ($tiers[$team->getPriorityTierId()] ?? null) : null;
            $paused = isset($deactivatedTeamIds[$teamId]);
            $counts = $countsByTeam[$teamId] ?? null;
            $rows[] = [
                'sortName' => $team instanceof Team ? $team->getName() : '',
                'tierKey' => $tier instanceof PriorityTier ? (string) $tier->getId() : '',
                'report' => [
                    'teamId' => $teamId,
                    'tierLabel' => $tier instanceof PriorityTier ? $tier->getLabel() : '',
                    'tierWeight' => $tier instanceof PriorityTier ? $tier->getOrToolsWeight() : 0,
                    'tierOrder' => $team instanceof Team ? $team->getTierOrder() : 0,
                    'paused' => $paused,
                    'socleSessions' => $paused ? ($socleCountByTeam[$teamId] ?? 0) : ($counts['socle'] ?? 0),
                    'keptSessions' => $paused ? 0 : ($counts['kept'] ?? 0),
                    'lost' => $paused ? 0 : ($counts['lost'] ?? 0),
                    'reducedByChoice' => $paused ? 0 : ($counts['reduced'] ?? 0),
                    'movedSessions' => $paused ? 0 : ($counts['moved'] ?? 0),
                    'newSessions' => $paused ? 0 : ($counts['new'] ?? 0),
                    'chosenSessions' => $paused ? null : ($sessionOverrides[$teamId] ?? null),
                    'daysKept' => $paused ? null : ($counts['daysKept'] ?? null),
                    'changed' => !$paused && null !== $counts && ($counts['lost'] > 0 || $counts['moved'] > 0 || $counts['new'] > 0 || $counts['reduced'] > 0),
                    'sharedKept' => $paused ? 0 : ($counts['sharedKept'] ?? 0),
                    'sharedWith' => $paused ? [] : ($counts['sharedWith'] ?? []),
                ],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['report']['tierWeight'], $a['report']['tierLabel'], $a['report']['tierOrder'], $a['sortName'], $a['report']['teamId']]
            <=> [$a['report']['tierWeight'], $b['report']['tierLabel'], $b['report']['tierOrder'], $b['sortName'], $b['report']['teamId']]);

        $teams = [];
        $ranksByKey = [];
        foreach ($rows as $row) {
            $report = $row['report'];
            $teams[] = $report;
            $key = $row['tierKey'];
            $rank = $ranksByKey[$key] ?? [
                'tierLabel' => $report['tierLabel'],
                'tierWeight' => $report['tierWeight'],
                'teams' => 0,
                'socleSessions' => 0,
                'keptSessions' => 0,
                'lost' => 0,
                'chosenReductions' => 0,
                'newSessions' => 0,
                'paused' => 0,
                'unchanged' => 0,
            ];
            ++$rank['teams'];
            if ($report['paused']) {
                ++$rank['paused'];
            } else {
                $rank['socleSessions'] += $report['socleSessions'];
                $rank['keptSessions'] += $report['keptSessions'];
                $rank['lost'] += $report['lost'];
                $rank['chosenReductions'] += $report['reducedByChoice'];
                $rank['newSessions'] += $report['newSessions'];
                if (!$report['changed']) {
                    ++$rank['unchanged'];
                }
            }
            $ranksByKey[$key] = $rank;
        }

        // Les lignes sont déjà triées rang par rang : l'ordre d'apparition EST l'ordre des rangs.
        return [$teams, array_values($ranksByKey)];
    }

    /**
     * L'équipe garde-t-elle ses jours ? FALSE dès qu'une séance DÉPLACÉE change de jour (`from` et
     * `to` sur deux jours différents), TRUE sinon. NULL si l'équipe n'a aucune séance tenue ni
     * déplacée. Les nouvelles et les non replacées n'entrent pas dans le calcul.
     *
     * @param list<ScheduleSlotTemplate>                                    $keptPeriodSlots
     * @param list<array{0: ScheduleSlotTemplate, 1: ScheduleSlotTemplate}> $movedPairs      (socle, période)
     */
    private function daysKept(array $keptPeriodSlots, array $movedPairs): ?bool
    {
        if ([] === $keptPeriodSlots) {
            return null;
        }
        foreach ($movedPairs as [$from, $to]) {
            if ($from->getDayOfWeek() !== $to->getDayOfWeek()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Les partenaires de chaque séance d'une version : les AUTRES équipes (hors `$excludedTeamIds`)
     * dont une AUTRE séance tient la même clé de placement ET qui partagent un bloc déclaré avec
     * l'équipe de la séance. Ids triés, dédoublonnés ; une séance sans partenaire est absente.
     *
     * @param list<ScheduleSlotTemplate> $slots
     * @param list<list<string>>         $groups
     * @param array<string, true>        $excludedTeamIds
     *
     * @return array<string, list<string>> slotId → ids d'équipes partenaires
     */
    private function sharedPartners(array $slots, array $groups, array $excludedTeamIds): array
    {
        if ([] === $groups) {
            return [];
        }
        $byKey = [];
        foreach ($slots as $slot) {
            $byKey[$this->placementKey($slot)][] = $slot;
        }
        $partners = [];
        foreach ($byKey as $sameCase) {
            foreach ($sameCase as $slot) {
                $found = [];
                foreach ($sameCase as $other) {
                    $otherTeam = $other->getTeamId();
                    if ($other->getId() === $slot->getId() || $otherTeam === $slot->getTeamId() || isset($excludedTeamIds[$otherTeam])) {
                        continue;
                    }
                    if (CoachDoubleBookingDetector::sameGroup($slot->getTeamId(), $otherTeam, $groups)) {
                        $found[$otherTeam] = true;
                    }
                }
                if ([] !== $found) {
                    $ids = array_map(strval(...), array_keys($found));
                    sort($ids);
                    $partners[$slot->getId()] = $ids;
                }
            }
        }

        return $partners;
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
