<?php

declare(strict_types=1);

namespace App\Service\Geo;

use App\Service\Basketball\FfbbApiClient;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * SSRF-safe client for the Base Adresse Nationale (BAN, api-adresse.data.gouv.fr):
 * the free, public, 🇫🇷 government geocoder — RGPD-coherent, confined exactly like
 * {@see FfbbApiClient}. ONE fixed host, hard-coded (never
 * derived from input); the query is length/format-validated before any call;
 * redirects are disabled (max_redirects=0) so a compromised endpoint cannot bounce
 * us to an internal address; a tight timeout bounds each call.
 *
 * P2-53 RMM-8 — géocodage d'une adresse de gymnase à la saisie (jamais au solve) :
 * la latitude/longitude finit en colonnes numériques sur le gymnase, le payload
 * reste des nombres et l'engine ne parle à personne.
 */
final class BanGeocodingClient
{
    private const SEARCH_URL = 'https://api-adresse.data.gouv.fr/search/';
    private const REVERSE_URL = 'https://api-adresse.data.gouv.fr/reverse/';
    private const TIMEOUT = 5.0;

    private const QUERY_MIN = 3;
    private const QUERY_MAX = 200;

    /**
     * Response-size ceiling (1 MiB). The BAN answers a handful of address candidates —
     * a few KiB at most; a response an order of magnitude larger means a compromised or
     * misbehaving endpoint, and reading it whole would be a memory-exhaustion vector. The
     * download is ABORTED past this cap (via `on_progress`); the exception propagates like
     * any transport failure, so the caller returns a 502 best-effort, never a broken form.
     */
    private const int MAX_RESPONSE_BYTES = 1_048_576;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {}

    public static function isValidQuery(string $query): bool
    {
        $length = mb_strlen(trim($query));

        return $length >= self::QUERY_MIN && $length <= self::QUERY_MAX;
    }

    /** Des coordonnées géographiques plausibles (latitude ∈ [-90, 90], longitude ∈ [-180, 180]). */
    public static function isValidCoordinate(float $latitude, float $longitude): bool
    {
        return $latitude >= -90.0 && $latitude <= 90.0 && $longitude >= -180.0 && $longitude <= 180.0;
    }

    /**
     * Reverse-geocode des coordonnées vers le LIBELLÉ d'adresse le plus proche (BAN /reverse/,
     * requête SSRF-safe : hôte fixe hard-codé, timeout serré, pas de redirection, plafond de
     * taille identique à la recherche). BEST-EFFORT : `null` si l'appel échoue, si la réponse
     * est vide ou si aucune adresse exploitable — jamais d'exception propagée. Aucune écriture :
     * l'adresse retrouvée est destinée à l'AFFICHAGE, elle n'est jamais stockée.
     */
    public function reverse(float $latitude, float $longitude): ?string
    {
        if (!self::isValidCoordinate($latitude, $longitude)) {
            return null;
        }

        try {
            $data = $this->httpClient->request('GET', self::REVERSE_URL, [
                'query' => ['lat' => $latitude, 'lon' => $longitude],
                'headers' => ['Accept' => 'application/json'],
                'timeout' => self::TIMEOUT,
                'max_duration' => self::TIMEOUT,
                'max_redirects' => 0,
                'on_progress' => static function (int $dlNow): void {
                    if ($dlNow > self::MAX_RESPONSE_BYTES) {
                        throw new RuntimeException(\sprintf('Réponse BAN trop volumineuse (> %d octets).', self::MAX_RESPONSE_BYTES));
                    }
                },
            ])->toArray(false);
        } catch (Throwable) {
            return null; // best-effort : un échec réseau/HTTP n'est jamais une erreur ici.
        }

        $features = $data['features'] ?? null;
        if (!\is_array($features)) {
            return null;
        }
        foreach (array_values($features) as $feature) {
            $simple = $this->mapFeature($feature);
            if (null !== $simple) {
                return $simple['label'];
            }
        }

        return null;
    }

    /**
     * Geocode a free-text address to at most $limit candidates. An invalid query
     * (too short/long) returns [] without any network call. Transport failures
     * propagate — the caller treats them best-effort (a 502, never a broken form).
     *
     * @return list<array{label: string, latitude: float, longitude: float, score: float, type: string|null}>
     */
    public function geocode(string $query, int $limit = 5): array
    {
        $candidates = [];
        foreach ($this->fetchFeatures($query, $limit) as $feature) {
            $candidate = $this->mapFeature($feature);
            if (null !== $candidate) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * The single BEST candidate for an address, with its STRUCTURED fields (postcode/city
     * from the BAN properties, when present). Used to set a club SIÈGE from a chosen address:
     * the coordinates are the federal geocoder's, never the caller's (a forged latitude in a
     * request body is ignored). Null = invalid query or no usable feature. Transport failures
     * propagate — the caller returns a 502, never a broken form.
     *
     * @return array{label: string, postalCode: string|null, city: string|null, latitude: float, longitude: float}|null
     */
    public function geocodeTop(string $query): ?array
    {
        foreach ($this->fetchFeatures($query, 1) as $feature) {
            $structured = $this->mapFeatureStructured($feature);
            if (null !== $structured) {
                return $structured;
            }
        }

        return null;
    }

    /**
     * The raw BAN features for a query (SSRF-safe request). An invalid query (too short/long)
     * returns [] without any network call.
     *
     * @return list<mixed>
     */
    private function fetchFeatures(string $query, int $limit): array
    {
        if (!self::isValidQuery($query)) {
            return [];
        }

        $data = $this->httpClient->request('GET', self::SEARCH_URL, [
            'query' => ['q' => trim($query), 'limit' => max(1, min(5, $limit))],
            'headers' => ['Accept' => 'application/json'],
            'timeout' => self::TIMEOUT,
            'max_duration' => self::TIMEOUT,
            'max_redirects' => 0,
            // Abort past the size ceiling: a runaway response never gets read whole.
            'on_progress' => static function (int $dlNow): void {
                if ($dlNow > self::MAX_RESPONSE_BYTES) {
                    throw new RuntimeException(\sprintf('Réponse BAN trop volumineuse (> %d octets).', self::MAX_RESPONSE_BYTES));
                }
            },
        ])->toArray(false);

        $features = $data['features'] ?? null;

        return \is_array($features) ? array_values($features) : [];
    }

    /**
     * @return array{label: string, postalCode: string|null, city: string|null, latitude: float, longitude: float}|null
     */
    private function mapFeatureStructured(mixed $feature): ?array
    {
        $simple = $this->mapFeature($feature);
        if (null === $simple) {
            return null;
        }
        $properties = \is_array($feature) && \is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $postcode = $properties['postcode'] ?? null;
        $city = $properties['city'] ?? null;

        return [
            'label' => $simple['label'],
            'postalCode' => \is_string($postcode) && '' !== $postcode ? $postcode : null,
            'city' => \is_string($city) && '' !== $city ? $city : null,
            'latitude' => $simple['latitude'],
            'longitude' => $simple['longitude'],
        ];
    }

    /**
     * @return array{label: string, latitude: float, longitude: float, score: float, type: string|null}|null
     */
    private function mapFeature(mixed $feature): ?array
    {
        if (!\is_array($feature)) {
            return null;
        }
        $properties = \is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $geometry = \is_array($feature['geometry'] ?? null) ? $feature['geometry'] : [];
        $coordinates = \is_array($geometry['coordinates'] ?? null) ? $geometry['coordinates'] : [];

        $label = $properties['label'] ?? null;
        // GeoJSON order is [longitude, latitude].
        $longitude = $coordinates[0] ?? null;
        $latitude = $coordinates[1] ?? null;
        if (!\is_string($label) || '' === $label || !is_numeric($longitude) || !is_numeric($latitude)) {
            return null;
        }

        $score = $properties['score'] ?? null;
        // BAN `type` : housenumber | street | locality | municipality — la PRÉCISION du point. Le
        // front avertit « Position approximative (rue entière) » quand ce n'est pas un housenumber.
        $type = $properties['type'] ?? null;

        return [
            'label' => $label,
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'score' => is_numeric($score) ? (float) $score : 0.0,
            'type' => \is_string($type) && '' !== $type ? $type : null,
        ];
    }
}
