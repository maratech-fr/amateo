<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

/**
 * La MAISON UNIQUE de l'empreinte d'un conflit (RMM-3). Prend un item du tableau
 * rendu par {@see MatchConflictDetector} et en dérive une chaîne STABLE
 * `TYPE:champs` — l'identité DURABLE du conflit, celle qui doit rester constante
 * tant que « c'est le même litige », et changer dès que sa NATURE change.
 *
 * L'empreinte se calcule EN AVAL : le détecteur n'est pas touché, il continue de
 * rendre ses items gradués (start/end/severity compris) ; cette classe n'en
 * retient que les champs d'IDENTITÉ. Sont donc EXCLUS partout : start/end (le
 * segment de recouvrement bouge sans que le litige change), severity, coachRole,
 * estimatedKickoff, et tout compteur (imported/expected). Deux fixtures dont
 * l'heure estimée glisse restent LE MÊME MATCH_MATCH ; une compétition qui passe
 * de 9/22 à 15/22 reste LE MÊME COMPETITION_INCOMPLETE (sinon chaque import le
 * re-badgerait « nouveau »).
 *
 * Les paires de fixtures sont TRIÉES : l'ordre gauche/droite est un artefact de la
 * double boucle du détecteur, pas de l'identité du conflit.
 *
 * Consommée par deux lecteurs du même radar stateless : {@see FixtureConflictsController}
 * (qui expose l'empreinte, champ additif) et {@see MatchModuleDeltaComputer} (qui
 * compare les empreintes courantes à celles de la référence de visite).
 */
final class ConflictFingerprinter
{
    /**
     * @param array<string, mixed> $conflict un item de MatchConflictDetector::detect()
     */
    public function fingerprint(array $conflict): string
    {
        $type = $this->str($conflict, 'type');

        return match ($type) {
            // Coach + les deux fixtures (triées) : change de nature dès que l'une
            // des deux fixtures change (A↔B devient A↔C).
            'MATCH_MATCH' => \sprintf('%s:%s:%s', $type, $this->str($conflict, 'coachId'), $this->pair(
                $this->nestedStr($conflict, 'left', 'fixtureId'),
                $this->nestedStr($conflict, 'right', 'fixtureId'),
            )),
            // Coach + la fixture + le créneau d'entraînement.
            'MATCH_TRAINING' => \sprintf(
                '%s:%s:%s:%s',
                $type,
                $this->str($conflict, 'coachId'),
                $this->nestedStr($conflict, 'fixture', 'fixtureId'),
                $this->nestedStr($conflict, 'training', 'slotTemplateId'),
            ),
            // Le gymnase + les deux fixtures (triées).
            'VENUE_OVERLAP' => \sprintf('%s:%s:%s', $type, $this->str($conflict, 'venueId'), $this->pair(
                $this->nestedStr($conflict, 'left', 'fixtureId'),
                $this->nestedStr($conflict, 'right', 'fixtureId'),
            )),
            // La passerelle + les deux fixtures (triées).
            'TEAM_LINK_OVERLAP' => \sprintf('%s:%s:%s', $type, $this->str($conflict, 'teamLinkId'), $this->pair(
                $this->nestedStr($conflict, 'left', 'fixtureId'),
                $this->nestedStr($conflict, 'right', 'fixtureId'),
            )),
            // Une seule fixture porte le litige.
            'LEAGUE_WINDOW_VIOLATION', 'ACCESS_WINDOW_LOST', 'AWAY_NO_FOOTPRINT' => \sprintf(
                '%s:%s',
                $type,
                $this->nestedStr($conflict, 'fixture', 'fixtureId'),
            ),
            // La fixture + l'indisponibilité qui la frappe.
            'VENUE_UNAVAILABLE' => \sprintf(
                '%s:%s:%s',
                $type,
                $this->nestedStr($conflict, 'fixture', 'fixtureId'),
                $this->str($conflict, 'unavailabilityId'),
            ),
            // La compétition SEULE — jamais imported/expected (chaque import les bouge).
            'COMPETITION_INCOMPLETE' => \sprintf('%s:%s', $type, $this->str($conflict, 'competitionId')),
            default => throw new InvalidArgumentException(\sprintf('Type de conflit inconnu pour l\'empreinte : « %s ».', $type)),
        };
    }

    /** Deux ids ordonnés : le tri efface l'artefact gauche/droite. */
    private function pair(string $a, string $b): string
    {
        $ids = [$a, $b];
        sort($ids);

        return implode(',', $ids);
    }

    /** @param array<string, mixed> $conflict */
    private function str(array $conflict, string $key): string
    {
        $value = $conflict[$key] ?? null;
        if (!\is_string($value)) {
            throw new InvalidArgumentException(\sprintf('Champ d\'empreinte « %s » absent ou non-textuel.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $conflict */
    private function nestedStr(array $conflict, string $outer, string $inner): string
    {
        $nested = $conflict[$outer] ?? null;
        if (!\is_array($nested)) {
            throw new InvalidArgumentException(\sprintf('Bloc d\'empreinte « %s » absent.', $outer));
        }
        $value = $nested[$inner] ?? null;
        if (!\is_string($value)) {
            throw new InvalidArgumentException(\sprintf('Champ d\'empreinte « %s.%s » absent ou non-textuel.', $outer, $inner));
        }

        return $value;
    }
}
