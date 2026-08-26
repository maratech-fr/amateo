<?php

declare(strict_types=1);

namespace App\Service\Geo;

use App\Service\Basketball\FfbbApiClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
    private const TIMEOUT = 5.0;

    private const QUERY_MIN = 3;
    private const QUERY_MAX = 200;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {}

    public static function isValidQuery(string $query): bool
    {
        $length = mb_strlen(trim($query));

        return $length >= self::QUERY_MIN && $length <= self::QUERY_MAX;
    }

    /**
     * Geocode a free-text address to at most $limit candidates. An invalid query
     * (too short/long) returns [] without any network call. Transport failures
     * propagate — the caller treats them best-effort (a 502, never a broken form).
     *
     * @return list<array{label: string, latitude: float, longitude: float, score: float}>
     */
    public function geocode(string $query, int $limit = 5): array
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
        ])->toArray(false);

        $features = $data['features'] ?? null;
        if (!\is_array($features)) {
            return [];
        }

        $candidates = [];
        foreach ($features as $feature) {
            $candidate = $this->mapFeature($feature);
            if (null !== $candidate) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * @return array{label: string, latitude: float, longitude: float, score: float}|null
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

        return [
            'label' => $label,
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'score' => is_numeric($score) ? (float) $score : 0.0,
        ];
    }
}
