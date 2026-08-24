<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Basketball;

use App\Enum\FixtureHomeAway;
use App\Service\Basketball\FfbbApiClient;
use App\Service\Basketball\FfbbRencontreReader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The rencontre reader (RMM-4 PR-3, canal API FFBB) on canned Meilisearch
 * payloads shaped like the 2026-08-24 probe of `ffbbserver_rencontres`: HOME/AWAY
 * derives from the side carrying the club code, the opponent is the OTHER
 * organisme's name, « 00:00 » is the not-set sentinel, double-encoded labels are
 * repaired, and a rencontre of another season is dropped.
 */
#[Group('unit')]
final class FfbbRencontreReaderTest extends TestCase
{
    private const CLUB = 'ARA0069036';

    public function testMapsAHomeRencontreToTheDiffShape(): void
    {
        $rows = $this->reader()->read(self::CLUB, 2026);

        // The away/other-season/noise hits are filtered out — two rows survive.
        self::assertCount(2, $rows);
        $home = $rows[0];
        self::assertSame('renc-1', $home['rencontreId']);
        self::assertSame('2026-09-20', $home['matchDate']->format('Y-m-d'));
        self::assertSame('20:30', $home['kickoffTime']?->format('H:i'));
        self::assertSame(FixtureHomeAway::HOME, $home['homeAway']);
        self::assertSame('AS COLLONGES', $home['opponentLabel']); // the OTHER organisme
        self::assertSame('GYMNASE DU TEST', $home['venueLabel']);
        self::assertSame('comp-1', $home['competitionFfbbId']);
        self::assertSame('Pré régionale masculine', $home['competitionName']); // double encoding repaired
    }

    public function testDerivesAwayAndTreatsMidnightAsNoKickoff(): void
    {
        $rows = $this->reader()->read(self::CLUB, 2026);
        $away = $rows[1];

        self::assertSame(FixtureHomeAway::AWAY, $away['homeAway']); // club is organisme 2
        self::assertSame('EVEIL SPORTIF JONAGEOIS', $away['opponentLabel']);
        self::assertNull($away['kickoffTime'], '00:00 is the FBI not-set sentinel → null');
    }

    public function testRencontresOfAnotherSeasonAreDropped(): void
    {
        // read(2026) already proves the 25-26 hit is dropped (2 of 3 survive);
        // a season with no matching hit at all returns nothing.
        self::assertSame([], $this->reader()->read(self::CLUB, 2028));
    }

    private function reader(): FfbbRencontreReader
    {
        $us = ['code' => self::CLUB, 'nom' => 'NOTRE CLUB'];
        $hits = ['results' => [['hits' => [
            [
                'id' => 'renc-1',
                'date_rencontre' => '2026-09-20T20:30:00',
                'idOrganismeEquipe1' => $us,
                'idOrganismeEquipe2' => ['code' => 'ARA0000002', 'nom' => 'AS COLLONGES'],
                'competitionId' => ['id' => 'comp-1', 'nom' => "Pr\u{c3}\u{a9} r\u{c3}\u{a9}gionale masculine"],
                'salle' => ['libelle' => 'GYMNASE DU TEST'],
                'saison' => ['code' => '26-27'],
            ],
            [
                'id' => 'renc-2',
                'date_rencontre' => '2026-09-27T00:00:00', // sentinel → null kickoff
                'idOrganismeEquipe1' => ['code' => 'ARA0000003', 'nom' => 'EVEIL SPORTIF JONAGEOIS'],
                'idOrganismeEquipe2' => $us, // club is organisme 2 → AWAY
                'competitionId' => ['id' => 'comp-1', 'nom' => 'Pré régionale masculine'],
                'saison' => ['code' => '26-27'],
            ],
            [
                'id' => 'renc-old',
                'date_rencontre' => '2025-09-20T18:00:00',
                'idOrganismeEquipe1' => $us,
                'idOrganismeEquipe2' => ['code' => 'ARA0000004', 'nom' => 'VIEUX CLUB'],
                'saison' => ['code' => '25-26'], // another season → dropped
            ],
        ]]]];

        $httpClient = new MockHttpClient(function (string $method, string $url) use ($hits): MockResponse {
            if (str_contains($url, 'api.ffbb.com')) {
                return new MockResponse((string) json_encode(['data' => ['key_ms' => 'token']]));
            }

            return new MockResponse((string) json_encode($hits));
        });

        return new FfbbRencontreReader(new FfbbApiClient($httpClient));
    }
}
