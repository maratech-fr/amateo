<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use Throwable;

/**
 * Re-résout, CÔTÉ SERVEUR, l'ADRESSE fédérale (rue + commune) derrière un numéro de
 * salle FFBB (`Venue.externalRef`) — pour un CONTRÔLE de cohérence de la position
 * stockée d'un gymnase (aucune écriture).
 *
 * Frère de {@see FfbbSalleResolver}, délibérément SÉPARÉ : celui-là lit le libellé +
 * la ville + les coordonnées fédérales (pour n'écrire que du fédéral dans la table
 * partagée `opponent_venue_suggestion`) ; celui-ci lit le champ `adresse` (la RUE),
 * dont l'autre n'a pas besoin. Même patron de VÉRIFICATION que le frère
 * ({@see FfbbSalleResolver::VERIFY_RADIUS_METERS}) : les coordonnées stockées ne sont
 * qu'une GRAINE de recherche géo, on ne garde que le hit dont le `numero` est
 * EXACTEMENT celui demandé, et on lit son adresse fédérale.
 *
 * Best-effort : `numero` non numérique, FFBB muet, salle introuvable ou salle sans
 * adresse → `null` (jamais bloquant, aucune alerte en aval).
 */
final class FfbbSalleAddressResolver
{
    /** Rayon de VÉRIFICATION : la salle choisie est à ses propres coordonnées (~0 m). */
    private const int VERIFY_RADIUS_METERS = 2000;

    public function __construct(private readonly FfbbApiClient $apiClient) {}

    /**
     * @return array{address: string, city: ?string}|null
     */
    public function resolveAddress(string $externalRef, float $latitude, float $longitude): ?array
    {
        if (1 !== preg_match('/^\d{1,20}$/', $externalRef)) {
            return null; // un numéro fédéral est numérique — sinon inconnu par construction
        }

        try {
            $hits = $this->apiClient->searchSallesNearby($latitude, $longitude, self::VERIFY_RADIUS_METERS);
        } catch (Throwable) {
            return null; // best-effort : FFBB muet → aucune vérification
        }

        foreach ($hits as $hit) {
            if ((string) ($hit['numero'] ?? '') !== $externalRef) {
                continue;
            }
            $address = $this->str($hit['adresse'] ?? null);
            if (null === $address) {
                return null; // sans adresse fédérale, rien à comparer
            }
            $carto = \is_array($hit['cartographie'] ?? null) ? $hit['cartographie'] : [];

            return ['address' => $address, 'city' => $this->str($carto['ville'] ?? null)];
        }

        return null;
    }

    private function str(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
