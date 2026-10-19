<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Tests\Integration\Admin\ScheduledDeploymentTest;
use App\Tests\Integration\Command\DeploymentTickCommandTest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Backend GitHub déterministe pour l'env de TEST : « mise en prod programmée » (PR b).
 * Les tests de route ({@see ScheduledDeploymentTest}) et de
 * commande ({@see DeploymentTickCommandTest}) doivent prouver
 * la mécanique de déploiement SANS jamais toucher api.github.com. Ce double sert, pilotable
 * par des propriétés STATIQUES :
 *
 *  - GET .../repos/{repo}/tags            → la liste {@see self::$tags} (name + commit sha) ;
 *  - GET .../workflows/ci.yml/runs        → un run unique à la conclusion {@see self::$ciConclusion} ;
 *  - POST .../workflows/deploy.yml/dispatches → 204 (ou 500 si {@see self::$dispatchFails}),
 *    en comptant les tentatives {@see self::$dispatchCount} et en capturant les inputs ;
 *  - GET .../workflows/deploy.yml/runs    → le run {@see self::$runId} resolu post-dispatch ;
 *  - GET .../actions/runs/{id}            → son statut/conclusion {@see self::$runConclusion}.
 *
 * NEO le câble dans services_test.yaml comme `$httpClient` de `App\Service\GitHubDeployClient`
 * (patron {@see FfbbHttpClientStub} ⇄ `FfbbApiClient`). Chaque test qui pose une statique la
 * remet à son défaut via {@see self::reset()} (état partagé dans le process).
 */
final class GitHubDeployHttpClientStub implements HttpClientInterface
{
    /**
     * Message BRUT d'une panne de transport (DNS, connexion refusée) — distinctif exprès : les
     * tests d'abus ({@see ScheduledDeploymentTest::testATransportFailureNeverEchoesTheRawErrorNorTheToken})
     * prouvent qu'il ne ressort JAMAIS dans une réponse HTTP ni un e-mail.
     */
    public const string TRANSPORT_ERROR = 'cURL error 7: Failed to connect to api.github.com port 443 — raw transport detail that must never surface';

    /**
     * Les tags servis par `GET /repos/{repo}/tags` : name + sha du commit pointé. Seuls les
     * `^v*` sont des versions déployables (le CLIENT filtre), mais le double peut servir du
     * bruit (`main`, `sha-abc`) pour prouver le filtre.
     *
     * @var list<array{name: string, sha: string}>
     */
    public static array $tags = [
        ['name' => 'v1.0.0', 'sha' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9001122334'],
    ];

    /** Conclusion du dernier run `ci.yml` du head_sha interrogé (CI verte = `success`). */
    public static string $ciConclusion = 'success';

    /**
     * Quand ≠ null, `GET .../tags` répond ce code HTTP NON-2xx (p. ex. 403/404 : jeton révoqué,
     * dépôt renommé) — GitHub « injoignable » au sens d'une réponse d'erreur. Le client lève alors
     * {@see GitHubDeployException} ; la route sert `{items:[], available:false}`, le tick réessaie.
     */
    public static ?int $tagsFailStatus = null;

    /**
     * Quand true, TOUTE requête échoue au niveau TRANSPORT (le `getStatusCode()`/`toArray()` lève
     * {@see TransportExceptionInterface} via l'info `error`
     * de la MockResponse) — GitHub réellement injoignable (réseau), pas une réponse d'erreur.
     */
    public static bool $transportFails = false;

    /** Quand true, le POST de dispatch `deploy.yml` répond 500 (échec de dispatch). */
    public static bool $dispatchFails = false;

    /** Nombre de dispatches `deploy.yml` tentés (pour prouver « aucun dispatch » sur refus). */
    public static int $dispatchCount = 0;

    /**
     * Les inputs du dernier dispatch capturé (`{version, maintenance_until}`), ou null.
     *
     * @var array<string, mixed>|null
     */
    public static ?array $lastDispatchInputs = null;

    /** Id du run `deploy.yml` resolu après dispatch. */
    public static ?int $runId = 987654321;

    /** Conclusion du run de déploiement (`null` = encore en cours, `success`/`failure` = terminé). */
    public static ?string $runConclusion = null;

    private readonly MockHttpClient $inner;

    public function __construct()
    {
        $this->inner = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            // Panne RÉSEAU (toute requête) : l'info `error` d'une MockResponse fait lever une
            // TransportException dès que le client touche la réponse (getStatusCode/toArray).
            if (self::$transportFails) {
                return new MockResponse('', ['error' => self::TRANSPORT_ERROR]);
            }
            if (str_contains($url, '/workflows/deploy.yml/dispatches')) {
                ++self::$dispatchCount;
                self::$lastDispatchInputs = $this->decodeInputs($options['body'] ?? null);

                return new MockResponse('', ['http_code' => self::$dispatchFails ? 500 : 204]);
            }
            if (str_contains($url, '/workflows/ci.yml/runs')) {
                return $this->json(['workflow_runs' => [['status' => 'completed', 'conclusion' => self::$ciConclusion]]]);
            }
            if (str_contains($url, '/workflows/deploy.yml/runs')) {
                return $this->json(['workflow_runs' => [[
                    'id' => self::$runId,
                    'status' => null === self::$runConclusion ? 'in_progress' : 'completed',
                    'conclusion' => self::$runConclusion,
                ]]]);
            }
            if (str_contains($url, '/actions/runs/')) {
                return $this->json([
                    'id' => self::$runId,
                    'status' => null === self::$runConclusion ? 'in_progress' : 'completed',
                    'conclusion' => self::$runConclusion,
                ]);
            }
            if (str_contains($url, '/tags')) {
                if (null !== self::$tagsFailStatus) {
                    // Réponse d'ERREUR (non-2xx) : le client lève GitHubDeployException.
                    return new MockResponse('', ['http_code' => self::$tagsFailStatus]);
                }

                return $this->json(array_map(
                    static fn (array $tag): array => ['name' => $tag['name'], 'commit' => ['sha' => $tag['sha']]],
                    self::$tags,
                ));
            }

            return $this->json([]);
        });
    }

    public static function reset(): void
    {
        self::$tags = [['name' => 'v1.0.0', 'sha' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9001122334']];
        self::$ciConclusion = 'success';
        self::$tagsFailStatus = null;
        self::$transportFails = false;
        self::$dispatchFails = false;
        self::$dispatchCount = 0;
        self::$lastDispatchInputs = null;
        self::$runId = 987654321;
        self::$runConclusion = null;
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

    /** @return array<string, mixed>|null */
    private function decodeInputs(mixed $body): ?array
    {
        if (!\is_string($body) || '' === $body) {
            return null;
        }
        $payload = json_decode($body, true);

        return \is_array($payload) && \is_array($payload['inputs'] ?? null) ? $payload['inputs'] : null;
    }

    /** @param array<mixed> $data */
    private function json(array $data): MockResponse
    {
        return new MockResponse((string) json_encode($data));
    }
}
