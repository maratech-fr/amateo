<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Tests\Integration\Admin\AdminAudienceTest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Backend Umami déterministe pour l'env de TEST : onglet « Statistiques » (P4-307).
 * La route {@see AdminAudienceTest} doit prouver sa mécanique (états, cache, non-fuite de la
 * clé) SANS jamais toucher l'instance Umami. Ce double sert, pilotable par des propriétés
 * STATIQUES :
 *
 *  - GET .../websites/{id}/stats                 → {@see self::$stats} (pageviews/visitors/…
 *    + comparison, forme v3) ;
 *  - GET .../websites/{id}/metrics?type=referrer → {@see self::$sources} (lignes {x, y}) ;
 *  - GET .../websites/{id}/metrics (type=path)   → {@see self::$pages} (lignes {x, y}) ;
 *  - {@see self::$fails} true → toute réponse est 500 (Umami KO → « indisponible ») ;
 *  - {@see self::$requestCount} compte les requêtes émises → prouve qu'un 2ᵉ appel dans la
 *    fenêtre de cache NE rappelle PAS Umami.
 *
 * NEO le câble dans services_test.yaml comme `$httpClient` de `App\Service\UmamiStatsClient`
 * (patron {@see GitHubDeployHttpClientStub} ⇄ `GitHubDeployClient`). Chaque test remet l'état
 * partagé du process à son défaut via {@see self::reset()}.
 *
 * ⚠ La comparaison est VOLONTAIREMENT inférieure au courant (250 < 300, 1000 < 1200…) : la
 * tendance calculée par le service est alors strictement positive, quelle que soit la métrique
 * retenue — c'est l'assertion de sens d'`AdminAudienceTest`.
 */
final class UmamiHttpClientStub implements HttpClientInterface
{
    /**
     * Stats v3 servies par `/stats` : compteurs du courant + `comparison` (période précédente).
     *
     * @var array<string, mixed>
     */
    public static array $stats = [
        'pageviews' => 1200,
        'visitors' => 300,
        'visits' => 450,
        'bounces' => 90,
        'totaltime' => 50000,
        'comparison' => ['pageviews' => 1000, 'visitors' => 250, 'visits' => 400, 'bounces' => 100, 'totaltime' => 40000],
    ];

    /**
     * Top pages (`type=path`) : lignes brutes `{x: chemin, y: compte}`.
     *
     * @var list<array{x: string, y: int}>
     */
    public static array $pages = [
        ['x' => '/', 'y' => 500],
        ['x' => '/tarifs', 'y' => 200],
        ['x' => '/fonctionnalites', 'y' => 150],
        ['x' => '/contact', 'y' => 90],
        ['x' => '/mentions-legales', 'y' => 40],
        ['x' => '/blog', 'y' => 20],
    ];

    /**
     * Top sources (`type=referrer`) : lignes brutes `{x: référent, y: compte}`.
     *
     * @var list<array{x: string, y: int}>
     */
    public static array $sources = [
        ['x' => 'google.com', 'y' => 300],
        ['x' => 'facebook.com', 'y' => 120],
        ['x' => 'instagram.com', 'y' => 60],
    ];

    /** Quand true, toute requête répond 500 (Umami injoignable / en erreur). */
    public static bool $fails = false;

    /** Nombre de requêtes émises vers Umami (preuve « le cache n'a pas rappelé Umami »). */
    public static int $requestCount = 0;

    private readonly MockHttpClient $inner;

    public function __construct()
    {
        $this->inner = new MockHttpClient(function (string $method, string $url): MockResponse {
            ++self::$requestCount;

            if (self::$fails) {
                return new MockResponse('{"error":"unavailable"}', ['http_code' => 500]);
            }
            if (str_contains($url, '/stats')) {
                return $this->json(self::$stats);
            }
            if (str_contains($url, 'type=referrer')) {
                return $this->json(self::$sources);
            }

            return $this->json(self::$pages);
        });
    }

    public static function reset(): void
    {
        self::$stats = [
            'pageviews' => 1200,
            'visitors' => 300,
            'visits' => 450,
            'bounces' => 90,
            'totaltime' => 50000,
            'comparison' => ['pageviews' => 1000, 'visitors' => 250, 'visits' => 400, 'bounces' => 100, 'totaltime' => 40000],
        ];
        self::$pages = [
            ['x' => '/', 'y' => 500],
            ['x' => '/tarifs', 'y' => 200],
            ['x' => '/fonctionnalites', 'y' => 150],
            ['x' => '/contact', 'y' => 90],
            ['x' => '/mentions-legales', 'y' => 40],
            ['x' => '/blog', 'y' => 20],
        ];
        self::$sources = [
            ['x' => 'google.com', 'y' => 300],
            ['x' => 'facebook.com', 'y' => 120],
            ['x' => 'instagram.com', 'y' => 60],
        ];
        self::$fails = false;
        self::$requestCount = 0;
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

    /** @param array<mixed> $data */
    private function json(array $data): MockResponse
    {
        return new MockResponse((string) json_encode($data));
    }
}
