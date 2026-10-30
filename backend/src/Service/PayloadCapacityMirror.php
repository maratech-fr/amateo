<?php

declare(strict_types=1);

namespace App\Service;

/**
 * P3-19 — LE MIROIR de l'algèbre de créneaux du moteur, en un seul endroit.
 *
 * Le récap annonce capacité et saturations en lisant le payload que le solveur
 * recevra. Pour approcher ce que le moteur peut RÉELLEMENT placer, il réplique
 * une partie de son algèbre — deux copies de la même logique, une en Python,
 * une ici. Ce fichier est la copie PHP, extraite de `ValidateConstraintsController`
 * pour être testable en tant que telle : `CapacityMirrorParityTest` (cross-stack)
 * envoie les mêmes payloads au VRAI moteur et vérifie que les deux copies rendent
 * le même verdict — le jour où l'algèbre moteur change, ce test rougit au lieu de
 * laisser le récap mentir en silence.
 *
 * ⚠ HISTOIRE, à lire avant d'y toucher (revue #341, deux rounds). La version
 * d'origine sommait les capacités brutes. La revue a montré trois écarts avec le
 * moteur ; les « corrections » en répliquant son algèbre complète en ont créé
 * QUATRE autres — dont deux qui SOUS-estimaient l'offre, produisant de fausses
 * alertes sur la branche dont le contrat est de ne jamais mentir. Conclusion :
 * ne répliquer QUE les réductions dont on prouve qu'elles laissent l'offre
 * AU-DESSUS du réel :
 *
 * 1. **déduplication** par `(gymnase, jour, heure)` — `model.slot_capacities` est
 *    un dict sur ce triplet (`model.py:54-56`) : deux lignes au même triplet
 *    s'écrasent, elles ne s'additionnent pas ;
 * (Une seconde règle existait — le rabot `FACILITY_CAPACITY`, `min(capacité,
 * maxTeams)` par gymnase. La famille a été RETIRÉE le 2026-08-08 : aucun chemin
 * UI ne la créait, zéro ligne en base. La capacité se règle par CRÉNEAU
 * (`VenueTrainingSlot.capacity`), qui est le geste réel du gestionnaire.)
 *
 * Ce qui n'est délibérément PAS répliqué : les verrous HARD (le moteur y émet un
 * créneau par équipe épinglée, décline la durée en pas de 15 min, place même hors
 * grille). Ne rien faire est SÛR pour l'OFFRE : un créneau verrouillé compte sa
 * capacité pleine, donc ≥ ce que le solveur y placera. On sous-avertit, on ne
 * ment pas. La sortie définitive de ce miroir reste un calcul CÔTÉ MOTEUR.
 */
final class PayloadCapacityMirror
{
    /**
     * L'offre du payload — un MAJORANT de ce que le solveur peut réellement placer.
     *
     * @param array<string, mixed> $payload
     */
    public function offer(array $payload): int
    {
        $byKey = [];
        foreach (\is_array($payload['venues'] ?? null) ? $payload['venues'] : [] as $venue) {
            $venueId = (string) ($venue['id'] ?? '');
            foreach (\is_array($venue['trainingSlots'] ?? null) ? $venue['trainingSlots'] : [] as $slot) {
                $capacity = (int) ($slot['capacity'] ?? 0);
                // Écrasement, pas addition : le moteur clé un DICT sur ce triplet.
                $byKey[$this->key($venueId, $slot['dayOfWeek'] ?? '', (string) ($slot['startTime'] ?? ''))] = $capacity;
            }
        }

        return array_sum($byKey);
    }

    /**
     * La DEMANDE de places du payload — Σ `sessionsPerWeek` des équipes, MOINS le repli des
     * blocs de mutualisation : une séance de bloc réunit N membres sur UNE place de gymnase, donc
     * (n_membres − 1) × `commonSessions` sortent de la demande par bloc (plancher 0). MIROIR
     * LITTÉRAL de la branche moteur (`engine/app/solver/result_builder/diagnostics.py`,
     * `_infeasible_message`), gardé par {@see App\Tests\CrossStack\CapacityMirrorParityTest}. Un
     * bloc de moins de 2 membres ou à `commonSessions` nul ne replie rien (chemin inchangé).
     *
     * @param array<string, mixed> $payload
     */
    public function demand(array $payload): int
    {
        $demand = 0;
        foreach (\is_array($payload['teams'] ?? null) ? $payload['teams'] : [] as $team) {
            $demand += (int) (\is_array($team) ? ($team['sessionsPerWeek'] ?? 0) : 0);
        }

        foreach (\is_array($payload['sharedBlocks'] ?? null) ? $payload['sharedBlocks'] : [] as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $memberIds = \is_array($block['teamIds'] ?? null) ? $block['teamIds'] : [];
            $commonSessions = (int) ($block['commonSessions'] ?? 0);
            if (\count($memberIds) >= 2 && $commonSessions > 0) {
                $demand -= (\count($memberIds) - 1) * $commonSessions;
            }
        }

        return max(0, $demand);
    }

    /**
     * Les gymnases dont les « au moins » dépassent la grille MÊME SANS aucun verrou HARD : aucune
     * réservation n'est en cause, le moteur échouera (miroir de sa sonde de causalité).
     *
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    public function venuesSaturatedWithoutLocks(array $payload): array
    {
        $templates = \is_array($payload['slotTemplates'] ?? null) ? $payload['slotTemplates'] : [];
        $payload['slotTemplates'] = array_values(array_filter(
            $templates,
            static fn (mixed $pin): bool => !\is_array($pin) || 'HARD' !== ($pin['lockLevel'] ?? null),
        ));

        return array_column($this->venueMinimumSaturation($payload), 'venueId');
    }

    /**
     * Saturation des « au moins N ici » PAR GYMNASE — miroir dans le sens SÛR :
     *
     * - la DEMANDE est le Σ des minimums (par équipe×gymnase, le MAX quand plusieurs
     *   règles se cumulent — le moteur pose un `>=` par règle, le max domine), RÉDUITE
     *   pour chaque équipe des pins HARD de CETTE équipe à CE gymnase, comptés en JOURS
     *   DISTINCTS, plancher 0 (P4-97). Un créneau verrouillé n'a pas de variable
     *   (`model.py`) mais il HONORE le plancher : le moteur crédite désormais
     *   `effective_min = minimum - jours_verrouillés` (`add_venue_minimum_constraints`).
     *   L'ancien « un pin n'en soustrait RIEN » documentait le bug : le récap déclarait
     *   « 0 places libres » sur une équipe déjà servie par ses réservations (SM2 épinglé
     *   à Matéo) et bloquait la génération pour de faux ;
     * - l'OFFRE LIBRE : capacités dédupliquées, MOINS les
     *   triplets verrouillés par un pin HARD — un triplet bloqué ne porte aucune
     *   variable, pour personne. Inchangée.
     *
     * `demande > libre` : une séance ne pourra pas être placée. C'est un INFEASIBLE certain seulement
     * si la grille dépasse même sans verrou ({@see venuesSaturatedWithoutLocks}, décision fondateur
     * 2026-10-30) ; sinon le moteur produit le planning et nomme l'équipe privée de case. Épinglé par
     * le test de parité.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<array{venueId: string, venueName: string, demand: int, free: int}>
     */
    public function venueMinimumSaturation(array $payload): array
    {
        /** @var array<string, array<string, int>> $minByVenueTeam venueId => teamId => min */
        $minByVenueTeam = [];
        foreach (\is_array($payload['constraints'] ?? null) ? $payload['constraints'] : [] as $row) {
            if (!\is_array($row) || 'FACILITY' !== ($row['family'] ?? null) || false === ($row['isActive'] ?? true)) {
                continue;
            }
            $config = \is_array($row['config'] ?? null) ? $row['config'] : [];
            // Mêmes gardes que la branche moteur (`constraints.py`) : HARD, scope
            // TEAM avec cible — ce que le validateur laisse passer d'autre est ignoré là-bas.
            if (empty($config['minAtVenueId'])
                || ($row['ruleType'] ?? null) !== 'HARD'
                || 'TEAM' !== ($row['scope'] ?? null)
                || empty($row['scopeTargetId'])
            ) {
                continue;
            }
            $venueId = (string) $config['minAtVenueId'];
            $teamId = (string) $row['scopeTargetId'];
            $min = max(1, isset($config['minAtVenueCount']) ? (int) $config['minAtVenueCount'] : 1);
            $minByVenueTeam[$venueId][$teamId] = max($minByVenueTeam[$venueId][$teamId] ?? 0, $min);
        }

        if ([] === $minByVenueTeam) {
            return [];
        }

        // Triplets fermés par un verrou HARD (réservations + slots épinglés), ET les JOURS
        // DISTINCTS verrouillés par (gymnase, équipe) — ces derniers créditent la demande
        // (P4-97 : un pin honore le plancher, comme `effective_min` côté moteur).
        $pinnedKeys = [];
        /** @var array<string, array<string, array<int|string, true>>> $lockedDaysByVenueTeam venueId => teamId => day => true */
        $lockedDaysByVenueTeam = [];
        foreach (\is_array($payload['slotTemplates'] ?? null) ? $payload['slotTemplates'] : [] as $pin) {
            if (!\is_array($pin) || 'HARD' !== ($pin['lockLevel'] ?? null)) {
                continue;
            }
            $pinVenueId = (string) ($pin['venueId'] ?? '');
            $pinnedKeys[$this->key($pinVenueId, $pin['dayOfWeek'] ?? '', (string) ($pin['startTime'] ?? ''))] = true;
            $pinTeamId = (string) ($pin['teamId'] ?? '');
            $pinDay = $pin['dayOfWeek'] ?? null;
            if ('' !== $pinTeamId && null !== $pinDay) {
                $lockedDaysByVenueTeam[$pinVenueId][$pinTeamId][$pinDay] = true;
            }
        }

        /** @var array<string, array<string, int>> $freeByVenue venueId => triplet => capacité libre */
        $freeByVenue = [];
        $venueNames = [];
        foreach (\is_array($payload['venues'] ?? null) ? $payload['venues'] : [] as $venue) {
            if (!\is_array($venue)) {
                continue;
            }
            $venueId = (string) ($venue['id'] ?? '');
            $venueNames[$venueId] = (string) ($venue['name'] ?? $venueId);
            if (!isset($minByVenueTeam[$venueId])) {
                continue;
            }
            foreach (\is_array($venue['trainingSlots'] ?? null) ? $venue['trainingSlots'] : [] as $slot) {
                if (!\is_array($slot)) {
                    continue;
                }
                $key = $this->key($venueId, $slot['dayOfWeek'] ?? '', (string) ($slot['startTime'] ?? ''));
                if (isset($pinnedKeys[$key])) {
                    continue;
                }
                $capacity = (int) ($slot['capacity'] ?? 0);
                $freeByVenue[$venueId][$key] = $capacity;
            }
        }

        $saturated = [];
        foreach ($minByVenueTeam as $venueId => $minByTeam) {
            $demand = 0;
            foreach ($minByTeam as $teamId => $min) {
                $lockedDays = \count($lockedDaysByVenueTeam[$venueId][$teamId] ?? []);
                $demand += max(0, $min - $lockedDays);
            }
            $free = array_sum($freeByVenue[$venueId] ?? []);
            if ($demand > $free) {
                $saturated[] = ['venueId' => $venueId, 'venueName' => $venueNames[$venueId] ?? $venueId, 'demand' => $demand, 'free' => $free];
            }
        }

        return $saturated;
    }

    /**
     * Les triplets de la grille servie — la clé que partagent l'offre, la saturation
     * et la détection d'orphelines (une réservation hors de cet ensemble n'a pas de
     * variable côté moteur… qui la PLACE quand même, mesuré : d'où le bloqueur).
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, true>
     */
    public function gridKeys(array $payload): array
    {
        $grid = [];
        foreach (\is_array($payload['venues'] ?? null) ? $payload['venues'] : [] as $venue) {
            if (!\is_array($venue)) {
                continue;
            }
            $venueId = (string) ($venue['id'] ?? '');
            foreach (\is_array($venue['trainingSlots'] ?? null) ? $venue['trainingSlots'] : [] as $slot) {
                if (\is_array($slot)) {
                    $grid[$this->key($venueId, $slot['dayOfWeek'] ?? '', (string) ($slot['startTime'] ?? ''))] = true;
                }
            }
        }

        return $grid;
    }

    /**
     * RÉSUMÉ DE GRILLE d'un snapshot figé (D17, PR B « Signaler ») — joint au signalement d'un
     * échec de génération pour que le support voie la FORME de la grille sans ouvrir le planning.
     * Fonction PURE, aucune dépendance : elle lit le payload tel quel.
     *
     * Elle tolère les DEUX conventions de clés : celle du snapshot moteur réel
     * (`venues[].trainingSlots`, pins HARD dans `slotTemplates` via `lockLevel`) et la forme
     * abrégée (`venues[].slots`, `reservations` avec `pinned`) — le résumé reste juste sur l'entrée
     * RÉELLE de {@see App\Entity\Schedule::engineInput()} comme sur une grille déjà aplatie.
     *
     * - `slots` : créneaux ouverts (toutes grilles de gymnase) ;
     * - `reservations` : réservations / pins (team→slot) ;
     * - `pins` : parmi elles, les VERROUILLÉES (`pinned:true` ou `lockLevel:HARD`) ;
     * - `blocks` : blocs de mutualisation ;
     * - `lockedMinutes` : Σ des durées des réservations verrouillées.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{slots: int, reservations: int, pins: int, blocks: int, lockedMinutes: int}
     */
    public function gridSummary(array $payload): array
    {
        $slots = 0;
        foreach (\is_array($payload['venues'] ?? null) ? $payload['venues'] : [] as $venue) {
            if (!\is_array($venue)) {
                continue;
            }
            $venueSlots = $venue['slots'] ?? $venue['trainingSlots'] ?? null;
            $slots += \is_array($venueSlots) ? \count($venueSlots) : 0;
        }

        // Réservations/pins : la forme abrégée (`reservations`) sinon le bloc moteur (`slotTemplates`).
        $reservations = \is_array($payload['reservations'] ?? null)
            ? $payload['reservations']
            : (\is_array($payload['slotTemplates'] ?? null) ? $payload['slotTemplates'] : []);

        $pins = 0;
        $lockedMinutes = 0;
        foreach ($reservations as $reservation) {
            if (!\is_array($reservation)) {
                continue;
            }
            if (true === ($reservation['pinned'] ?? null) || 'HARD' === ($reservation['lockLevel'] ?? null)) {
                ++$pins;
                $lockedMinutes += (int) ($reservation['durationMinutes'] ?? 0);
            }
        }

        return [
            'slots' => $slots,
            'reservations' => \count($reservations),
            'pins' => $pins,
            'blocks' => \is_array($payload['sharedBlocks'] ?? null) ? \count($payload['sharedBlocks']) : 0,
            'lockedMinutes' => $lockedMinutes,
        ];
    }

    public function key(string $venueId, mixed $dayOfWeek, string $startTime): string
    {
        return \sprintf('%s|%s|%s', $venueId, $dayOfWeek, substr($startTime, 0, 5));
    }
}
