<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\GitHubDeployClient;
use App\Service\GitHubDeployException;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Le client GitHub de la « mise en prod programmée » (PR b) — la COUTURE sortante : tout ce
 * qui part vers api.github.com. Prouvé au plus bas (aucun serveur, aucune route) sur un
 * MockHttpClient qui enregistre méthode/URL/corps/entêtes de chaque requête :
 *
 *  - l'hôte est FIXE (const `https://api.github.com`), jamais dérivé d'une entrée utilisateur ;
 *  - seuls les tags `^v*` sont des versions déployables (le reste du dépôt est ignoré) ;
 *  - CI verte = dernier run `ci.yml` du head_sha du tag à conclusion `success` ;
 *  - le dispatch POST vise `…/workflows/deploy.yml/dispatches`, ref `main`, inputs
 *    `{version, maintenance_until}` avec un `maintenance_until` en ISO-8601 STRICT ;
 *  - le jeton voyage en `Authorization: Bearer …` mais ne fuite JAMAIS dans l'exception d'une
 *    réponse 500 (ni, par construction, dans un log) ;
 *  - jeton absent → le client est INERTE (`isConfigured()` faux), aucune requête tentée.
 *
 * Contrat FIXÉ pour NEO (noms suivis par l'implémentation) : const `GitHubDeployClient::API_HOST`,
 * constructeur `(HttpClientInterface $httpClient, string $deployToken, string $deployRepository)`,
 * méthodes `isConfigured()`, `tags()`, `isCiGreen(string $version)`,
 * `dispatchDeploy(string $version, DateTimeImmutable $maintenanceUntil)`,
 * `latestDeployRunId(string $version)`, `runConclusion(int $runId)`, exception
 * `App\Service\GitHubDeployException` sur réponse non-2xx (message EXPURGÉ du jeton).
 */
#[Group('unit')]
final class GitHubDeployClientTest extends TestCase
{
    private const string TOKEN = 'github_pat_11SECRETTOKENdeploy0000';

    private const string REPOSITORY = 'maratech-fr/amateo';

    public function testHostIsAFixedConstantNeverDerivedFromInput(): void
    {
        self::assertSame('https://api.github.com', GitHubDeployClient::API_HOST);
    }

    public function testOnlyVstarTagsAreDeployableVersions(): void
    {
        $client = $this->client($recorded, [
            'tags' => $this->tagsResponse([
                ['name' => 'v1.2.3', 'sha' => 'aaa1111'],
                ['name' => 'main', 'sha' => 'bbb2222'],
                ['name' => 'v2.0.0', 'sha' => 'ccc3333'],
                ['name' => 'sha-deadbee', 'sha' => 'ddd4444'],
                ['name' => '2.1.0', 'sha' => 'eee5555'],
            ]),
        ]);

        self::assertSame(['v1.2.3', 'v2.0.0'], array_values($client->tags()), 'seuls les tags ^v sont des versions déployables');

        $tagsCall = $this->firstCallMatching($recorded, '/tags');
        self::assertSame('GET', $tagsCall['method']);
        self::assertSame(GitHubDeployClient::API_HOST . '/repos/' . self::REPOSITORY . '/tags', $this->stripQuery($tagsCall['url']));
    }

    public function testCiIsGreenWhenTheLatestCiRunOfTheTagHeadShaSucceeded(): void
    {
        $green = $this->client($recorded, [
            'tags' => $this->tagsResponse([['name' => 'v1.2.3', 'sha' => 'head9999']]),
            'ci' => new MockResponse((string) json_encode(['workflow_runs' => [['status' => 'completed', 'conclusion' => 'success']]])),
        ]);
        self::assertTrue($green->isCiGreen('v1.2.3'));
        // Le run CI est interrogé sur le head_sha du tag, jamais « au hasard ».
        $ciCall = $this->firstCallMatching($recorded, '/workflows/ci.yml/runs');
        self::assertStringContainsString('head_sha=head9999', $ciCall['url']);

        $red = $this->client($unused, [
            'tags' => $this->tagsResponse([['name' => 'v1.2.3', 'sha' => 'head9999']]),
            'ci' => new MockResponse((string) json_encode(['workflow_runs' => [['status' => 'completed', 'conclusion' => 'failure']]])),
        ]);
        self::assertFalse($red->isCiGreen('v1.2.3'), 'un dernier run ci.yml en échec = CI rouge');
    }

    public function testDispatchTargetsTheDeployWorkflowWithRefMainAndIsoStrictInputs(): void
    {
        $client = $this->client($recorded, ['dispatch' => new MockResponse('', ['http_code' => 204])]);
        $maintenanceUntil = new DateTimeImmutable('2026-10-19T02:00:00+02:00');

        $client->dispatchDeploy('v1.2.3', $maintenanceUntil);

        $dispatch = $this->firstCallMatching($recorded, '/workflows/deploy.yml/dispatches');
        self::assertSame('POST', $dispatch['method']);
        self::assertSame(
            GitHubDeployClient::API_HOST . '/repos/' . self::REPOSITORY . '/actions/workflows/deploy.yml/dispatches',
            $this->stripQuery($dispatch['url']),
            'l\'URL de dispatch est fixe sur api.github.com',
        );
        $body = json_decode((string) $dispatch['body'], true);
        self::assertIsArray($body);
        self::assertSame('main', $body['ref'], 'le workflow est toujours dispatché sur la branche main');
        self::assertSame('v1.2.3', $body['inputs']['version']);
        self::assertSame($maintenanceUntil->format(DateTimeInterface::ATOM), $body['inputs']['maintenance_until'], 'maintenance_until est un instant ISO-8601 strict');
        self::assertStringContainsString('Bearer ' . self::TOKEN, $dispatch['authorization'], 'le jeton voyage en Bearer');
    }

    public function testNoRequestUrlIsEverDerivedFromTheRequestedVersion(): void
    {
        // Une version forgée (jamais acceptée en amont, mais défense en profondeur) ne doit
        // PAS pouvoir réécrire l'hôte ni le chemin : elle finit dans le CORPS, pas dans l'URL.
        $client = $this->client($recorded, ['dispatch' => new MockResponse('', ['http_code' => 204])]);

        $client->dispatchDeploy('v1.0.0/../../evil?x=y', new DateTimeImmutable('2026-10-19T02:00:00+02:00'));

        $dispatch = $this->firstCallMatching($recorded, 'deploy.yml/dispatches');
        self::assertStringStartsWith(GitHubDeployClient::API_HOST . '/repos/' . self::REPOSITORY . '/', $dispatch['url']);
        $body = json_decode((string) $dispatch['body'], true);
        self::assertIsArray($body);
        self::assertSame('v1.0.0/../../evil?x=y', $body['inputs']['version'], 'la version brute reste confinée au corps JSON');
    }

    public function testTheTokenNeverLeaksIntoTheExceptionOnAFailedResponse(): void
    {
        $client = $this->client($recorded, ['tags' => new MockResponse('{"message":"Server Error"}', ['http_code' => 500])]);

        try {
            $client->tags();
            self::fail('une réponse 500 de GitHub doit lever GitHubDeployException');
        } catch (GitHubDeployException $exception) {
            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage(), 'le jeton ne doit jamais fuiter dans le message d\'exception');
            self::assertStringNotContainsString(self::TOKEN, (string) $exception, 'ni dans la trace rendue en chaîne');
        }
    }

    public function testAnEmptyTokenMakesTheClientInertWithoutAnyRequest(): void
    {
        $client = new GitHubDeployClient(
            new MockHttpClient(static function (): MockResponse {
                self::fail('un client sans jeton ne doit émettre AUCUNE requête');
            }),
            '',
            self::REPOSITORY,
        );

        self::assertFalse($client->isConfigured(), 'jeton absent → fonctionnalité inerte');
    }

    public function testAConfiguredClientReportsItself(): void
    {
        $client = $this->client($unused, []);
        self::assertTrue($client->isConfigured());
    }

    /**
     * Construit le client réel sur un MockHttpClient qui ROUTE par URL (robuste à l'ordre et au
     * nombre d'appels) et ENREGISTRE chaque requête dans $recorded.
     *
     * @param array<int, array{method: string, url: string, body: ?string, authorization: string}>|null                             $recorded
     * @param array{tags?: MockResponse, ci?: MockResponse, dispatch?: MockResponse, deployRuns?: MockResponse, run?: MockResponse} $responses
     */
    private function client(?array &$recorded, array $responses): GitHubDeployClient
    {
        $recorded = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$recorded, $responses): MockResponse {
            $recorded[] = [
                'method' => $method,
                'url' => $url,
                'body' => \is_string($options['body'] ?? null) ? $options['body'] : null,
                'authorization' => implode(' ', (array) ($options['normalized_headers']['authorization'] ?? [])),
            ];

            if (str_contains($url, '/workflows/deploy.yml/dispatches')) {
                return $responses['dispatch'] ?? new MockResponse('', ['http_code' => 204]);
            }
            if (str_contains($url, '/workflows/ci.yml/runs')) {
                return $responses['ci'] ?? new MockResponse((string) json_encode(['workflow_runs' => [['status' => 'completed', 'conclusion' => 'success']]]));
            }
            if (str_contains($url, '/workflows/deploy.yml/runs')) {
                return $responses['deployRuns'] ?? new MockResponse((string) json_encode(['workflow_runs' => [['id' => 42, 'status' => 'in_progress', 'conclusion' => null]]]));
            }
            if (str_contains($url, '/actions/runs/')) {
                return $responses['run'] ?? new MockResponse((string) json_encode(['id' => 42, 'status' => 'in_progress', 'conclusion' => null]));
            }
            if (str_contains($url, '/tags')) {
                return $responses['tags'] ?? $this->tagsResponse([['name' => 'v1.0.0', 'sha' => 'head0000']]);
            }

            return new MockResponse('[]');
        });

        return new GitHubDeployClient($http, self::TOKEN, self::REPOSITORY);
    }

    /** @param list<array{name: string, sha: string}> $tags */
    private function tagsResponse(array $tags): MockResponse
    {
        return new MockResponse((string) json_encode(array_map(
            static fn (array $tag): array => ['name' => $tag['name'], 'commit' => ['sha' => $tag['sha']]],
            $tags,
        )));
    }

    /**
     * @param array<int, array{method: string, url: string, body: ?string, authorization: string}> $recorded
     *
     * @return array{method: string, url: string, body: ?string, authorization: string}
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
