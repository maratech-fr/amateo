<?php

declare(strict_types=1);

namespace App\Service\Geo;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * SSRF-safe client for the IGN Géoplateforme routing API (data.geopf.fr): free,
 * key-less, 🇫🇷 (measured live 2026-08-26 — ~140-230 ms; `duration` in seconds,
 * `distance` in metres). Confined exactly like {@see BanGeocodingClient}: ONE fixed
 * host, hard-coded; coordinates are RANGE-validated and formatted SERVER-side
 * (sprintf %.6F from the venue columns — never a user string in the URL); redirects
 * disabled; tight timeout.
 *
 * P2-53 RMM-8 (PR-1) — used by the matrix autofill to fill AUTO travel minutes.
 * Profiles: `car` and `pedestrian` only — `bike` returns 400, do NOT use it.
 */
final class IgnRoutingClient
{
    public const PROFILE_CAR = 'car';
    public const PROFILE_PEDESTRIAN = 'pedestrian';

    private const ITINERARY_URL = 'https://data.geopf.fr/navigation/itineraire';
    private const RESOURCE = 'bdtopo-osrm';
    private const TIMEOUT = 5.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {}

    /**
     * Fastest travel time (rounded UP to the minute) for $profile between two
     * points, or null when the coordinates are out of range, the response carries
     * no numeric duration, or the transport fails (best-effort — the matrix is
     * an assistance, a single failed pair never breaks the gesture).
     */
    public function travelMinutes(string $profile, float $startLat, float $startLon, float $endLat, float $endLon): ?int
    {
        $response = $this->request($profile, $startLat, $startLon, $endLat, $endLon);

        return $response instanceof ResponseInterface ? $this->readMinutes($response) : null;
    }

    /**
     * Concurrent variant for the matrix autofill: dispatches the jobs in windows
     * of $concurrency (multiplexed by the HttpClient) and reads each result. A job
     * whose coordinates are out of range, whose response has no numeric duration,
     * OR whose transport fails resolves to null — the caller lists that pair as
     * unresolved, never a global failure.
     *
     * @param list<array{key: string, profile: string, startLat: float, startLon: float, endLat: float, endLon: float}> $jobs
     *
     * @return array<string, int|null> minutes keyed by the job's `key` (null = unresolved)
     */
    public function travelMinutesBatch(array $jobs, int $concurrency = 8): array
    {
        $results = [];
        foreach (array_chunk($jobs, max(1, $concurrency)) as $window) {
            /** @var array<string, ResponseInterface> $responses */
            $responses = [];
            foreach ($window as $job) {
                $response = $this->request($job['profile'], $job['startLat'], $job['startLon'], $job['endLat'], $job['endLon']);
                if (!$response instanceof ResponseInterface) {
                    $results[$job['key']] = null;

                    continue;
                }
                $responses[$job['key']] = $response;
            }

            foreach ($responses as $key => $response) {
                $results[$key] = $this->readMinutes($response);
            }
        }

        return $results;
    }

    private function request(string $profile, float $startLat, float $startLon, float $endLat, float $endLon): ?ResponseInterface
    {
        if (self::PROFILE_CAR !== $profile && self::PROFILE_PEDESTRIAN !== $profile) {
            return null;
        }
        if (!$this->inRange($startLat, $startLon) || !$this->inRange($endLat, $endLon)) {
            return null;
        }

        // Lazy: the request starts here but is only read later — firing a whole
        // window before reading is what multiplexes them.
        return $this->httpClient->request('GET', self::ITINERARY_URL, [
            'query' => [
                'resource' => self::RESOURCE,
                'profile' => $profile,
                'optimization' => 'fastest',
                // The API expects "lon,lat"; both formatted server-side (%.6F is
                // locale-independent — never a comma decimal separator).
                'start' => \sprintf('%.6F,%.6F', $startLon, $startLat),
                'end' => \sprintf('%.6F,%.6F', $endLon, $endLat),
            ],
            'headers' => ['Accept' => 'application/json'],
            'timeout' => self::TIMEOUT,
            'max_duration' => self::TIMEOUT,
            'max_redirects' => 0,
        ]);
    }

    private function readMinutes(ResponseInterface $response): ?int
    {
        try {
            $data = $response->toArray(false);
        } catch (ExceptionInterface) {
            // Transport/decoding failure on THIS pair: best-effort, the caller
            // lists it unresolved rather than failing the whole autofill.
            return null;
        }

        $duration = $data['duration'] ?? null;
        if (!is_numeric($duration) || (float) $duration < 0) {
            return null;
        }

        return (int) ceil((float) $duration / 60);
    }

    private function inRange(float $latitude, float $longitude): bool
    {
        return $latitude >= -90.0 && $latitude <= 90.0 && $longitude >= -180.0 && $longitude <= 180.0;
    }
}
