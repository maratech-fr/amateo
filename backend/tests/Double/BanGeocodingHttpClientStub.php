<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Deterministic BAN (Base Adresse Nationale) backend for the TEST env (wired in
 * services_test.yaml as BanGeocodingClient's HTTP client): geocoding tests must
 * never hit the real government API. Serves a fixed GeoJSON FeatureCollection of
 * two candidates whose label echoes the searched query.
 */
final class BanGeocodingHttpClientStub implements HttpClientInterface
{
    private readonly MockHttpClient $inner;

    public function __construct()
    {
        $this->inner = new MockHttpClient(function (string $method, string $url): MockResponse {
            $query = $this->queryParam($url, 'q');

            return new MockResponse((string) json_encode([
                'type' => 'FeatureCollection',
                'features' => [
                    $this->feature($query . ' — Gymnase A', 4.85, 45.75, 0.97),
                    $this->feature($query . ' — Gymnase B', 4.86, 45.76, 0.61),
                    // A malformed feature (no coordinates) the client must drop.
                    ['type' => 'Feature', 'properties' => ['label' => 'sans coord'], 'geometry' => []],
                ],
            ]));
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

    /**
     * @return array<string, mixed>
     */
    private function feature(string $label, float $longitude, float $latitude, float $score): array
    {
        return [
            'type' => 'Feature',
            // GeoJSON order: [longitude, latitude].
            'geometry' => ['type' => 'Point', 'coordinates' => [$longitude, $latitude]],
            'properties' => ['label' => $label, 'score' => $score],
        ];
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
