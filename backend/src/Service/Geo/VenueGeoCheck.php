<?php

declare(strict_types=1);

namespace App\Service\Geo;

use App\Entity\Venue;
use App\Service\Basketball\FfbbSalleAddressResolver;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * Contrôle de cohérence de la position STOCKÉE d'un gymnase rattaché à une salle FFBB
 * (étude BCCL 2026-09-30). Pour chaque gymnase du club+saison courant qui porte un
 * `externalRef` (salle FFBB) ET des coordonnées, on compare le point ENREGISTRÉ (jamais
 * le `_geo` FFBB frais — l'alerte doit disparaître quand le gestionnaire corrige) à
 * l'adresse fédérale de la salle :
 *
 *   (a) reverse BAN du point stocké → la rue la plus proche ; si le point tombe (type
 *       `housenumber`/`street`) dans une AUTRE rue que celle de l'adresse FFBB
 *       (comparaison normalisée) → alerte {@see self::REASON_OTHER_STREET} ;
 *   (b) forward BAN de l'adresse FFBB ; si le point exact (type `housenumber`) est à
 *       plus de {@see self::FAR_THRESHOLD_METERS} m du point stocké → alerte
 *       {@see self::REASON_FAR_FROM_ADDRESS}.
 *
 * BEST-EFFORT, JAMAIS bloquant : tout échec réseau/FFBB/BAN, salle introuvable, ou
 * absence de coordonnées/`externalRef` → aucune alerte pour ce gymnase. Au plus UNE
 * alerte par gymnase ((a) prime sur (b)). Aucune écriture.
 */
final class VenueGeoCheck
{
    public const string REASON_OTHER_STREET = 'OTHER_STREET';
    public const string REASON_FAR_FROM_ADDRESS = 'FAR_FROM_ADDRESS';

    /** Au-delà, le point exact fédéral et le point stocké ne décrivent plus le même lieu. */
    private const int FAR_THRESHOLD_METERS = 300;

    /** Rayon terrestre moyen (mètres), pour la distance haversine. */
    private const int EARTH_RADIUS_METERS = 6_371_000;

    /** Types de précision BAN qui désignent un point de rue (pas une localité/commune). */
    private const array STREET_LEVEL_TYPES = ['housenumber', 'street'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FfbbSalleAddressResolver $salleAddressResolver,
        private readonly BanGeocodingClient $ban,
    ) {}

    /**
     * @return list<array{venueId: string, reason: string, ffbbAddress: string, pointStreet: string|null, distanceM: int|null}>
     */
    public function check(string $clubId, string $seasonId): array
    {
        // Le filtre tenant Doctrine + la RLS scopent déjà par club ; le club+saison explicite
        // est une défense en profondeur et rend l'intention de la requête claire.
        /** @var list<Venue> $venues */
        $venues = $this->entityManager->getRepository(Venue::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]);

        $alerts = [];
        foreach ($venues as $venue) {
            $alert = $this->checkVenue($venue);
            if (null !== $alert) {
                $alerts[] = $alert;
            }
        }

        return $alerts;
    }

    /**
     * @return array{venueId: string, reason: string, ffbbAddress: string, pointStreet: string|null, distanceM: int|null}|null
     */
    private function checkVenue(Venue $venue): ?array
    {
        $externalRef = $venue->getExternalRef();
        $latRaw = $venue->getLatitude();
        $lonRaw = $venue->getLongitude();
        if (null === $externalRef || '' === $externalRef || null === $latRaw || null === $lonRaw) {
            return null; // pas ancré FFBB, ou pas géolocalisé → rien à vérifier
        }
        $latitude = (float) $latRaw;
        $longitude = (float) $lonRaw;

        $salle = $this->salleAddressResolver->resolveAddress($externalRef, $latitude, $longitude);
        if (null === $salle) {
            return null; // salle FFBB introuvable / FFBB muet → best-effort
        }
        $ffbbAddress = $salle['address'];

        // (a) — le point tombe-t-il dans une AUTRE rue ?
        $reverse = $this->ban->reverseStreet($latitude, $longitude);
        if (null !== $reverse
            && \in_array($reverse['type'], self::STREET_LEVEL_TYPES, true)
            && !$this->sameStreet($reverse['street'], $ffbbAddress)
        ) {
            return [
                'venueId' => $venue->getId(),
                'reason' => self::REASON_OTHER_STREET,
                'ffbbAddress' => $ffbbAddress,
                'pointStreet' => $reverse['street'],
                'distanceM' => null,
            ];
        }

        // (b) — le point exact de l'adresse FFBB est-il loin du point stocké ?
        $query = null !== $salle['city'] && '' !== $salle['city'] ? $ffbbAddress . ', ' . $salle['city'] : $ffbbAddress;
        try {
            $candidates = $this->ban->geocode($query, 1);
        } catch (Throwable) {
            return null; // best-effort : la BAN indisponible n'est jamais une alerte
        }
        $top = $candidates[0] ?? null;
        if (null !== $top && 'housenumber' === $top['type']) {
            $distance = $this->haversineMeters($latitude, $longitude, $top['latitude'], $top['longitude']);
            if ($distance > self::FAR_THRESHOLD_METERS) {
                return [
                    'venueId' => $venue->getId(),
                    'reason' => self::REASON_FAR_FROM_ADDRESS,
                    'ffbbAddress' => $ffbbAddress,
                    'pointStreet' => null,
                    'distanceM' => (int) round($distance),
                ];
            }
        }

        return null;
    }

    /** Deux libellés de rue désignent-ils la MÊME rue (comparaison normalisée) ? */
    private function sameStreet(string $a, string $b): bool
    {
        $normalizedA = $this->normalizeStreet($a);

        return '' !== $normalizedA && $normalizedA === $this->normalizeStreet($b);
    }

    /**
     * Normalise un libellé de rue pour la comparaison : minuscules, accents retirés, virgules et
     * numéros de voie retirés (y compris « 251cours » collé), suffixes bis/ter/quater retirés,
     * espaces normalisés. Ainsi « 251cours Émile Zola » ≡ « Cours Emile Zola » et
     * « Rue Sèverine » ≡ « Rue Séverine ».
     */
    private function normalizeStreet(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $lower = mb_strtolower(false === $ascii ? $value : $ascii, 'UTF-8');
        // Chiffres retirés AVANT le repli non-alpha : « 251cours » (collé) redevient « cours ».
        $noDigits = (string) preg_replace('/[0-9]+/', ' ', str_replace(',', ' ', $lower));
        $lettersOnly = (string) preg_replace('/[^a-z]+/', ' ', $noDigits);
        $noAffix = (string) preg_replace('/\b(bis|ter|quater)\b/', ' ', $lettersOnly);

        return trim((string) preg_replace('/\s+/', ' ', $noAffix));
    }

    /** Distance haversine, en mètres, entre deux points (latitude/longitude en degrés). */
    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METERS * asin(min(1.0, sqrt($a)));
    }
}
