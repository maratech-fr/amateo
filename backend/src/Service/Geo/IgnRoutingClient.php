<?php

declare(strict_types=1);

namespace App\Service\Geo;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
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
 *
 * PACING (measured quota 2026): the endpoint answers `x-ratelimit-limit-second: 1`
 * and returns 429 after ~9 quick calls — so requests are PACED at one per second on
 * the injected {@see ClockInterface} (the last-dispatch instant is remembered; a
 * request that would come too soon `sleep`s the difference on the clock). This
 * replaces the old concurrent windows: calls are now serial, single-threaded, and a
 * 429 is retried (Retry-After, else 1 s) up to {@see MAX_ATTEMPTS} times before the
 * pair is given up as null. Under a {@see MockClock} the
 * sleeps advance mock time, so the pacing/retry are exercised without a real wait.
 */
final class IgnRoutingClient
{
    public const PROFILE_CAR = 'car';
    public const PROFILE_PEDESTRIAN = 'pedestrian';

    /**
     * Global wall-clock budget for one {@see travelMinutesBatch} lot. Requests are
     * PACED at ~1/s (see the class docblock), so a full lot of N calls takes ~N s —
     * this budget stops the lot once the wall-clock is spent and returns the
     * not-yet-attempted keys in `budgetExceededKeys` (the caller lists them
     * `budget_exceeded` — « re-run to continue », best-effort intact). It MUST stay
     * well under the upstream ceilings (prod `max_execution_time = 60`,
     * `fastcgi_read_timeout 60s`): 30 s = half of them, leaving room for the DB
     * reads, the flush and serialization. The async worker (a background job) passes
     * its own, larger budget.
     */
    public const float BATCH_BUDGET_SECONDS = 30.0;

    /** One request per second (the measured `x-ratelimit-limit-second`). */
    private const float MIN_INTERVAL_SECONDS = 1.0;

    /** A 429 is retried at most this many times (Retry-After, else 1 s) before null. */
    private const int MAX_ATTEMPTS = 3;

    private const string ITINERARY_URL = 'https://data.geopf.fr/navigation/itineraire';
    private const string RESOURCE = 'bdtopo-osrm';
    private const float TIMEOUT = 5.0;

    /** The instant (Unix seconds, µs precision) of the last dispatched request, for pacing. */
    private ?float $lastDispatchAt = null;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ClockInterface $clock,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    /**
     * Fastest travel time (rounded UP to the minute) for $profile between two
     * points, or null when the coordinates are out of range, the response carries
     * no numeric duration, the transport fails, or the endpoint stays rate-limited
     * after {@see MAX_ATTEMPTS} tries (best-effort — the matrix is an assistance, a
     * single failed pair never breaks the gesture). The call is PACED (~1/s).
     */
    public function travelMinutes(string $profile, float $startLat, float $startLon, float $endLat, float $endLon): ?int
    {
        return $this->pacedMinutes($profile, $startLat, $startLon, $endLat, $endLon);
    }

    /**
     * Serial, PACED variant for the matrix autofill: dispatches the jobs one at a
     * time (~1/s) and reads each result. A job whose coordinates are out of range,
     * whose response has no numeric duration, whose transport fails, OR which stays
     * 429 after {@see MAX_ATTEMPTS} resolves to null in `minutes` — the caller lists
     * that pair unresolved (routing_failed), never a global failure.
     *
     * GLOBAL BUDGET ({@see BATCH_BUDGET_SECONDS}, or $budgetSeconds): the first job
     * always runs; from the second on, once the wall-clock budget is spent we STOP
     * attempting the remaining jobs and return their keys in `budgetExceededKeys`.
     * The caller lists those pairs unresolved (budget_exceeded) so a degraded/slow
     * IGN can never hold the request near the upstream 60 s ceiling.
     *
     * $concurrency is kept for signature stability but IGNORED: the endpoint's
     * one-per-second quota makes concurrency counter-productive (it only earns 429s),
     * so the lot is single-threaded and paced.
     *
     * $onProgress, si fourni, est appelé APRÈS chaque job traité avec (jobs traités,
     * total) — le worker asynchrone (C6) s'en sert pour publier l'avancement.
     *
     * @param list<array{key: string, profile: string, startLat: float, startLon: float, endLat: float, endLon: float}> $jobs
     * @param (callable(int, int): void)|null                                                                           $onProgress
     *
     * @return array{minutes: array<string, int|null>, budgetExceededKeys: list<string>} minutes keyed by the job's `key` (null = routing failure); keys never attempted because the budget ran out
     */
    public function travelMinutesBatch(array $jobs, int $concurrency = 8, ?float $budgetSeconds = null, ?callable $onProgress = null): array
    {
        unset($concurrency); // serial + paced; kept only for API stability (see docblock).
        $budgetSeconds ??= self::BATCH_BUDGET_SECONDS;
        $deadline = $this->nowSeconds() + $budgetSeconds;
        $total = \count($jobs);

        $results = [];
        $budgetExceededKeys = [];
        $overBudget = false;
        $processed = 0;

        foreach ($jobs as $index => $job) {
            // The first job always runs; from the second on, stop once the batch
            // budget is spent (elapsed time already reflects the paced calls above).
            if (!$overBudget && 0 !== $index && $this->nowSeconds() >= $deadline) {
                $overBudget = true;
            }
            if ($overBudget) {
                $budgetExceededKeys[] = $job['key'];

                continue;
            }

            $results[$job['key']] = $this->pacedMinutes(
                $job['profile'],
                $job['startLat'],
                $job['startLon'],
                $job['endLat'],
                $job['endLon'],
            );
            ++$processed;
            if (null !== $onProgress) {
                $onProgress($processed, $total);
            }
        }

        return ['minutes' => $results, 'budgetExceededKeys' => $budgetExceededKeys];
    }

    /**
     * One paced request with 429 retry. Returns the minutes, or null on invalid
     * input, transport failure, non-numeric duration, or exhausted retries.
     */
    private function pacedMinutes(string $profile, float $startLat, float $startLon, float $endLat, float $endLon): ?int
    {
        if (self::PROFILE_CAR !== $profile && self::PROFILE_PEDESTRIAN !== $profile) {
            return null;
        }
        if (!$this->inRange($startLat, $startLon) || !$this->inRange($endLat, $endLon)) {
            return null;
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            $this->pace();

            try {
                $response = $this->httpClient->request('GET', self::ITINERARY_URL, [
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
                // Read the STATUS before the body: a 429 is a retryable rate-limit,
                // not a decode error (the old code let `toArray(false)` swallow it as
                // a silent null). getStatusCode() blocks until the headers arrive.
                $status = $response->getStatusCode();
            } catch (TransportExceptionInterface $exception) {
                $this->logger->warning('IGN routing request failed (transport)', [
                    'profile' => $profile,
                    'attempt' => $attempt,
                    'exception' => $exception->getMessage(),
                ]);

                return null;
            }

            if (429 === $status) {
                $retryAfter = $this->retryAfterSeconds($response);
                $this->logger->warning('IGN routing rate-limited (HTTP 429)', [
                    'profile' => $profile,
                    'attempt' => $attempt,
                    'retryAfterSeconds' => $retryAfter,
                ]);
                if ($attempt < self::MAX_ATTEMPTS) {
                    $this->clock->sleep($retryAfter);

                    continue;
                }

                return null;
            }

            return $this->readMinutes($response, $profile, $attempt);
        }

        return null;
    }

    /** Sleep on the injected clock so at least MIN_INTERVAL_SECONDS separates two dispatches. */
    private function pace(): void
    {
        $now = $this->nowSeconds();
        if (null !== $this->lastDispatchAt) {
            $wait = self::MIN_INTERVAL_SECONDS - ($now - $this->lastDispatchAt);
            if ($wait > 0.0) {
                $this->clock->sleep($wait);
                $now += $wait; // the clock advanced by $wait — avoid a second now() read.
            }
        }
        $this->lastDispatchAt = $now;
    }

    /** `Retry-After` in seconds if the header is a positive integer, else 1 s. */
    private function retryAfterSeconds(ResponseInterface $response): float
    {
        try {
            $headers = $response->getHeaders(false);
        } catch (ExceptionInterface) {
            return self::MIN_INTERVAL_SECONDS;
        }
        $raw = $headers['retry-after'][0] ?? null;
        if (null !== $raw && ctype_digit($raw) && (int) $raw > 0) {
            return (float) (int) $raw;
        }

        return self::MIN_INTERVAL_SECONDS;
    }

    private function readMinutes(ResponseInterface $response, string $profile, int $attempt): ?int
    {
        try {
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning('IGN routing non-2xx response', [
                    'profile' => $profile,
                    'attempt' => $attempt,
                    'status' => $status,
                ]);

                return null;
            }
            $data = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            // Transport/decoding failure on THIS pair: best-effort, the caller lists
            // it unresolved rather than failing the whole autofill.
            $this->logger->warning('IGN routing decode failed', [
                'profile' => $profile,
                'attempt' => $attempt,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        $duration = $data['duration'] ?? null;
        if (!is_numeric($duration) || (float) $duration < 0) {
            return null;
        }

        return (int) ceil((float) $duration / 60);
    }

    private function nowSeconds(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }

    private function inRange(float $latitude, float $longitude): bool
    {
        return $latitude >= -90.0 && $latitude <= 90.0 && $longitude >= -180.0 && $longitude <= 180.0;
    }
}
