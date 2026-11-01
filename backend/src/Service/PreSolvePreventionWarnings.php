<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ConstraintFamily;
use App\Enum\ConstraintScope;

/**
 * P4-57 — les préventions qu'on peut FAIRE AVANT de générer.
 *
 * Décision fondateur du 2026-08-06, reconduite ici : « on peut s'en prémunir » plutôt que le
 * découvrir après génération. Chacun des constats ci-dessous n'existait jusqu'ici que comme
 * diagnostic POST-solve (« l'équipe n'a pas pu être placée : aucun créneau ne convient »,
 * {@see DiagnosticMessageBuilder}) — le gestionnaire attendait donc une génération complète pour
 * apprendre une chose qui se voyait dans ses données.
 *
 * ⚑ Tout se calcule depuis LE PAYLOAD, celui-là même qui part au moteur — jamais depuis une
 * lecture parallèle de la base. C'est ce qui garantit qu'un avertissement décrit ce que le
 * solveur va RÉELLEMENT recevoir : le filtre de période, les overrides et les désactivations
 * sont déjà appliqués quand on arrive ici. Le même principe que la parité gate ⇄ payload.
 *
 * Deux registres, deux méthodes :
 * - {@see detect} rend des AVERTISSEMENTS — le solveur reste l'autorité, un club peut vouloir
 *   générer en connaissance de cause.
 * - {@see detectBlockers} rend des BLOQUEURS — la génération est refusée. ALIGN-09 assume ce
 *   raffinement de la doctrine P4-57 : le gate PRÉVIENT pour un risque, mais BLOQUE pour une
 *   certitude arithmétique (« au moins une séance l'un de ces jours » quand AUCUN de ces jours
 *   n'a de créneau candidat — INFEASIBLE certain, pas un risque).
 *
 * ⚠ Subtilité de fusion ALIGN-09 : le moteur fusionne les règles DAY par équipe en UNE somme
 * agrégée sur l'UNION des jours imposés ({@see constraints.py} `add_time_window_constraints`).
 * Deux règles « au moins une séance » sur la même équipe se combinent donc en une seule exigence
 * (l'union des jours) — plus faible que l'intention probable (« une séance CHACUN de ces
 * ensembles »). Le moteur étant gelé, on ne corrige pas : {@see mergedForcedDayRules} AVERTIT du
 * malentendu (risque = avertissement, pas bloqueur).
 */
final readonly class PreSolvePreventionWarnings
{
    /** ISO-8601 : 1 = lundi (même table que {@see ValidateConstraintsController}). */
    private const array DAY_NAMES = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    /**
     * @param array<string, mixed> $payload
     * @param array<string, bool>  $constraintOrigins constraintId => héritée du planning de saison
     *                                                (calendarEntryId NULL) ; `false` = posée pour
     *                                                la période. Vide ⇒ aucune origine composée
     *                                                (la SERVABILITÉ reste calculée du payload seul).
     *
     * @return list<string>
     */
    public function detect(array $payload, array $constraintOrigins = []): array
    {
        $venues = $this->listOf($payload, 'venues');
        $teams = $this->listOf($payload, 'teams');
        $constraints = $this->listOf($payload, 'constraints');

        return [
            ...$this->venuesWithoutSlots($venues),
            ...$this->teamsNoSlotCanHost($teams, $venues, $constraints),
            ...$this->overloadedCoaches($payload, $teams, $venues, $constraints),
            ...$this->constraintsTargetingAbsentTeams($teams, $constraints),
            ...$this->mergedForcedDayRules($teams, $constraints),
            ...$this->forcedDayEmptiedByClosingRules($teams, $venues, $constraints),
            ...$this->adjointOnlyUnavailabilityIsIndicative($payload, $constraints),
            ...$this->sharedBlockWithoutCommonServableCase($payload, $venues, $teams, $constraints, $constraintOrigins),
            ...$this->daysWithoutAnyAvailableEmployee($payload, $venues, $constraints),
            ...$this->teamReservedOnOverlappingCases($payload),
        ];
    }

    /**
     * ALIGN-09 — les BLOQUEURS pré-solve calculés depuis LE PAYLOAD (même foyer, saison ET
     * période). Aujourd'hui un seul : une équipe à qui l'on impose « au moins une séance l'un
     * de ces jours » alors qu'AUCUN de ces jours n'a de créneau candidat.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    public function detectBlockers(array $payload): array
    {
        return $this->forcedDaysWithoutCandidateSlot(
            $this->listOf($payload, 'teams'),
            $this->listOf($payload, 'venues'),
            $this->listOf($payload, 'constraints'),
        );
    }

    /**
     * 2. Un gymnase déclaré auquel aucune disponibilité n'a été ajoutée.
     *
     * Presque toujours une saisie inachevée : la salle existe pour le club mais n'offre rien
     * au solveur. Silencieux jusqu'ici — elle n'apparaissait simplement nulle part.
     *
     * @param list<array<string, mixed>> $venues
     *
     * @return list<string>
     */
    private function venuesWithoutSlots(array $venues): array
    {
        $names = [];
        foreach ($venues as $venue) {
            if ([] === $this->listOf($venue, 'trainingSlots')) {
                $names[] = $this->nameOf($venue);
            }
        }

        if ([] === $names) {
            return [];
        }

        sort($names);

        return [\sprintf(
            1 === \count($names)
                ? 'Le gymnase %s n\'a aucun créneau : il ne servira pas à la génération. Ajoutez ses disponibilités, ou retirez-le.'
                : 'Ces gymnases n\'ont aucun créneau et ne serviront pas à la génération : %s. Ajoutez leurs disponibilités, ou retirez-les.',
            implode(', ', $names),
        )];
    }

    /**
     * 1. Une équipe qu'AUCUN créneau ne peut accueillir.
     *
     * Deux causes, cumulables : son gymnase IMPOSÉ n'offre rien, et/ou ses jours autorisés ne
     * croisent aucun créneau ouvert. Dans les deux cas le placement est impossible AVANT même
     * de lancer le solveur — c'est une certitude arithmétique, pas un risque.
     *
     * ⚠ On ne regarde que ce qui rend l'accueil IMPOSSIBLE, jamais improbable : un créneau
     * déjà pris par une autre équipe n'entre pas ici (c'est l'affaire du solveur, et la
     * capacité globale est déjà couverte par le récap). Un avertissement qui crie au loup
     * cesse d'être lu.
     *
     * @param list<array<string, mixed>> $teams
     * @param list<array<string, mixed>> $venues
     * @param list<array<string, mixed>> $constraints
     *
     * @return list<string>
     */
    private function teamsNoSlotCanHost(array $teams, array $venues, array $constraints): array
    {
        $slotDaysByVenue = [];
        $allDays = [];
        foreach ($venues as $venue) {
            $venueId = $this->stringOf($venue, 'id');
            foreach ($this->listOf($venue, 'trainingSlots') as $slot) {
                $day = (int) ($slot['dayOfWeek'] ?? 0);
                $slotDaysByVenue[$venueId][$day] = true;
                $allDays[$day] = true;
            }
        }

        $messages = [];
        foreach ($teams as $team) {
            $teamId = $this->stringOf($team, 'id');
            $forcedVenueId = $this->forcedVenueOf($team, $constraints);
            $reachable = '' !== $forcedVenueId ? ($slotDaysByVenue[$forcedVenueId] ?? []) : $allDays;

            if ([] === $reachable) {
                $messages[] = \sprintf(
                    '%s est imposée dans un gymnase qui n\'a aucun créneau : elle ne pourra pas être placée.',
                    $this->nameOf($team),
                );
                continue;
            }

            $allowed = $this->allowedDaysOf($teamId, $constraints);
            if (null !== $allowed && [] === array_intersect_key($reachable, array_flip($allowed))) {
                $messages[] = \sprintf(
                    '%s n\'autorise que des jours où aucun créneau ne lui est ouvert : elle ne pourra pas être placée.',
                    $this->nameOf($team),
                );
            }
        }

        sort($messages);

        return $messages;
    }

    /**
     * 3. Un coach principal dont la charge dépasse ses jours disponibles.
     *
     * La charge ne compte que les séances des équipes dont il est le SEUL principal : un adjoint
     * n'est pas requis, un co-entraîneur principal peut tenir la séance à sa place. Un jour lui
     * reste disponible s'il y garde au moins un créneau ({@see daysClosedForCoach}). Au-dessus, il
     * devra cumuler plusieurs séances le même jour — ce que le solveur sait faire, mais que le
     * gestionnaire gagne à décider plutôt qu'à subir.
     *
     * ⚠ AVERTISSEMENT et non bloqueur, précisément parce que le cumul est légal : deux séances
     * le même soir dans deux gymnases voisins peuvent très bien convenir. C'est le
     * dédoublement STRICT (même heure, deux endroits) qui bloque, et il est déjà traité
     * ailleurs (`CoachDoubleBookingDetector`).
     *
     * @param array<string, mixed>       $payload
     * @param list<array<string, mixed>> $teams
     * @param list<array<string, mixed>> $venues
     * @param list<array<string, mixed>> $constraints
     *
     * @return list<string>
     */
    private function overloadedCoaches(array $payload, array $teams, array $venues, array $constraints): array
    {
        $openDays = \count($this->slotStartsByDay($venues));
        if (0 === $openDays) {
            return []; // Aucun créneau du tout : le récap le dit déjà, mieux et plus tôt.
        }

        $mainCoaches = $this->teamMainCoaches($constraints);
        $load = [];
        foreach ($teams as $team) {
            $coaches = $mainCoaches[$this->stringOf($team, 'id')] ?? [];
            if (1 === \count($coaches)) {
                $load[$coaches[0]] = ($load[$coaches[0]] ?? 0) + (int) ($team['sessionsPerWeek'] ?? 0);
            }
        }

        $messages = [];
        foreach ($this->listOf($payload, 'coaches') as $coach) {
            $coachId = $this->stringOf($coach, 'id');
            $sessions = $load[$coachId] ?? 0;
            if (0 === $sessions) {
                continue;
            }

            $available = $openDays - \count($this->daysClosedForCoach($coachId, $venues, $constraints));
            if ($sessions > $available) {
                $messages[] = \sprintf(
                    '%s est le seul coach de %s par semaine mais n\'a %s : plusieurs séances tomberont le même jour.',
                    $this->coachName($coach),
                    1 === $sessions ? '1 séance' : $sessions . ' séances',
                    match ($available) {
                        0 => 'aucun jour disponible',
                        1 => 'qu\'un jour disponible',
                        default => 'que ' . $available . ' jours disponibles',
                    },
                );
            }
        }

        sort($messages);

        return $messages;
    }

    /**
     * Les jours ouverts dont TOUS les créneaux sont fermés à ce coach. Une plage qui commence après
     * le dernier créneau ne ferme donc pas le jour, une liste blanche de jours ferme les autres.
     *
     * @param list<array<string, mixed>> $venues
     * @param list<array<string, mixed>> $constraints
     *
     * @return list<int>
     */
    private function daysClosedForCoach(string $coachId, array $venues, array $constraints): array
    {
        $closed = [];
        foreach ($this->slotStartsByDay($venues) as $day => $starts) {
            if ($this->coachBlockedOnAllStarts($coachId, $day, $starts, $constraints)) {
                $closed[] = $day;
            }
        }

        return $closed;
    }

    /**
     * @param list<int>                  $starts
     * @param list<array<string, mixed>> $constraints
     */
    private function coachBlockedOnAllStarts(string $coachId, int $day, array $starts, array $constraints): bool
    {
        foreach ($starts as $start) {
            if (!$this->coachBlockedAt($coachId, $day, $start, $constraints)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Les minutes de début distinctes des créneaux de la grille, par jour, jours triés.
     *
     * @param list<array<string, mixed>> $venues
     *
     * @return array<int, list<int>>
     */
    private function slotStartsByDay(array $venues): array
    {
        $starts = [];
        foreach ($venues as $venue) {
            foreach ($this->listOf($venue, 'trainingSlots') as $slot) {
                $starts[(int) ($slot['dayOfWeek'] ?? 0)][$this->minutesOf($this->stringOf($slot, 'startTime'))] = true;
            }
        }
        ksort($starts);

        return array_map(array_keys(...), $starts);
    }

    /**
     * Un jour ouvré de la grille où aucun salarié ne peut venir sur aucun créneau : la règle « un
     * salarié présent chaque jour » ne pourra pas y être tenue. Même seuil (2 salariés) et mêmes
     * jours (lundi-vendredi) que le moteur. Un jour où un salarié est libre mais où rien n'est
     * réservé ne dit rien : le solveur y reste libre.
     *
     * @param array<string, mixed>       $payload
     * @param list<array<string, mixed>> $venues
     * @param list<array<string, mixed>> $constraints
     *
     * @return list<string>
     */
    private function daysWithoutAnyAvailableEmployee(array $payload, array $venues, array $constraints): array
    {
        $employees = array_values(array_filter(
            $this->listOf($payload, 'coaches'),
            static fn (array $coach): bool => true === ($coach['isEmployee'] ?? false),
        ));
        if (\count($employees) < 2) {
            return [];
        }

        $messages = [];
        foreach ($this->slotStartsByDay($venues) as $day => $starts) {
            if ($day < 1 || $day > 5) {
                continue;
            }
            foreach ($employees as $employee) {
                if (!$this->coachBlockedOnAllStarts($this->stringOf($employee, 'id'), $day, $starts, $constraints)) {
                    continue 2;
                }
            }
            $messages[] = \sprintf(
                'Aucun salarié n\'est disponible sur les créneaux du %s : %s sont indisponibles ce jour-là.',
                self::DAY_NAMES[$day],
                $this->frenchList(array_map($this->coachName(...), $employees)),
            );
        }

        return $messages;
    }

    /**
     * Une même équipe réservée deux fois sur des créneaux qui se chevauchent le même jour. Le moteur
     * produit quand même le planning (la réservation gagne) : le gestionnaire le lit avant de générer.
     * Intervalles demi-ouverts : deux séances qui s'enchaînent ne se chevauchent pas.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function teamReservedOnOverlappingCases(array $payload): array
    {
        $teamNames = [];
        foreach ($this->listOf($payload, 'teams') as $team) {
            $teamNames[$this->stringOf($team, 'id')] = $this->nameOf($team);
        }
        $venueNames = [];
        foreach ($this->listOf($payload, 'venues') as $venue) {
            $venueNames[$this->stringOf($venue, 'id')] = $this->nameOf($venue);
        }

        $reserved = [];
        foreach ($this->listOf($payload, 'slotTemplates') as $slot) {
            $teamId = $this->stringOf($slot, 'teamId');
            if ('HARD' !== ($slot['lockLevel'] ?? null) || !isset($teamNames[$teamId])) {
                continue;
            }
            $start = $this->minutesOf($this->stringOf($slot, 'startTime'));
            $reserved[$teamId][(int) ($slot['dayOfWeek'] ?? 0)][] = [
                'start' => $start,
                'end' => $start + (int) ($slot['durationMinutes'] ?? 0),
                'venueId' => $this->stringOf($slot, 'venueId'),
            ];
        }

        $messages = [];
        foreach ($reserved as $teamId => $days) {
            foreach ($days as $day => $cases) {
                usort($cases, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
                foreach ($cases as $index => $first) {
                    foreach (\array_slice($cases, $index + 1) as $second) {
                        if ($second['start'] >= $first['end'] || $first['start'] >= $second['end']) {
                            continue;
                        }
                        $firstRange = $this->clockRange($first['start'], $first['end']);
                        $secondRange = $this->clockRange($second['start'], $second['end']);
                        $firstVenue = $venueNames[$first['venueId']] ?? 'Sans nom';
                        $messages[] = \sprintf(
                            '%s est réservée deux fois le %s sur des créneaux qui se chevauchent : %s.',
                            $teamNames[$teamId],
                            self::DAY_NAMES[$day] ?? (string) $day,
                            $first['venueId'] === $second['venueId']
                                ? \sprintf('%s et %s à %s', $firstRange, $secondRange, $firstVenue)
                                : \sprintf('%s à %s et %s à %s', $firstRange, $firstVenue, $secondRange, $venueNames[$second['venueId']] ?? 'Sans nom'),
                        );
                    }
                }
            }
        }

        sort($messages);

        return $messages;
    }

    private function clockRange(int $start, int $end): string
    {
        return \sprintf('%02d:%02d-%02d:%02d', intdiv($start, 60), $start % 60, intdiv($end, 60), $end % 60);
    }

    /** @param array<string, mixed> $coach */
    private function coachName(array $coach): string
    {
        return trim($this->stringOf($coach, 'firstName') . ' ' . $this->stringOf($coach, 'lastName')) ?: 'Ce coach';
    }

    /**
     * « a et b », « a, b et c ».
     *
     * @param list<string> $items
     */
    private function frenchList(array $items): string
    {
        $last = array_pop($items);

        return [] === $items ? (string) $last : implode(', ', $items) . ' et ' . $last;
    }

    /**
     * 4. Une contrainte qui vise une équipe absente du périmètre.
     *
     * Sur un plan de période, le payload ne porte que les équipes ENGAGÉES sur la fenêtre : une
     * contrainte visant une équipe désactivée y survit sans jamais pouvoir s'appliquer. Le
     * gestionnaire croit avoir réglé quelque chose qui ne s'exercera pas.
     *
     * @param list<array<string, mixed>> $teams
     * @param list<array<string, mixed>> $constraints
     *
     * @return list<string>
     */
    private function constraintsTargetingAbsentTeams(array $teams, array $constraints): array
    {
        $present = [];
        foreach ($teams as $team) {
            $present[$this->stringOf($team, 'id')] = true;
        }

        $orphans = 0;
        foreach ($constraints as $constraint) {
            if (ConstraintScope::TEAM->value !== ($constraint['scope'] ?? null)) {
                continue;
            }
            $teamId = $this->stringOf($constraint, 'scopeTargetId');
            if ('' !== $teamId && !isset($present[$teamId])) {
                ++$orphans;
            }
        }

        if (0 === $orphans) {
            return [];
        }

        return [\sprintf(
            1 === $orphans
                ? 'Une contrainte vise une équipe absente de cette période : elle ne s\'appliquera pas.'
                : '%d contraintes visent des équipes absentes de cette période : elles ne s\'appliqueront pas.',
            $orphans,
        )];
    }

    /**
     * ALIGN-09 (a) BLOQUEUR — « au moins une séance l'un de ces jours » dont AUCUN jour imposé
     * n'a de créneau candidat pour l'équipe.
     *
     * Un jour imposé est CANDIDAT s'il porte un créneau que l'équipe peut atteindre (gymnase
     * imposé respecté), qu'il n'est pas interdit (forbiddenDays HARD), et — si une whitelist
     * `allowedDays` existe — qu'il y appartient : son complément est interdit par le moteur
     * ({@see constraints.py} ~1732), donc un jour imposé hors whitelist est zéroté, sans candidat.
     * La FUSION (union des jours imposés) rend le constat par ensemble, jamais jour par jour : le
     * bloqueur ne tombe que si AUCUN jour de l'union n'a de candidat.
     *
     * @param list<array<string, mixed>> $teams
     * @param list<array<string, mixed>> $venues
     * @param list<array<string, mixed>> $constraints
     *
     * @return list<string>
     */
    private function forcedDaysWithoutCandidateSlot(array $teams, array $venues, array $constraints): array
    {
        $slotDaysByVenue = [];
        $allDays = [];
        foreach ($venues as $venue) {
            $venueId = $this->stringOf($venue, 'id');
            foreach ($this->listOf($venue, 'trainingSlots') as $slot) {
                $day = (int) ($slot['dayOfWeek'] ?? 0);
                $slotDaysByVenue[$venueId][$day] = true;
                $allDays[$day] = true;
            }
        }

        $messages = [];
        foreach ($teams as $team) {
            $rules = $this->teamHardDayRules($this->stringOf($team, 'id'), $constraints);
            if ([] === $rules['forced']) {
                continue;
            }

            $forcedVenueId = $this->forcedVenueOf($team, $constraints);
            $reachable = '' !== $forcedVenueId ? ($slotDaysByVenue[$forcedVenueId] ?? []) : $allDays;
            $forbidden = array_flip($rules['forbidden']);
            $allowed = $rules['allowed'];

            $hasCandidate = false;
            foreach ($rules['forced'] as $day) {
                if (!isset($reachable[$day]) || isset($forbidden[$day])) {
                    continue;
                }
                if (null !== $allowed && !\in_array($day, $allowed, true)) {
                    continue;
                }
                $hasCandidate = true;
                break;
            }

            if (!$hasCandidate) {
                $messages[] = \sprintf(
                    '%s : au moins une séance exigée le(s) %s, mais aucun créneau sur aucun de ces jours — ouvrez un créneau (étape Gymnases) ou retirez la règle (étape Contraintes).',
                    $this->nameOf($team),
                    $this->dayList($rules['forced']),
                );
            }
        }

        sort($messages);

        return $messages;
    }

    /**
     * ALIGN-09 (b) AVERTISSEMENT de FUSION — deux règles « au moins une séance » sur la même
     * équipe se combinent en une seule exigence (l'union des jours), plus faible que l'intention
     * probable. Le moteur étant gelé, on ne corrige pas : on prévient du malentendu.
     *
     * @param list<array<string, mixed>> $teams
     * @param list<array<string, mixed>> $constraints
     *
     * @return list<string>
     */
    private function mergedForcedDayRules(array $teams, array $constraints): array
    {
        $messages = [];
        foreach ($teams as $team) {
            $rules = $this->teamHardDayRules($this->stringOf($team, 'id'), $constraints);
            if ($rules['forcedRuleCount'] < 2) {
                continue;
            }
            $messages[] = \sprintf(
                '%s « au moins une séance » sur %s se combinent : une seule séance sur l\'ensemble %s suffira.',
                2 === $rules['forcedRuleCount'] ? 'Deux règles' : 'Plusieurs règles',
                $this->nameOf($team),
                $this->dayList($rules['forced']),
            );
        }

        sort($messages);

        return $messages;
    }

    /**
     * ALIGN-16 — un jour imposé QUI A des créneaux candidats mais dont TOUS sont fermés par une
     * AUTRE règle HARD : l'entraîneur PRINCIPAL indisponible toute la journée (indispo plein-jour),
     * ou une fenêtre horaire HARD qu'aucun créneau du jour ne satisfait. Le moteur rend alors la
     * génération infaisable (le verrou de jour reste posé) et NOMME la cause en diagnostic ; le
     * récap l'annonce AVANT (décision fondateur 2026-10-03). Distinct de {@see forcedDaysWithoutCandidateSlot}
     * (bloqueur : AUCUN créneau sur aucun jour imposé) : ici le créneau EXISTE mais une autre règle
     * le ferme. On ne crie QUE si TOUS les jours imposés candidats sont fermés — sinon la règle
     * reste satisfiable et il n'y a rien à prévenir.
     *
     * ⚠ L'indispo d'un ADJOINT ne ferme jamais un créneau côté moteur (parsing.py exclut les
     * adjoints) : seul l'entraîneur PRINCIPAL compte ici (ALIGN-19).
     *
     * @param list<array<string, mixed>> $teams
     * @param list<array<string, mixed>> $venues
     * @param list<array<string, mixed>> $constraints
     *
     * @return list<string>
     */
    private function forcedDayEmptiedByClosingRules(array $teams, array $venues, array $constraints): array
    {
        $slotsByVenueDay = [];
        $slotsByDay = [];
        foreach ($venues as $venue) {
            $venueId = $this->stringOf($venue, 'id');
            foreach ($this->listOf($venue, 'trainingSlots') as $slot) {
                $day = (int) ($slot['dayOfWeek'] ?? 0);
                $startMin = $this->minutesOf($this->stringOf($slot, 'startTime'));
                $endMin = $startMin + (int) ($slot['durationMinutes'] ?? 0);
                $slotsByVenueDay[$venueId][$day][] = [$startMin, $endMin];
                $slotsByDay[$day][] = [$startMin, $endMin];
            }
        }

        $messages = [];
        foreach ($teams as $team) {
            $teamId = $this->stringOf($team, 'id');
            $rules = $this->teamHardDayRules($teamId, $constraints);
            if ([] === $rules['forced']) {
                continue;
            }

            $forcedVenueId = $this->forcedVenueOf($team, $constraints);
            $forbidden = array_flip($rules['forbidden']);
            $allowed = $rules['allowed'];

            // Jours imposés qui ONT un créneau candidat (sinon → bloqueur, déjà couvert ailleurs).
            $candidateDays = [];
            foreach ($rules['forced'] as $day) {
                if (isset($forbidden[$day])) {
                    continue;
                }
                if (null !== $allowed && !\in_array($day, $allowed, true)) {
                    continue;
                }
                $daySlots = '' !== $forcedVenueId ? ($slotsByVenueDay[$forcedVenueId][$day] ?? []) : ($slotsByDay[$day] ?? []);
                if ([] !== $daySlots) {
                    $candidateDays[$day] = $daySlots;
                }
            }
            if ([] === $candidateDays) {
                continue;
            }

            $windows = $this->teamHardTimeWindows($teamId, $constraints);
            $mainUnavailableDays = $this->mainCoachFullDayUnavailableDays($teamId, $constraints);

            $allEmptied = true;
            foreach ($candidateDays as $day => $daySlots) {
                $closedByCoach = isset($mainUnavailableDays[$day]);
                $closedByWindow = [] !== $windows && !$this->anySlotFitsAllWindows($daySlots, $windows);
                if (!$closedByCoach && !$closedByWindow) {
                    $allEmptied = false;
                    break;
                }
            }

            if ($allEmptied) {
                $messages[] = \sprintf(
                    '%s : au moins une séance exigée le(s) %s, mais une autre règle (indisponibilité de l\'entraîneur principal toute la journée, ou fenêtre horaire) n\'y laisse aucun créneau — la génération échouera. Levez la règle qui ferme ces jours, ou retirez l\'exigence (étape Contraintes).',
                    $this->nameOf($team),
                    $this->dayList(array_keys($candidateDays)),
                );
            }
        }

        sort($messages);

        return $messages;
    }

    /**
     * ALIGN-19 — l'indisponibilité d'un entraîneur qui n'est ADJOINT sur AUCUNE équipe (jamais
     * principal) est INDICATIVE : le moteur ne la lit pas (parsing.py exclut les adjoints), elle ne
     * bloquera jamais une séance. On le DIT au récap pour que le gestionnaire ne croie pas avoir
     * protégé un créneau qui reste ouvert. Un entraîneur principal, même sur une seule équipe, sort
     * de ce lot (son indisponibilité, elle, ferme bien).
     *
     * @param array<string, mixed>       $payload
     * @param list<array<string, mixed>> $constraints
     *
     * @return list<string>
     */
    private function adjointOnlyUnavailabilityIsIndicative(array $payload, array $constraints): array
    {
        $assistantOnly = $this->coachesNeverMain($constraints);
        if ([] === $assistantOnly) {
            return [];
        }

        $names = [];
        foreach ($this->listOf($payload, 'coaches') as $coach) {
            $coachId = $this->stringOf($coach, 'id');
            if (!isset($assistantOnly[$coachId])) {
                continue;
            }
            // Pas d'indisponibilité plein-jour déclarée → rien à clarifier pour ce coach.
            if ([] === $this->unavailableDaysOf($coachId, $constraints)) {
                continue;
            }
            $names[] = trim(($coach['firstName'] ?? '') . ' ' . ($coach['lastName'] ?? '')) ?: 'Un entraîneur';
        }

        if ([] === $names) {
            return [];
        }

        sort($names);

        return [\sprintf(
            1 === \count($names)
                ? 'L\'indisponibilité de %s (adjoint uniquement) est indicative : un adjoint indisponible ne bloque jamais une séance — seul un entraîneur principal indisponible en empêche une.'
                : 'Les indisponibilités de %s (adjoints uniquement) sont indicatives : un adjoint indisponible ne bloque jamais une séance — seul un entraîneur principal indisponible en empêche une.',
            implode(', ', $names),
        )];
    }

    /**
     * Les entraîneurs liés à AU MOINS une équipe mais jamais en PRINCIPAL (adjoints partout).
     *
     * @param list<array<string, mixed>> $constraints
     *
     * @return array<string, true>
     */
    private function coachesNeverMain(array $constraints): array
    {
        $linked = [];
        $main = [];
        foreach ($constraints as $constraint) {
            if ('TEAM_COACH' !== ($constraint['type'] ?? null)) {
                continue;
            }
            $metadata = \is_array($constraint['metadata'] ?? null) ? $constraint['metadata'] : [];
            $coachId = $this->stringOf($metadata, 'coachId');
            if ('' === $coachId) {
                continue;
            }
            $linked[$coachId] = true;
            if ('MAIN' === ($metadata['role'] ?? null)) {
                $main[$coachId] = true;
            }
        }

        return array_diff_key($linked, $main);
    }

    /**
     * Lot 3B PR B' — MIROIR DÉCLARÉ de la règle moteur « un coach suffit » (Q4B) appliquée aux
     * SÉANCES DE BLOC ({@see app/solver/constraints/structural/closures.py}
     * `_close_coachless_block_cases` via le helper UNIQUE `app/solver/constraints/availability.py`,
     * et son diagnostic jumeau `result_builder/diagnostics/shared_blocks.py`). Une séance de bloc est
     * servable sur une case dès qu'UN coach de l'UNION des coachs PRINCIPAUX de ses membres y est
     * disponible ; un bloc dont les cases servables sont STRICTEMENT moins nombreuses que ses séances
     * communes est INFEASIBLE côté moteur. On le PRÉVIENT au récap, AVANT de générer.
     *
     * ⚠ C'est une copie de l'algèbre du moteur : {@see SharedBlockPreSolveMirrorParityTest} épingle
     * la parité (warning ⇒ INFEASIBLE, pas de warning ⇒ COMPLETED) contre le VRAI moteur, exactement
     * comme {@see CapacityMirrorParityTest} pour l'algèbre de capacité. La SERVABILITÉ se calcule du
     * PAYLOAD seul (grille + indispos + sharedBlocks), comme toute prévention (P4-57) ; l'ORIGINE
     * des contraintes citées (héritée de la saison / posée pour la période) est le SEUL apport
     * externe, car le payload ne la porte pas — carte vide ⇒ on nomme la contrainte sans son origine.
     *
     * @param array<string, mixed>       $payload
     * @param list<array<string, mixed>> $venues
     * @param list<array<string, mixed>> $teams
     * @param list<array<string, mixed>> $constraints
     * @param array<string, bool>        $constraintOrigins
     *
     * @return list<string>
     */
    private function sharedBlockWithoutCommonServableCase(array $payload, array $venues, array $teams, array $constraints, array $constraintOrigins): array
    {
        $blocks = $this->listOf($payload, 'sharedBlocks');
        if ([] === $blocks) {
            return [];
        }

        // Cases candidates DISTINCTES (gymnase, jour, début) — une séance de bloc occupe UNE case ;
        // le relais de coach est indifférent au gymnase, mais chaque (gymnase, jour, début) est une
        // place à part (même grain que `Σb == commonSessions` côté moteur). Aucune case ⇒ pénurie
        // de place, déjà dite par le récap.
        $cases = [];
        foreach ($venues as $venue) {
            $venueId = $this->stringOf($venue, 'id');
            foreach ($this->listOf($venue, 'trainingSlots') as $slot) {
                $day = (int) ($slot['dayOfWeek'] ?? 0);
                $start = $this->minutesOf($this->stringOf($slot, 'startTime'));
                $cases[$venueId . '|' . $day . '|' . $start] = [$day, $start];
            }
        }
        if ([] === $cases) {
            return [];
        }
        $caseList = array_values($cases);

        $teamNames = [];
        foreach ($teams as $team) {
            $teamNames[$this->stringOf($team, 'id')] = $this->nameOf($team);
        }
        $mainCoaches = $this->teamMainCoaches($constraints);

        $messages = [];
        foreach ($blocks as $block) {
            $memberIds = array_values(array_filter(array_map(
                static fn (mixed $id): string => \is_string($id) ? $id : (string) $id,
                \is_array($block['teamIds'] ?? null) ? $block['teamIds'] : [],
            )));
            if (\count($memberIds) < 2) {
                continue;
            }
            $commonSessions = (int) ($block['commonSessions'] ?? 0);
            if ($commonSessions <= 0) {
                continue;
            }

            $union = [];
            foreach ($memberIds as $memberId) {
                foreach ($mainCoaches[$memberId] ?? [] as $coachId) {
                    $union[$coachId] = true;
                }
            }
            if ([] === $union) {
                continue; // Aucun coach requis sur le bloc : ce n'est pas un problème de coach.
            }
            $unionIds = array_keys($union);

            $servable = 0;
            foreach ($caseList as [$day, $start]) {
                foreach ($unionIds as $coachId) {
                    if (!$this->coachBlockedAt($coachId, $day, $start, $constraints)) {
                        ++$servable;
                        break;
                    }
                }
            }
            if ($servable >= $commonSessions) {
                continue; // Assez de cases à coach de l'union : le bloc est servable, rien à dire.
            }

            $names = implode(', ', array_map(static fn (string $id): string => $teamNames[$id] ?? $id, $memberIds));
            $cited = $this->citedCoachConstraints($unionIds, $caseList, $constraints, $constraintOrigins);
            $head = 0 === $servable
                ? \sprintf('Groupe %s : aucun créneau où au moins un coach du groupe n\'est disponible pour une séance commune — la génération échouera.', $names)
                : \sprintf('Groupe %s : seulement %d créneau(x) où un coach du groupe est disponible, pour %d séance(s) commune(s) — la génération échouera.', $names, $servable, $commonSessions);
            $messages[] = '' !== $cited ? $head . ' ' . $cited : $head;
        }

        sort($messages);

        return $messages;
    }

    /**
     * Les contraintes d'indisponibilité des coachs de l'union qui ferment AU MOINS une case
     * candidate, nommées avec leur ORIGINE (si connue). Dédup par libellé, ordre déterministe.
     *
     * @param list<string>                $unionIds
     * @param list<array{0: int, 1: int}> $cases
     * @param list<array<string, mixed>>  $constraints
     * @param array<string, bool>         $constraintOrigins
     */
    private function citedCoachConstraints(array $unionIds, array $cases, array $constraints, array $constraintOrigins): string
    {
        $union = array_flip($unionIds);
        $sentences = [];
        $seen = [];
        foreach ($constraints as $constraint) {
            if (false === ($constraint['isActive'] ?? true)
                || ConstraintScope::COACH->value !== ($constraint['scope'] ?? null)
                || ConstraintFamily::COACH_AVAILABILITY->value !== ($constraint['family'] ?? null)
                || !isset($union[$this->stringOf($constraint, 'scopeTargetId')])
            ) {
                continue;
            }
            $label = trim($this->stringOf($constraint, 'name'));
            if ('' === $label || isset($seen[$label])) {
                continue;
            }
            // Ne citer qu'une contrainte qui ferme RÉELLEMENT une case candidate du bloc.
            $closesACase = false;
            foreach ($cases as [$day, $start]) {
                if ($this->oneConstraintBlocksAt($constraint, $day, $start)) {
                    $closesACase = true;
                    break;
                }
            }
            if (!$closesACase) {
                continue;
            }
            $seen[$label] = true;
            $origin = $this->constraintOrigin($this->stringOf($constraint, 'id'), $constraintOrigins);
            $sentences[] = null !== $origin
                ? \sprintf('Contrainte en cause : « %s » (%s).', $label, $origin)
                : \sprintf('Contrainte en cause : « %s ».', $label);
        }

        return implode(' ', $sentences);
    }

    /**
     * L'origine d'une contrainte citée, ou `null` si non résolue. Id normalisé au premier `:` (une
     * CLUB suffixée `<uuid>:<teamId>` revient à son UUID) — miroir de
     * {@see ScheduleDiagnosticsRecorder::normalizeCauses}.
     *
     * @param array<string, bool> $constraintOrigins
     */
    private function constraintOrigin(string $constraintId, array $constraintOrigins): ?string
    {
        $colon = strpos($constraintId, ':');
        $id = false === $colon ? $constraintId : substr($constraintId, 0, $colon);
        if (!\array_key_exists($id, $constraintOrigins)) {
            return null;
        }

        return $constraintOrigins[$id]
            ? 'héritée du planning de saison — désactivable pour cette période'
            : 'posée pour cette période';
    }

    /**
     * Carte équipe → coachs PRINCIPAUX (MAIN) requis, depuis les liens v1 TEAM_COACH du payload. Un
     * ADJOINT (role ASSISTANT) ne compte pas (le moteur ne le rend pas requis — {@see parsing.py}).
     * Role absent ⇒ MAIN (legacy-safe, comme le moteur).
     *
     * @param list<array<string, mixed>> $constraints
     *
     * @return array<string, list<string>>
     */
    private function teamMainCoaches(array $constraints): array
    {
        $map = [];
        foreach ($constraints as $constraint) {
            if ('TEAM_COACH' !== ($constraint['type'] ?? null)) {
                continue;
            }
            $metadata = \is_array($constraint['metadata'] ?? null) ? $constraint['metadata'] : [];
            $role = strtoupper((string) ($metadata['role'] ?? 'MAIN'));
            if ('ASSISTANT' === $role) {
                continue;
            }
            $teamId = $this->stringOf($constraint, 'teamId');
            $coachId = $this->stringOf($metadata, 'coachId');
            if ('' === $teamId || '' === $coachId) {
                continue;
            }
            if (!\in_array($coachId, $map[$teamId] ?? [], true)) {
                $map[$teamId][] = $coachId;
            }
        }

        return $map;
    }

    /**
     * Un coach est-il BLOQUÉ à `(jour, minute de début)` par l'une de ses COACH_AVAILABILITY ?
     * Miroir de {@see parsing.py} `parse_v2_constraints` (algèbre De Morgan : unavailableDays +
     * complément d'availableDays, borné par la fenêtre fromTime/untilTime). Aucune règle ⇒ libre.
     *
     * @param list<array<string, mixed>> $constraints
     */
    private function coachBlockedAt(string $coachId, int $day, int $start, array $constraints): bool
    {
        foreach ($constraints as $constraint) {
            if (false === ($constraint['isActive'] ?? true)
                || ConstraintScope::COACH->value !== ($constraint['scope'] ?? null)
                || ConstraintFamily::COACH_AVAILABILITY->value !== ($constraint['family'] ?? null)
                || $this->stringOf($constraint, 'scopeTargetId') !== $coachId
            ) {
                continue;
            }
            if ($this->oneConstraintBlocksAt($constraint, $day, $start)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Une SEULE contrainte COACH_AVAILABILITY bloque-t-elle `(jour, début)` ? (Même algèbre que
     * {@see parsing.py} : fenêtre fromTime/untilTime [repli jour entier si absente/inversée],
     * unavailableDays dans la fenêtre, et complément d'une whitelist availableDays.).
     *
     * @param array<string, mixed> $constraint
     */
    private function oneConstraintBlocksAt(array $constraint, int $day, int $start): bool
    {
        $config = \is_array($constraint['config'] ?? null) ? $constraint['config'] : [];
        [$from, $to] = $this->coachWindowMinutes($config);

        $unavailable = $this->dayInts($config['unavailableDays'] ?? null);
        if (\in_array($day, $unavailable, true) && $start >= $from && $start < $to) {
            return true;
        }

        $available = $this->dayInts($config['availableDays'] ?? null);
        if ([] !== $available) {
            if (!\in_array($day, $available, true)) {
                return true; // jour hors liste blanche : bloqué toute la journée.
            }
            if ($start < $from || $start >= $to) {
                return true; // jour autorisé mais hors de la fenêtre horaire.
            }
        }

        return false;
    }

    /**
     * Bornes (minutes) de la fenêtre d'une COACH_AVAILABILITY, miroir de {@see parsing.py}
     * `_coach_window_minutes` : absente → jour entier (0, 1440) ; malformée ou inversée
     * (from >= to) → jour entier (honore l'indisponibilité prudemment).
     *
     * @param array<string, mixed> $config
     *
     * @return array{0: int, 1: int}
     */
    private function coachWindowMinutes(array $config): array
    {
        $from = $this->boundMinutes($config['fromTime'] ?? null, 0);
        $to = $this->boundMinutes($config['untilTime'] ?? null, 1440);
        if (null === $from || null === $to || $from >= $to) {
            return [0, 1440];
        }

        return [$from, $to];
    }

    /** Minutes d'une borne "HH:MM", le défaut si absente/vide, `null` si malformée (→ jour entier). */
    private function boundMinutes(mixed $raw, int $default): ?int
    {
        if (null === $raw || '' === $raw) {
            return $default;
        }

        return 1 === preg_match('/^(\d{1,2}):(\d{2})$/', (string) $raw, $m) ? ((int) $m[1]) * 60 + (int) $m[2] : null;
    }

    /**
     * Les jours où TOUS les entraîneurs PRINCIPAUX de l'équipe sont indisponibles toute la
     * journée — INTERSECTION, pas union. « Un coach suffit » (P4-319, même règle que le moteur
     * `closures.py` / `availability.py`) : une équipe peut avoir plusieurs principaux, et un
     * jour n'est FERMÉ que si AUCUN d'eux n'est disponible. Un principal qui couvre le jour le
     * garde ouvert. Aucun principal ⇒ aucun jour fermé par ce biais.
     *
     * @param list<array<string, mixed>> $constraints
     *
     * @return array<int, true>
     */
    private function mainCoachFullDayUnavailableDays(string $teamId, array $constraints): array
    {
        $coachIds = [];
        foreach ($constraints as $constraint) {
            if ('TEAM_COACH' !== ($constraint['type'] ?? null) || $this->stringOf($constraint, 'teamId') !== $teamId) {
                continue;
            }
            $metadata = \is_array($constraint['metadata'] ?? null) ? $constraint['metadata'] : [];
            if ('MAIN' !== ($metadata['role'] ?? null)) {
                continue;
            }
            $coachId = $this->stringOf($metadata, 'coachId');
            if ('' !== $coachId) {
                $coachIds[$coachId] = true;
            }
        }
        if ([] === $coachIds) {
            return [];
        }

        $intersection = null;
        foreach (array_keys($coachIds) as $coachId) {
            $coachDays = array_fill_keys($this->unavailableDaysOf($coachId, $constraints), true);
            $intersection = null === $intersection ? $coachDays : array_intersect_key($intersection, $coachDays);
            if ([] === $intersection) {
                return []; // un principal disponible tous les jours ⇒ aucun jour fermé pour l'équipe
            }
        }

        // `$coachIds` non vide ⇒ la boucle a tourné au moins une fois ⇒ `$intersection` affectée.
        return $intersection;
    }

    /**
     * Les fenêtres horaires HARD d'une équipe (famille TIME). Chaque borne en minutes, ou `null`.
     *
     * @param list<array<string, mixed>> $constraints
     *
     * @return list<array{minStart: int|null, maxStart: int|null, maxEnd: int|null}>
     */
    private function teamHardTimeWindows(string $teamId, array $constraints): array
    {
        $windows = [];
        foreach ($constraints as $constraint) {
            if (ConstraintFamily::TIME->value !== ($constraint['family'] ?? null)
                || $this->stringOf($constraint, 'scopeTargetId') !== $teamId
                || ($constraint['ruleType'] ?? null) !== 'HARD'
            ) {
                continue;
            }
            $config = \is_array($constraint['config'] ?? null) ? $constraint['config'] : [];
            $minStart = isset($config['minStartTime']) ? $this->minutesOf($this->stringOf($config, 'minStartTime')) : null;
            $maxStart = isset($config['maxStartTime']) ? $this->minutesOf($this->stringOf($config, 'maxStartTime')) : null;
            $maxEnd = isset($config['maxEndTime']) ? $this->minutesOf($this->stringOf($config, 'maxEndTime')) : null;
            if (null === $minStart && null === $maxStart && null === $maxEnd) {
                continue;
            }
            $windows[] = ['minStart' => $minStart, 'maxStart' => $maxStart, 'maxEnd' => $maxEnd];
        }

        return $windows;
    }

    /**
     * Un créneau [startMin, endMin] satisfait-il TOUTES les fenêtres (chaque règle HARD s'ajoute) ?
     *
     * @param list<array{0: int, 1: int}>                                           $daySlots
     * @param list<array{minStart: int|null, maxStart: int|null, maxEnd: int|null}> $windows
     */
    private function anySlotFitsAllWindows(array $daySlots, array $windows): bool
    {
        foreach ($daySlots as [$start, $end]) {
            $fits = true;
            foreach ($windows as $window) {
                if ((null !== $window['minStart'] && $start < $window['minStart'])
                    || (null !== $window['maxStart'] && $start > $window['maxStart'])
                    || (null !== $window['maxEnd'] && $end > $window['maxEnd'])
                ) {
                    $fits = false;
                    break;
                }
            }
            if ($fits) {
                return true;
            }
        }

        return false;
    }

    /** "HH:MM" ou "HH:MM:SS" → minutes depuis minuit (0 si illisible). */
    private function minutesOf(string $time): int
    {
        if (1 === preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
            return ((int) $m[1]) * 60 + (int) $m[2];
        }

        return 0;
    }

    /**
     * ALIGN-15 — le gymnase imposé d'une équipe tel que le MOTEUR le résout, pas seulement le
     * champ d'équipe. Le champ `forcedVenueId` de l'équipe (écrit par {@see TeamStateProcessor},
     * jamais par une contrainte FACILITY) GAGNE s'il est non vide ({@see targeting.py}
     * `_forced_venue_id`) ; sinon la DERNIÈRE contrainte gagnante (last-wins, {@see parsing.py}
     * `_set_venue_rule`) parmi les FACILITY de scope TEAM ciblant cette équipe, en HARD,
     * portant `forcedVenueId` OU `preferredVenueId` — les deux nourrissent `forced_venues`
     * ({@see parsing.py} `parse_v2_constraints`, branches preferredVenueId puis forcedVenueId).
     * Dans une MÊME contrainte, `preferredVenueId` l'emporte (sa branche est testée avant celle de
     * `forcedVenueId`). Les contraintes inactives sont sautées, comme le moteur ({@see parsing.py}
     * `isActive`).
     *
     * ⚠ À la différence de {@see teamHardDayRules} (qui ne filtre PAS `isActive`, divergence
     * préexistante hors périmètre de ce lot), ce helper respecte `isActive` comme le moteur.
     *
     * @param array<string, mixed>       $team
     * @param list<array<string, mixed>> $constraints
     */
    private function forcedVenueOf(array $team, array $constraints): string
    {
        $field = $this->stringOf($team, 'forcedVenueId');
        if ('' !== $field) {
            return $field;
        }

        $teamId = $this->stringOf($team, 'id');
        $venue = '';
        foreach ($constraints as $constraint) {
            if (false === ($constraint['isActive'] ?? true)
                || ConstraintFamily::FACILITY->value !== ($constraint['family'] ?? null)
                || ConstraintScope::TEAM->value !== ($constraint['scope'] ?? null)
                || $this->stringOf($constraint, 'scopeTargetId') !== $teamId
                || ($constraint['ruleType'] ?? null) !== 'HARD'
            ) {
                continue;
            }
            $config = \is_array($constraint['config'] ?? null) ? $constraint['config'] : [];
            // preferredVenueId l'emporte dans une même contrainte (branche testée en premier côté
            // moteur) ; sinon forcedVenueId. Last-wins entre contraintes = dernière affectation.
            $candidate = $this->stringOf($config, 'preferredVenueId');
            if ('' === $candidate) {
                $candidate = $this->stringOf($config, 'forcedVenueId');
            }
            if ('' !== $candidate) {
                $venue = $candidate;
            }
        }

        return $venue;
    }

    /**
     * Les règles DAY d'une équipe telles que le MOTEUR les fusionne ({@see constraints.py}
     * `add_time_window_constraints`) : uniquement HARD, unionnées par clé. `allowed` vaut
     * `null` tant qu'aucune whitelist non vide n'existe (= non configuré, jamais « aucun jour
     * permis »). `forcedRuleCount` compte les contraintes « au moins une séance » distinctes —
     * deux se combinent (avertissement de fusion).
     *
     * @param list<array<string, mixed>> $constraints
     *
     * @return array{forced: list<int>, forbidden: list<int>, allowed: list<int>|null, forcedRuleCount: int}
     */
    private function teamHardDayRules(string $teamId, array $constraints): array
    {
        $forced = [];
        $forbidden = [];
        $allowed = null;
        $forcedRuleCount = 0;

        foreach ($constraints as $constraint) {
            if (ConstraintFamily::DAY->value !== ($constraint['family'] ?? null)
                || $this->stringOf($constraint, 'scopeTargetId') !== $teamId
                || ($constraint['ruleType'] ?? null) !== 'HARD'
            ) {
                continue;
            }
            $config = \is_array($constraint['config'] ?? null) ? $constraint['config'] : [];

            $forcedDays = $this->dayInts($config['forcedDays'] ?? null);
            if ([] !== $forcedDays) {
                $forced = [...$forced, ...$forcedDays];
                ++$forcedRuleCount;
            }
            $forbidden = [...$forbidden, ...$this->dayInts($config['forbiddenDays'] ?? null)];
            $allowedDays = $this->dayInts($config['allowedDays'] ?? null);
            if ([] !== $allowedDays) {
                $allowed = null === $allowed ? $allowedDays : [...$allowed, ...$allowedDays];
            }
        }

        return [
            'forced' => array_values(array_unique($forced)),
            'forbidden' => array_values(array_unique($forbidden)),
            'allowed' => null === $allowed ? null : array_values(array_unique($allowed)),
            'forcedRuleCount' => $forcedRuleCount,
        ];
    }

    /**
     * @return list<int>
     */
    private function dayInts(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $days = [];
        foreach ($value as $day) {
            $days[] = (int) $day;
        }

        return $days;
    }

    /**
     * Jours ISO en toutes lettres, triés — vocabulaire gestionnaire, jamais un numéro à l'écran.
     *
     * @param list<int> $days
     */
    private function dayList(array $days): string
    {
        sort($days);

        return implode(', ', array_map(static fn (int $day): string => self::DAY_NAMES[$day] ?? (string) $day, $days));
    }

    /**
     * Les jours autorisés d'une équipe, ou `null` quand elle n'en restreint aucun.
     *
     * @param list<array<string, mixed>> $constraints
     *
     * @return list<int>|null
     */
    private function allowedDaysOf(string $teamId, array $constraints): ?array
    {
        $allowed = null;
        foreach ($constraints as $constraint) {
            if ($this->stringOf($constraint, 'scopeTargetId') !== $teamId) {
                continue;
            }
            $days = ($constraint['config'] ?? [])['allowedDays'] ?? null;
            if (!\is_array($days) || [] === $days) {
                continue;
            }
            $current = array_map(intval(...), array_values($days));
            // Deux whitelists se CROISENT (chacune est une restriction), elles ne s'ajoutent pas.
            $allowed = null === $allowed ? $current : array_values(array_intersect($allowed, $current));
        }

        return $allowed;
    }

    /**
     * @param list<array<string, mixed>> $constraints
     *
     * @return list<int>
     */
    private function unavailableDaysOf(string $coachId, array $constraints): array
    {
        $days = [];
        foreach ($constraints as $constraint) {
            if (ConstraintScope::COACH->value !== ($constraint['scope'] ?? null) || $this->stringOf($constraint, 'scopeTargetId') !== $coachId) {
                continue;
            }
            foreach (($constraint['config'] ?? [])['unavailableDays'] ?? [] as $day) {
                $days[] = (int) $day;
            }
        }

        return array_values(array_unique($days));
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return list<array<string, mixed>>
     */
    private function listOf(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return \is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    /** @param array<string, mixed> $source */
    private function stringOf(array $source, string $key): string
    {
        $value = $source[$key] ?? null;

        return \is_string($value) ? $value : '';
    }

    /** @param array<string, mixed> $entity */
    private function nameOf(array $entity): string
    {
        $name = $this->stringOf($entity, 'name');

        return '' !== $name ? $name : 'Sans nom';
    }
}
