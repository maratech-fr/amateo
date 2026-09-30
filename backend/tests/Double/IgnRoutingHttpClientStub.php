<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Deterministic IGN routing backend for the TEST env (wired in services_test.yaml
 * as IgnRoutingClient's HTTP client): the matrix autofill must never hit the real
 * Géoplateforme. Returns a fixed duration + distance by profile. Car → 10 min (from
 * duration). Non-vehicled mode (« pedestrian » profile) is now the BIKE: its AUTO time is
 * DISTANCE-based — ceil(4200 m / 250) + 5 = 22 min (décision fondateur 2026-09-30), NOT the
 * pedestrian duration. Poison coordinate {@see self::POISON_COORD}: HTTP 500 with no
 * duration/distance, so the pair resolves as unresolved (routing_failed) — proving the
 * best-effort per-pair behaviour without a network call.
 */
final class IgnRoutingHttpClientStub implements HttpClientInterface
{
    /** ceil(600/60) = 10 min in a car (from IGN `duration`). */
    public const DRIVING_MINUTES = 10;
    /** ceil(4200/250) + 5 = 22 min à vélo (from IGN `distance`, 15 km/h + 5 min marge). */
    public const WALKING_MINUTES = 22;
    /** The pedestrian-route DISTANCE (metres) the stub returns — the bike time derives from it. */
    public const WALKING_DISTANCE_METERS = 4200;

    /** A venue latitude/longitude the stub recognises to force a routing failure. */
    public const POISON_COORD = '1.234567';

    private readonly MockHttpClient $inner;

    public function __construct()
    {
        $this->inner = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, self::POISON_COORD)) {
                return new MockResponse('{}', ['http_code' => 500]);
            }
            $profile = $this->queryParam($url, 'profile');
            $duration = 'pedestrian' === $profile ? 1800 : 600;

            return new MockResponse((string) json_encode(['duration' => $duration, 'distance' => 4200]));
        });
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->inner->request($method, $url, $options);
    }

    public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return $this;
    }

    private function queryParam(string $url, string $name): string
    {
        $query = parse_url($url, \PHP_URL_QUERY);
        if (!\is_string($query)) {
            return '';
        }
        parse_str($query, $params);
        $value = $params[$name] ?? '';

        return \is_string($value) ? $value : '';
    }
}
