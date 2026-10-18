<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\UmamiStatsClient;
use App\Service\UmamiStatsException;
use App\Tests\Integration\Admin\AdminAudienceTest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Le client Umami de l'onglet « Statistiques » (P4-307) — la COUTURE sortante : tout ce qui
 * part vers l'instance Umami interne. Prouvé au plus bas (aucun serveur, aucune route) sur un
 * MockHttpClient qui enregistre méthode/URL/corps/entêtes/options de chaque requête :
 *
 *  - l'hôte est une CONSTANTE (`http://umami:3000`), jamais dérivé de la config ni d'une entrée ;
 *  - la clé API voyage UNIQUEMENT en `Authorization: Bearer …` — jamais dans l'URL, la query
 *    ni le corps, et {@see UmamiStatsException} est EXPURGÉE de la clé par construction ;
 *  - timeout serré (3 s), redirections coupées (`max_redirects` 0, SSRF-safe) ;
 *  - l'API v3 répond `{pageviews, visitors, visits, bounces, totaltime, comparison:{…}}` sur
 *    `/api/websites/{id}/stats`, et une LISTE `[{x, y}]` sur `/api/websites/{id}/metrics` —
 *    le client les parse en compteurs typés et en lignes `{value, count}` ;
 *  - clé OU identifiant de site absent → le client est INERTE ({@see UmamiStatsClient::isConfigured()}
 *    faux), aucune requête émise ;
 *  - une réponse non-2xx, une erreur réseau ou un JSON invalide → {@see UmamiStatsException}.
 *
 * Contrat FIXÉ pour NEO (noms suivis par l'implémentation) : const `UmamiStatsClient::API_HOST`,
 * constructeur `(HttpClientInterface $httpClient, string $apiKey, string $websiteId)`, méthodes
 * `isConfigured()`, `stats(int $startAtMs, int $endAtMs): array` (clés `visitors`, `visits`,
 * `pageviews` + sous-tableau `comparison` aux mêmes clés), `topMetrics(string $type,
 * int $startAtMs, int $endAtMs, int $limit): array` (liste `{value, count}`, bornée à $limit),
 * exception `App\Service\UmamiStatsException` (message expurgé) sur réponse non-2xx / transport /
 * JSON invalide.
 *
 * Doublon VOULU (point sensible « sécurité ») avec
 * {@see AdminAudienceTest::testTheApiKeyNeverLeaksIntoTheResponse} :
 * ici la clé ne fuite pas dans l'EXCEPTION du client ; là-bas elle ne fuite pas dans le JSON de
 * la route. Deux foyers, deux angles.
 */
#[Group('unit')]
final class UmamiStatsClientTest extends TestCase
{
    private const string API_KEY = 'umami_api_SECRETKEY_0000000000';

    private const string WEBSITE_ID = '11111111-2222-3333-4444-555555555555';

    public function testHostIsAFixedConstantNeverDerivedFromConfig(): void
    {
        self::assertSame('http://umami:3000', UmamiStatsClient::API_HOST);
    }

    public function testStatsHitsTheInternalHostAndParsesTheV3Payload(): void
    {
        $client = $this->client($recorded);

        $stats = $client->stats(1_700_000_000_000, 1_700_600_000_000);

        self::assertSame(300, $stats['visitors']);
        self::assertSame(450, $stats['visits']);
        self::assertSame(1200, $stats['pageviews']);
        // La période de comparaison (compare=prev) porte la tendance : elle est parsée telle quelle.
        self::assertSame(250, $stats['comparison']['visitors']);
        self::assertSame(1000, $stats['comparison']['pageviews']);

        $call = $this->firstCallMatching($recorded, '/stats');
        self::assertSame('GET', $call['method']);
        self::assertSame(
            UmamiStatsClient::API_HOST . '/api/websites/' . self::WEBSITE_ID . '/stats',
            $this->stripQuery($call['url']),
            'les stats partent vers l\'hôte interne const, chemin v3',
        );
        self::assertStringContainsString('startAt=1700000000000', $call['url'], 'la fenêtre est passée en epoch ms');
        self::assertStringContainsString('endAt=1700600000000', $call['url']);
    }

    public function testTopMetricsMapsRowsCapsToTheLimitAndKeysByType(): void
    {
        $client = $this->client($recorded);

        $pages = $client->topMetrics('path', 1_700_000_000_000, 1_700_600_000_000, 5);
        self::assertCount(5, $pages, 'au plus $limit lignes, même si Umami en renvoie davantage');
        self::assertSame(['value' => '/', 'count' => 500], $pages[0]);

        $pathCall = $this->firstCallMatching($recorded, 'type=path');
        self::assertSame('GET', $pathCall['method']);
        self::assertSame(
            UmamiStatsClient::API_HOST . '/api/websites/' . self::WEBSITE_ID . '/metrics',
            $this->stripQuery($pathCall['url']),
        );
        self::assertStringContainsString('limit=5', $pathCall['url'], 'le cap est demandé à Umami aussi');

        $sources = $client->topMetrics('referrer', 1_700_000_000_000, 1_700_600_000_000, 5);
        self::assertSame(['value' => 'google.com', 'count' => 300], $sources[0]);
        $this->firstCallMatching($recorded, 'type=referrer');
    }

    public function testTheApiKeyTravelsOnlyAsABearerHeaderNeverInUrlOrBody(): void
    {
        $client = $this->client($recorded);

        $client->stats(1_700_000_000_000, 1_700_600_000_000);
        $client->topMetrics('path', 1_700_000_000_000, 1_700_600_000_000, 5);

        self::assertNotSame([], $recorded, 'au moins une requête a été émise');
        foreach ($recorded as $call) {
            self::assertStringContainsString('Bearer ' . self::API_KEY, $call['authorization'], 'la clé voyage en Bearer');
            self::assertStringNotContainsString(self::API_KEY, $call['url'], 'la clé ne fuite jamais dans l\'URL / la query');
            self::assertStringNotContainsString(self::API_KEY, (string) $call['body'], 'ni dans le corps');
        }
    }

    public function testRequestsAreTimeBoundedAndNeverFollowRedirects(): void
    {
        $client = $this->client($recorded);
        $client->stats(1_700_000_000_000, 1_700_600_000_000);

        $call = $this->firstCallMatching($recorded, '/stats');
        self::assertSame(3.0, $call['timeout'], 'timeout serré de 3 s');
        self::assertSame(0, $call['maxRedirects'], 'aucune redirection suivie (SSRF-safe)');
    }

    public function testAnEmptyApiKeyOrWebsiteIdMakesTheClientInertWithoutAnyRequest(): void
    {
        $never = new MockHttpClient(static function (): MockResponse {
            self::fail('un client non configuré ne doit émettre AUCUNE requête');
        });

        self::assertFalse(new UmamiStatsClient($never, '', self::WEBSITE_ID)->isConfigured(), 'clé absente → inerte');
        self::assertFalse(new UmamiStatsClient($never, self::API_KEY, '')->isConfigured(), 'identifiant de site absent → inerte');
        self::assertTrue(new UmamiStatsClient($never, self::API_KEY, self::WEBSITE_ID)->isConfigured());
    }

    public function testANon2xxResponseThrowsAnExceptionThatNeverLeaksTheKey(): void
    {
        $client = $this->clientReturning(new MockResponse('{"message":"forbidden"}', ['http_code' => 403]));

        try {
            $client->stats(1_700_000_000_000, 1_700_600_000_000);
            self::fail('une réponse non-2xx doit lever UmamiStatsException');
        } catch (UmamiStatsException $exception) {
            self::assertStringNotContainsString(self::API_KEY, $exception->getMessage(), 'la clé ne fuite jamais dans le message');
            self::assertStringNotContainsString(self::API_KEY, (string) $exception, 'ni dans la trace rendue en chaîne');
        }
    }

    public function testATransportErrorIsWrappedIntoAnUmamiStatsException(): void
    {
        $client = $this->clientReturning(new MockResponse('', ['error' => 'connection refused']));

        $this->expectException(UmamiStatsException::class);
        $client->stats(1_700_000_000_000, 1_700_600_000_000);
    }

    public function testInvalidJsonIsWrappedIntoAnUmamiStatsException(): void
    {
        $client = $this->clientReturning(new MockResponse('<<< not json >>>', ['http_code' => 200]));

        $this->expectException(UmamiStatsException::class);
        $client->stats(1_700_000_000_000, 1_700_600_000_000);
    }

    /**
     * Client réel sur un MockHttpClient qui ROUTE par URL (stats vs metrics path/referrer) et
     * ENREGISTRE chaque requête (méthode, URL, corps, Authorization, timeout, max_redirects).
     *
     * @param array<int, array{method: string, url: string, body: ?string, authorization: string, timeout: ?float, maxRedirects: ?int}>|null $recorded
     */
    private function client(?array &$recorded): UmamiStatsClient
    {
        $recorded = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$recorded): MockResponse {
            $recorded[] = [
                'method' => $method,
                'url' => $url,
                'body' => \is_string($options['body'] ?? null) ? $options['body'] : null,
                'authorization' => implode(' ', (array) ($options['normalized_headers']['authorization'] ?? [])),
                'timeout' => isset($options['timeout']) ? (float) $options['timeout'] : null,
                'maxRedirects' => isset($options['max_redirects']) ? (int) $options['max_redirects'] : null,
            ];

            if (str_contains($url, '/stats')) {
                return new MockResponse((string) json_encode([
                    'pageviews' => 1200,
                    'visitors' => 300,
                    'visits' => 450,
                    'bounces' => 90,
                    'totaltime' => 50000,
                    'comparison' => ['pageviews' => 1000, 'visitors' => 250, 'visits' => 400, 'bounces' => 100, 'totaltime' => 40000],
                ]));
            }
            if (str_contains($url, 'type=referrer')) {
                return new MockResponse((string) json_encode([
                    ['x' => 'google.com', 'y' => 300],
                    ['x' => 'facebook.com', 'y' => 120],
                ]));
            }

            // type=path (top pages) : Umami en renvoie plus que la limite, le client doit couper.
            return new MockResponse((string) json_encode([
                ['x' => '/', 'y' => 500],
                ['x' => '/tarifs', 'y' => 200],
                ['x' => '/fonctionnalites', 'y' => 150],
                ['x' => '/contact', 'y' => 90],
                ['x' => '/mentions-legales', 'y' => 40],
                ['x' => '/blog', 'y' => 20],
                ['x' => '/cgu', 'y' => 10],
            ]));
        });

        return new UmamiStatsClient($http, self::API_KEY, self::WEBSITE_ID);
    }

    private function clientReturning(MockResponse $response): UmamiStatsClient
    {
        return new UmamiStatsClient(new MockHttpClient($response), self::API_KEY, self::WEBSITE_ID);
    }

    /**
     * @param array<int, array{method: string, url: string, body: ?string, authorization: string, timeout: ?float, maxRedirects: ?int}> $recorded
     *
     * @return array{method: string, url: string, body: ?string, authorization: string, timeout: ?float, maxRedirects: ?int}
     */
    private function firstCallMatching(array $recorded, string $needle): array
    {
        foreach ($recorded as $call) {
            if (str_contains($call['url'], $needle)) {
                return $call;
            }
        }

        self::fail(\sprintf('aucune requête enregistrée ne vise « %s »', $needle));
    }

    private function stripQuery(string $url): string
    {
        return explode('?', $url, 2)[0];
    }
}
