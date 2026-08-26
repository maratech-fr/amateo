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
 * Géoplateforme. Returns a fixed duration by profile (car → 10 min, pedestrian →
 * 30 min after ceil), EXCEPT when either endpoint carries the « poison »
 * coordinate {@see self::POISON_COORD}: that request answers HTTP 500 with no
 * duration, so the pair resolves as unresolved (routing_failed) — proving the
 * best-effort per-pair behaviour without a network call.
 */
final class IgnRoutingHttpClientStub implements HttpClientInterface
{
    /** ceil(600/60) = 10 min in a car. */
    public const DRIVING_MINUTES = 10;
    /** ceil(1800/60) = 30 min on foot. */
    public const WALKING_MINUTES = 30;

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
