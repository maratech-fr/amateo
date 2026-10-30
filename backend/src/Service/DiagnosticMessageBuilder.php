<?php

declare(strict_types=1);

namespace App\Service;

final class DiagnosticMessageBuilder
{
    /** ISO day-of-week (1=Monday..7=Sunday) → French, matching the backend/frontend convention. */
    private const DAY_NAMES = [
        1 => 'lundi',
        2 => 'mardi',
        3 => 'mercredi',
        4 => 'jeudi',
        5 => 'vendredi',
        6 => 'samedi',
        7 => 'dimanche',
    ];

    /**
     * @param array<string, mixed>  $diagnostic
     * @param array<string, string> $teamNames         teamId => name
     * @param array<string, string> $coachNames        coachId => name
     * @param array<string, string> $venueNames        venueId => name
     * @param array<string, bool>   $constraintOrigins constraintId => la contrainte est HÉRITÉE du
     *                                                 planning de saison (calendarEntryId NULL) ;
     *                                                 `false` = posée pour cette période
     */
    public function build(
        array $diagnostic,
        array $teamNames = [],
        array $coachNames = [],
        array $venueNames = [],
        array $constraintOrigins = [],
    ): string {
        $type = (string) ($diagnostic['type'] ?? '');
        $engineMessage = trim((string) ($diagnostic['message'] ?? ''));

        // The engine already emits precise, manager-ready French messages that
        // name the teams / venue / coach + day + time + reason (who/when/why).
        // Prefer them; the localized builders below are only a fallback for
        // payloads that arrive without a rich message. soft_lock_moved and
        // unused_slot are the exceptions we ALWAYS rebuild locally: soft_lock_moved
        // still arrives with a raw English message, and unused_slot arrives with an
        // EMPTY message (the engine owns only the structured fields — venueId,
        // dayOfWeek, startTime, durationMinutes — and leaves the French copy, day
        // name included, to this builder).
        return match ($type) {
            'unplaced' => '' !== $engineMessage ? $engineMessage : $this->buildUnplaced($diagnostic, $teamNames),
            'conflict' => '' !== $engineMessage ? $engineMessage : $this->buildConflict($diagnostic, $teamNames, $coachNames, $venueNames),
            'coach_overload' => '' !== $engineMessage ? $engineMessage : $this->buildCoachOverload($diagnostic, $coachNames),
            'soft_lock_moved' => $this->buildSoftLockMoved($diagnostic, $teamNames, $venueNames),
            'unused_slot' => $this->buildUnusedSlot($diagnostic, $venueNames),
            // Lot 3B PR B — le moteur nomme déjà le GROUPE (« Groupe A, B, C : aucun créneau… ») ;
            // le backend AJOUTE l'ORIGINE de chaque contrainte citée, qu'il est le seul à connaître
            // (le moteur ignore `calendarEntryId`). Message vide ⇒ repli générique.
            'shared_block_not_honored' => $this->buildSharedBlockNotHonored($diagnostic, $engineMessage, $constraintOrigins),
            // Réservation souveraine (besoin fondateur 2026-10-30, amendement ADR-0001) — un verrou
            // HARD a rendu une règle dure insatisfiable ; le moteur a produit le planning, tenu le
            // verrou, et relâché la règle. Le moteur nomme déjà le groupe/la règle dans son message
            // (préféré) ; message vide ⇒ on compose le texte gestionnaire (équipe + règle + le fait
            // qu'une réservation a gagné). « La réservation gagne envers tout, mais c'est la SEULE exception. »
            'rule_sacrificed' => '' !== $engineMessage ? $engineMessage : $this->buildRuleSacrificed($diagnostic, $teamNames),
            // Décision fondateur 2026-10-30 — la réservation d'une AUTRE équipe prend la seule case
            // compatible : la règle tient, la séance reste non placée. Message moteur préféré.
            'blocked_by_reservation' => '' !== $engineMessage ? $engineMessage : $this->buildBlockedByReservation($diagnostic, $teamNames),
            // PR 6 (lot 8) — « Garder ses créneaux de saison » : le moteur émet déjà le message
            // nommé (« SM1 : 2/3 créneaux de saison conservés. Non conservés : … »), on le préfère.
            'season_slots_kept' => '' !== $engineMessage ? $engineMessage : 'Des créneaux de saison n\'ont pas pu être conservés.',
            default => '' !== $engineMessage ? $engineMessage : 'Diagnostic inconnu.',
        };
    }

    /**
     * Compose le message d'un bloc de mutualisation non honoré : le texte du moteur (groupe nommé,
     * cause + case nommées, et QUOI FAIRE — décision fondateur Q3) SUIVI, pour chaque CONTRAINTE
     * citée en `causes[]`, de son origine — « héritée du planning de saison — désactivable pour cette
     * période » quand elle n'est PAS datée (`calendarEntryId` NULL), « posée pour cette période »
     * sinon. Aucun identifiant interne (seulement le LIBELLÉ de la contrainte, texte gestionnaire).
     * Une cause dégradée (sans libellé) ou une contrainte non résolue n'ajoute rien — jamais un groupe
     * accusé avec une origine fantôme.
     *
     * Les causes `coach_unavailability` (« un coach suffit ») et `time_window` (fenêtre horaire dure)
     * SONT des contraintes nommées : leur origine s'accole ici. La cause `hard_lock` est un VERROU,
     * pas une contrainte datée — le moteur nomme déjà la case et l'équipe qui verrouille dans son
     * message, et aucune origine ne s'y accole (le verrou n'a pas de `calendarEntryId`).
     *
     * @param array<string, mixed> $diagnostic
     * @param array<string, bool>  $constraintOrigins
     */
    private function buildSharedBlockNotHonored(array $diagnostic, string $engineMessage, array $constraintOrigins): string
    {
        if ('' === $engineMessage) {
            return 'Un groupe de mutualisation ne peut pas être honoré : aucun créneau commun servable.';
        }

        $causes = \is_array($diagnostic['causes'] ?? null) ? $diagnostic['causes'] : [];
        $sentences = [];
        $seen = [];
        foreach ($causes as $cause) {
            if (!\is_array($cause)) {
                continue;
            }
            // Un verrou n'est pas une contrainte datée : le moteur le nomme déjà dans le message
            // (case + équipe qui verrouille), aucune origine à accoler ici.
            if ('hard_lock' === ($cause['kind'] ?? null)) {
                continue;
            }
            $label = trim((string) ($cause['label'] ?? $cause['name'] ?? ''));
            if ('' === $label || isset($seen[$label])) {
                continue;
            }
            $seen[$label] = true;
            $origin = $this->constraintOrigin($cause, $constraintOrigins);
            $sentences[] = null !== $origin
                ? \sprintf('Contrainte en cause : « %s » (%s).', $label, $origin)
                : \sprintf('Contrainte en cause : « %s ».', $label);
        }

        return [] === $sentences ? $engineMessage : $engineMessage . ' ' . implode(' ', $sentences);
    }

    /**
     * Compose le texte d'une règle SACRIFIÉE par la réservation souveraine quand le moteur n'a pas
     * fourni de message : NOMME l'équipe concernée (via `teamNames`, jamais un identifiant interne)
     * et la règle relâchée (le LIBELLÉ de sa première cause), et DIT qu'une réservation a gagné.
     * Équipe ou libellé absent ⇒ repli dégradé, jamais un identifiant brut ni « Diagnostic inconnu. ».
     *
     * @param array<string, mixed>  $diagnostic
     * @param array<string, string> $teamNames
     */
    private function buildRuleSacrificed(array $diagnostic, array $teamNames): string
    {
        $teamId = $this->extractId($diagnostic, 'teamId', 'team_id');
        $teamName = null !== $teamId ? ($teamNames[$teamId] ?? $teamId) : null;

        $causes = \is_array($diagnostic['causes'] ?? null) ? $diagnostic['causes'] : [];
        $label = '';
        foreach ($causes as $cause) {
            if (!\is_array($cause)) {
                continue;
            }
            $candidate = trim((string) ($cause['label'] ?? $cause['name'] ?? ''));
            if ('' !== $candidate) {
                $label = $candidate;
                break;
            }
        }

        $rule = '' !== $label ? \sprintf('la règle « %s »', $label) : 'une règle';
        $ofTeam = null !== $teamName ? \sprintf(' de %s', $teamName) : '';

        return \sprintf(
            'Un verrou (réservation) est prioritaire : %s%s n\'a pas pu être honorée et a été relâchée.',
            $rule,
            $ofTeam,
        );
    }

    /**
     * Le repli d'une séance privée de case par la réservation d'une autre équipe : nomme l'équipe et la
     * règle (LIBELLÉ de la première cause), et dit que la règle tient : elle n'est pas contournée.
     *
     * @param array<string, mixed>  $diagnostic
     * @param array<string, string> $teamNames
     */
    private function buildBlockedByReservation(array $diagnostic, array $teamNames): string
    {
        $teamId = $this->extractId($diagnostic, 'teamId', 'team_id');
        $teamName = null !== $teamId ? ($teamNames[$teamId] ?? $teamId) : 'une équipe';

        $label = '';
        foreach (\is_array($diagnostic['causes'] ?? null) ? $diagnostic['causes'] : [] as $cause) {
            $candidate = \is_array($cause) ? trim((string) ($cause['label'] ?? '')) : '';
            if ('' !== $candidate) {
                $label = $candidate;
                break;
            }
        }
        $rule = '' !== $label ? \sprintf('la règle « %s »', $label) : 'ses règles';

        return \sprintf('Séance non placée pour %s : la réservation d\'une autre équipe occupe la seule case compatible avec %s.', $teamName, $rule);
    }

    /**
     * L'origine d'une contrainte citée, ou `null` si son id est absent/non résolu. L'id est
     * NORMALISÉ au premier `:` (une contrainte CLUB suffixée `<uuid>:<teamId>` par le builder —
     * {@see ScheduleConstraintBuilder} — revient à son UUID d'entité), symétrie de
     * {@see ScheduleDiagnosticsRecorder::normalizeCauses}.
     *
     * @param array<string, mixed> $cause
     * @param array<string, bool>  $constraintOrigins
     */
    private function constraintOrigin(array $cause, array $constraintOrigins): ?string
    {
        $rawId = $cause['constraintId'] ?? $cause['constraint_id'] ?? null;
        if (null === $rawId) {
            return null;
        }
        $id = (string) $rawId;
        $colon = strpos($id, ':');
        $id = false === $colon ? $id : substr($id, 0, $colon);

        if (!\array_key_exists($id, $constraintOrigins)) {
            return null;
        }

        return $constraintOrigins[$id]
            ? 'héritée du planning de saison — désactivable pour cette période'
            : 'posée pour cette période';
    }

    /**
     * @param array<string, mixed>  $diagnostic
     * @param array<string, string> $teamNames
     */
    private function buildUnplaced(array $diagnostic, array $teamNames): string
    {
        $teamId = $this->extractId($diagnostic, 'teamId', 'team_id');
        $teamName = null !== $teamId ? ($teamNames[$teamId] ?? $teamId) : 'L\'équipe';

        return \sprintf(
            '%s n\'a pas pu être placée dans le planning : aucun créneau ne correspondait à ses contraintes.',
            $teamName,
        );
    }

    /**
     * @param array<string, mixed>  $diagnostic
     * @param array<string, string> $teamNames
     * @param array<string, string> $coachNames
     * @param array<string, string> $venueNames
     */
    private function buildConflict(
        array $diagnostic,
        array $teamNames,
        array $coachNames,
        array $venueNames,
    ): string {
        $venueId = $this->extractId($diagnostic, 'venueId', 'venue_id');
        $coachId = $this->extractId($diagnostic, 'coachId', 'coach_id');

        if (null !== $venueId) {
            $venueName = $venueNames[$venueId] ?? $venueId;

            return \sprintf(
                '%s accueille plusieurs équipes simultanément. Veuillez déplacer l\'une des séances.',
                $venueName,
            );
        }

        if (null !== $coachId) {
            $coachName = $coachNames[$coachId] ?? $coachId;

            return \sprintf(
                '%s est assigné(e) à plusieurs équipes simultanément. Veuillez réattribuer l\'une des séances.',
                $coachName,
            );
        }

        return 'Le planning n\'a pas pu être généré : les contraintes actuelles sont incompatibles.';
    }

    /**
     * @param array<string, mixed>  $diagnostic
     * @param array<string, string> $coachNames
     */
    private function buildCoachOverload(array $diagnostic, array $coachNames): string
    {
        $coachId = $this->extractId($diagnostic, 'coachId', 'coach_id');
        $coachName = null !== $coachId ? ($coachNames[$coachId] ?? $coachId) : 'L\'entraîneur';
        $count = (int) ($diagnostic['count'] ?? 0);
        $threshold = (int) ($diagnostic['threshold'] ?? 0);

        if ($count > 0 && $threshold > 0) {
            return \sprintf(
                '%s est surchargé(e) avec %d séances (limite recommandée : %d).',
                $coachName,
                $count,
                $threshold,
            );
        }

        return \sprintf(
            '%s est surchargé(e). Veuillez réduire son nombre de séances.',
            $coachName,
        );
    }

    /**
     * @param array<string, mixed>  $diagnostic
     * @param array<string, string> $teamNames
     * @param array<string, string> $venueNames
     */
    private function buildSoftLockMoved(array $diagnostic, array $teamNames, array $venueNames): string
    {
        $teamId = $this->extractId($diagnostic, 'teamId', 'team_id');
        $teamName = null !== $teamId ? ($teamNames[$teamId] ?? $teamId) : 'L\'équipe';
        $venueId = $this->extractId($diagnostic, 'venueId', 'venue_id');
        $venueName = null !== $venueId ? ($venueNames[$venueId] ?? $venueId) : null;

        // Coordonnées du créneau déplacé (jour + plage horaire), quand le moteur les émet —
        // même patron que buildUnusedSlot (table des jours + calcul de plage). Un moteur plus
        // ancien qui n'émet pas ces champs laisse $when vide : la parenthèse retombe alors sur
        // le gymnase seul (ou disparaît), rendant le message d'avant à l'octet près.
        $dayOfWeek = (int) ($diagnostic['dayOfWeek'] ?? $diagnostic['day_of_week'] ?? 0);
        $day = self::DAY_NAMES[$dayOfWeek] ?? '';
        $start = $this->hhmm((string) ($diagnostic['startTime'] ?? $diagnostic['start_time'] ?? ''));
        $duration = (int) ($diagnostic['durationMinutes'] ?? $diagnostic['duration_minutes'] ?? 0);
        $end = '' !== $start && $duration > 0 ? $this->addMinutes($start, $duration) : '';

        $slot = '';
        if ('' !== $start) {
            $slot = '' !== $end ? \sprintf('de %s à %s', $start, $end) : \sprintf('à %s', $start);
        }
        $when = trim($day . ' ' . $slot);

        // La parenthèse : gymnase + coordonnées, gymnase seul, coordonnées seules, ou rien.
        $inside = '';
        if (null !== $venueName) {
            $inside = '' !== $when ? \sprintf('%s, %s', $venueName, $when) : $venueName;
        } elseif ('' !== $when) {
            $inside = $when;
        }

        return \sprintf(
            'Le créneau préféré de %s%s a été déplacé par le solveur pour un meilleur ajustement global.',
            $teamName,
            '' !== $inside ? \sprintf(' (%s)', $inside) : '',
        );
    }

    /**
     * @param array<string, mixed>  $diagnostic
     * @param array<string, string> $venueNames
     */
    private function buildUnusedSlot(array $diagnostic, array $venueNames): string
    {
        $venueId = $this->extractId($diagnostic, 'venueId', 'venue_id');
        $venueName = null !== $venueId ? ($venueNames[$venueId] ?? $venueId) : 'Un gymnase';

        $dayOfWeek = (int) ($diagnostic['dayOfWeek'] ?? $diagnostic['day_of_week'] ?? 0);
        $day = self::DAY_NAMES[$dayOfWeek] ?? '';
        $start = $this->hhmm((string) ($diagnostic['startTime'] ?? $diagnostic['start_time'] ?? ''));
        $duration = (int) ($diagnostic['durationMinutes'] ?? $diagnostic['duration_minutes'] ?? 0);
        $end = '' !== $start && $duration > 0 ? $this->addMinutes($start, $duration) : '';

        $slot = '';
        if ('' !== $start) {
            $slot = '' !== $end ? \sprintf('de %s à %s', $start, $end) : \sprintf('à %s', $start);
        }
        $when = trim($day . ' ' . $slot);

        return \sprintf(
            'Créneau disponible non utilisé : %s%s — aucune équipe n\'y est placée.',
            $venueName,
            '' !== $when ? \sprintf(' (%s)', $when) : '',
        );
    }

    private function hhmm(string $time): string
    {
        return 1 === preg_match('/(\d{2}):(\d{2})/', $time, $matches) ? $matches[0] : $time;
    }

    private function addMinutes(string $hhmm, int $minutes): string
    {
        if (1 !== preg_match('/^(\d{2}):(\d{2})/', $hhmm, $matches)) {
            return '';
        }

        $total = (((int) $matches[1]) * 60 + (int) $matches[2] + $minutes) % (24 * 60);

        return \sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }

    /** @param array<string, mixed> $diagnostic */
    private function extractId(array $diagnostic, string $camelKey, string $snakeKey): ?string
    {
        if (isset($diagnostic[$camelKey])) {
            return (string) $diagnostic[$camelKey];
        }

        if (isset($diagnostic[$snakeKey])) {
            return (string) $diagnostic[$snakeKey];
        }

        return null;
    }
}
