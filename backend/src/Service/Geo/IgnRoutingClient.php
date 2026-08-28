<?php

declare(strict_types=1);

namespace App\Service\Geo;

use Symfony\Component\Clock\ClockInterface;
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

    /**
     * Global wall-clock budget for one {@see travelMinutesBatch} lot. The autofill
     * dispatches up to MAX_AUTOFILL_PAIRS × 2 profiles = 240 calls in windows of 8,
     * each with a 5 s per-call timeout but NO global cap — a fully degraded IGN
     * could hold the request ~150 s (30 windows × 5 s). Prod caps the whole PHP
     * request at 60 s (`max_execution_time`, docker/php/Dockerfile; nginx
     * `fastcgi_read_timeout 60s`, docker/nginx/default.conf), so this budget MUST
     * stay well under it. 30 s = half that ceiling: even if the last dispatched
     * window then blocks its full 5 s read, the lot returns by ~35 s, leaving room
     * for the DB reads, the flush and serialization. In the nominal case
     * (~140-230 ms/call) a full 240-call lot multiplexes in ~7 s and the budget
     * never bites — it only fires when IGN is degraded, and then the remaining
     * pairs come back so the manager can re-run to continue (best-effort intact).
     */
    public const float BATCH_BUDGET_SECONDS = 30.0;

    private const ITINERARY_URL = 'https://data.geopf.fr/navigation/itineraire';
    private const RESOURCE = 'bdtopo-osrm';
    private const TIMEOUT = 5.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ClockInterface $clock,
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
     * OR whose transport fails resolves to null in `minutes` — the caller lists
     * that pair as unresolved (routing_failed), never a global failure.
     *
     * GLOBAL BUDGET ({@see BATCH_BUDGET_SECONDS}): the first window always runs;
     * from the second on, once the wall-clock budget is spent we STOP dispatching
     * new windows and return the remaining jobs' keys in `budgetExceededKeys`. The
     * caller lists those pairs unresolved (budget_exceeded) so a degraded IGN can
     * never hold the request near the upstream 60 s ceiling — best-effort intact,
     * the manager re-runs to continue.
     *
     * @param list<array{key: string, profile: string, startLat: float, startLon: float, endLat: float, endLon: float}> $jobs
     *
     * @return array{minutes: array<string, int|null>, budgetExceededKeys: list<string>} minutes keyed by the job's `key` (null = routing failure); keys never attempted because the budget ran out
     */
    public function travelMinutesBatch(array $jobs, int $concurrency = 8, ?float $budgetSeconds = null): array
    {
        $budgetSeconds ??= self::BATCH_BUDGET_SECONDS;
        $deadline = (float) $this->clock->now()->format('U.u') + $budgetSeconds;

        $results = [];
        $budgetExceededKeys = [];
        $overBudget = false;

        foreach (array_chunk($jobs, max(1, $concurrency)) as $index => $window) {
            // The first window always dispatches; from the second on, stop once the
            // batch budget is spent (the elapsed time reflects the windows already
            // dispatched AND read above).
            if (!$overBudget && 0 !== $index && (float) $this->clock->now()->format('U.u') >= $deadline) {
                $overBudget = true;
            }
            if ($overBudget) {
                foreach ($window as $job) {
                    $budgetExceededKeys[] = $job['key'];
                }

                continue;
            }

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

        return ['minutes' => $results, 'budgetExceededKeys' => $budgetExceededKeys];
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
