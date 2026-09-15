<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use Throwable;

/**
 * Re-résout, CÔTÉ SERVEUR, le gymnase FÉDÉRAL derrière un numéro de salle
 * (`Venue.externalRef`) choisi à la main — pour n'écrire dans la table PARTAGÉE
 * `opponent_venue_suggestion` que des données fédérales, JAMAIS le texte/les
 * coordonnées fournis par un client (revue sécurité 2026-09-15).
 *
 * ⚠ L'index FFBB des salles n'est queryable NI par `numero` en filtre, NI par
 * `numero` en plein texte (sondé le 2026-09-15). La seule voie qui rende le hit est
 * le `_geoRadius` ({@see FfbbApiClient::searchSallesNearby}). On l'exploite comme une
 * VÉRIFICATION : les coordonnées du corps ne servent que de GRAINE de recherche, on
 * ne garde que le hit dont le `numero` est EXACTEMENT celui demandé, et on lit son
 * libellé/ville/CP/coordonnées FÉDÉRAUX. Un client qui mentirait sur le libellé le
 * voit ignoré (on prend celui du hit) ; qui mentirait sur les coordonnées ne trouve
 * aucune salle de ce `numero` à proximité → aucune résolution (choix tenant seul).
 * Best-effort : FFBB muet → null (jamais bloquant).
 */
final class FfbbSalleResolver
{
    /** Rayon de VÉRIFICATION : la salle choisie est à ses propres coordonnées (~0 m). */
    private const int VERIFY_RADIUS_METERS = 2000;

    public function __construct(private readonly FfbbApiClient $apiClient) {}

    /**
     * @return array{label: string, city: ?string, postalCode: ?string, latitude: ?float, longitude: ?float}|null
     */
    public function resolveByExternalRef(string $externalRef, float $latitude, float $longitude): ?array
    {
        if (1 !== preg_match('/^\d{1,20}$/', $externalRef)) {
            return null; // un numéro fédéral est numérique — sinon inconnu par construction
        }

        try {
            $hits = $this->apiClient->searchSallesNearby($latitude, $longitude, self::VERIFY_RADIUS_METERS);
        } catch (Throwable) {
            return null; // best-effort : FFBB muet → pas d'écriture partagée
        }

        foreach ($hits as $hit) {
            if ((string) ($hit['numero'] ?? '') !== $externalRef) {
                continue;
            }
            $label = $this->str($hit['libelle'] ?? null);
            if (null === $label) {
                return null; // un gymnase sans libellé fédéral n'est pas une suggestion utile
            }
            $carto = \is_array($hit['cartographie'] ?? null) ? $hit['cartographie'] : [];
            $commune = \is_array($hit['commune'] ?? null) ? $hit['commune'] : [];

            return [
                'label' => mb_substr($label, 0, 180),
                'city' => $this->clamp($this->str($carto['ville'] ?? null), 180),
                'postalCode' => $this->clamp($this->str($commune['codePostal'] ?? null), 16),
                'latitude' => $this->float($carto['latitude'] ?? null),
                'longitude' => $this->float($carto['longitude'] ?? null),
            ];
        }

        return null;
    }

    private function str(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    private function clamp(?string $value, int $length): ?string
    {
        return null === $value ? null : mb_substr($value, 0, $length);
    }

    private function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
