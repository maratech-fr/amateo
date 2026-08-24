<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Basketball\FfbbApiClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Lot C SSRF guard (A12): FfbbApiClient only ever talks to the two hard-coded
 * FFBB hosts and validates the club code format before any use downstream.
 */
#[Group('unit')]
final class FfbbApiClientTest extends TestCase
{
    public function testClubCodeFormatIsValidated(): void
    {
        self::assertTrue(FfbbApiClient::isValidClubCode('ARA0069036'));
        self::assertTrue(FfbbApiClient::isValidClubCode('GES1234567'));
        self::assertFalse(FfbbApiClient::isValidClubCode('not-a-code'));
        self::assertFalse(FfbbApiClient::isValidClubCode('../etc/passwd'));
        self::assertFalse(FfbbApiClient::isValidClubCode('ARA069036'));   // 6 digits
        self::assertFalse(FfbbApiClient::isValidClubCode(''));
    }

    public function testSearchSallesValidatesPostalCodeAndFiltersByCommune(): void
    {
        // P2-20 : le CP est interpolé dans le filtre Meilisearch — un format
        // invalide ne doit JAMAIS atteindre la requête (même règle que le code
        // club) : liste vide, zéro appel réseau.
        $bodies = [];
        $client = new FfbbApiClient(new MockHttpClient(function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            if (str_contains($url, 'configuration')) {
                return new MockResponse((string) json_encode(['data' => ['key_ms' => 't']]));
            }
            $bodies[] = (string) $options['body'];

            return new MockResponse((string) json_encode(['results' => [['hits' => [['libelle' => 'GYMNASE MATEO']]]]]));
        }));

        self::assertSame([], $client->searchSalles('691000'), '6 chiffres → refusé');
        self::assertSame([], $client->searchSalles('69100\' OR 1'), 'injection de filtre → refusée');
        self::assertSame([], $bodies, 'aucun appel réseau sur CP invalide');

        $hits = $client->searchSalles('69100');
        self::assertSame('GYMNASE MATEO', $hits[0]['libelle'] ?? null);
        self::assertCount(1, $bodies);
        self::assertStringContainsString('ffbbserver_salles', $bodies[0]);
        self::assertStringContainsString('commune.codePostal = \'69100\'', (string) json_decode($bodies[0], true)['queries'][0]['filter']);
    }

    public function testSearchRencontresKeepsOnlyHitsCarryingTheClubCode(): void
    {
        // RMM-4 PR-3 — MESURÉ 2026-08-24 : le plein texte fait pleuvoir du bruit
        // (un hit « AMICAL PNM » qui NE concerne PAS le club). Le filtre STRICT
        // serveur ne garde qu'un hit dont le code club figure sur l'un des DEUX
        // organismes ; le hit-bruit gelé ci-dessous DOIT être exclu.
        $club = 'ARA0069036';
        $hits = ['results' => [['hits' => [
            ['id' => 'ours-home', 'idOrganismeEquipe1' => ['code' => $club], 'idOrganismeEquipe2' => ['code' => 'ARA0000002']],
            ['id' => 'ours-away', 'idOrganismeEquipe1' => ['code' => 'ARA0000003'], 'idOrganismeEquipe2' => ['code' => $club]],
            // Le bruit MESURÉ : « AMICAL PNM », deux clubs étrangers → exclu.
            ['id' => 'bruit', 'idOrganismeEquipe1' => ['code' => 'ARA0000007'], 'idOrganismeEquipe2' => ['code' => 'ARA0000008'], 'competitionId' => ['nom' => 'AMICAL PNM']],
        ]]]];
        $client = new FfbbApiClient(new MockHttpClient(function (string $method, string $url) use ($hits): MockResponse {
            if (str_contains($url, 'configuration')) {
                return new MockResponse((string) json_encode(['data' => ['key_ms' => 't']]));
            }

            return new MockResponse((string) json_encode($hits));
        }));

        self::assertSame([], $client->searchRencontres('bad-code'), 'un code invalide ne fait aucun appel');
        $kept = array_column($client->searchRencontres($club), 'id');
        self::assertSame(['ours-home', 'ours-away'], $kept, 'le hit-bruit « AMICAL PNM » est exclu');
    }

    public function testOnlyTalksToFixedFfbbHosts(): void
    {
        $hosts = [];
        $client = new FfbbApiClient(new MockHttpClient(function (string $method, string $url) use (&$hosts): MockResponse {
            $hosts[] = parse_url($url, \PHP_URL_HOST);
            if (str_contains($url, 'configuration')) {
                return new MockResponse((string) json_encode(['data' => ['key_ms' => 't']]));
            }

            return new MockResponse((string) json_encode(['results' => [['hits' => []]]]));
        }));

        $client->search('ARA0069036');

        self::assertNotEmpty($hosts);
        foreach ($hosts as $host) {
            self::assertContains($host, ['api.ffbb.com', 'meilisearch-prod.ffbb.app'], 'no host outside the FFBB allowlist');
        }
    }
}
