<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\MatchConflictDetector;
use App\Service\MatchPlacementPayloadBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Ouverture datée : le VRAI moteur lit les plages d'accès comme le backend et le front.
 *
 * Les cas `accessCases` de `matchAccess.parity.json` (jumeaux : `MatchAccessMirrorParityTest`,
 * `matchAccess.parity.test.ts`) sont rejoués sur POST /place-matches : on confie le coup d'envoi du cas
 * comme position importée (contrat 1.9) ; le moteur la garde si et seulement si le prédicat PHP dit « dedans ».
 * Angle nommé : c'est la seule preuve que l'union date/férié/jour coïncide entre PHP et Python. Skip si
 * le moteur n'est pas joignable (patron `MatchPlacementContractSchemaTest`).
 */
#[Group('contract')]
final class MatchAccessEngineParityTest extends TestCase
{
    private const string CASES = __DIR__ . '/../../../frontend/src/features/matches/lib/matchAccess.parity.json';

    private const string ENGINE_URL = 'http://engine:8000/place-matches';

    public function testEngineKeepsAKickoffExactlyWhenTheBackendPredicateSaysInside(): void
    {
        $raw = file_get_contents(self::CASES);
        self::assertIsString($raw);
        /** @var array{accessCases: list<array{name: string, venueId: string, matchDate: string, isHoliday: bool, kickoff: string, windows: list<array<string, mixed>>, inside: bool}>} $decoded */
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertNotEmpty($decoded['accessCases']);

        foreach ($decoded['accessCases'] as $case) {
            self::assertSame($case['inside'], MatchConflictDetector::kickoffInsideAccess($case['venueId'], $case['matchDate'], $case['isHoliday'], $case['kickoff'], $case['windows']), $case['name']);

            $kept = $this->engineKeeps($case['venueId'], $case['matchDate'], $case['isHoliday'], $case['kickoff'], $case['windows']);
            self::assertSame($case['inside'], $kept, \sprintf('PARITÉ ROMPUE (« %s ») : le moteur et le backend ne lisent pas les mêmes plages.', $case['name']));
        }
    }

    /**
     * D4, écart DÉCLARÉ : la pose juge le coup d'envoi (la salle est ouverte quand le match commence), le
     * moteur juge le match entier (sa sécurité). Un coup d'envoi 19:30 dans 14:00-20:00, 105 min de match :
     * accepté à la main, refusé en automatique. La plage large du raccourci neutralise le cas courant.
     */
    public function testKickoffInsideButMatchOverflowsIsAcceptedByHandAndRefusedBySolver(): void
    {
        $windows = [['venueId' => 'v1', 'dayOfWeek' => null, 'startTime' => '14:00', 'endTime' => '20:00', 'appliesOnPublicHolidays' => false, 'openingDate' => '2026-11-18']];

        self::assertTrue(MatchConflictDetector::kickoffInsideAccess('v1', '2026-11-18', false, '19:30', $windows), 'à la main : accepté');
        self::assertFalse($this->engineKeeps('v1', '2026-11-18', false, '19:30', $windows), 'en automatique : refusé');
    }

    /** @param list<array<string, mixed>> $windows */
    private function engineKeeps(string $venueId, string $date, bool $isHoliday, string $kickoff, array $windows): bool
    {
        $byVenue = [$venueId => []];
        foreach ($windows as $window) {
            $byVenue[(string) $window['venueId']][] = array_filter([
                'dayOfWeek' => $window['dayOfWeek'],
                'appliesOnPublicHolidays' => $window['appliesOnPublicHolidays'] ?: null,
                'openingDate' => $window['openingDate'],
                'start' => $window['startTime'],
                'end' => $window['endTime'],
            ], static fn (mixed $value): bool => null !== $value);
        }
        $venues = [];
        foreach ($byVenue as $id => $lines) {
            $venues[] = ['id' => $id, 'name' => $id, 'matchWindows' => $lines, 'unavailabilities' => []];
        }

        try {
            $response = $this->post([
                'version' => MatchPlacementPayloadBuilder::CONTRACT_VERSION,
                'clubId' => 'club-parity',
                'seasonId' => 'season-parity',
                'publicHolidays' => $isHoliday ? [$date] : [],
                'matches' => [['id' => 'm1', 'teamId' => 't1', 'date' => $date, 'kind' => 'TO_PLACE', 'importedVenueId' => $venueId, 'importedKickoff' => $kickoff]],
                'venues' => $venues,
                'teams' => [['id' => 't1', 'name' => 'T1', 'leagueWindows' => [], 'habits' => [], 'coaches' => []]],
            ]);
            self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
            $placement = $response->toArray(false)['placements'][0] ?? null;
        } catch (TransportExceptionInterface $exception) {
            self::markTestSkipped('Engine not available: ' . $exception->getMessage());
        }

        return \is_array($placement)
            && $placement['venueId'] === $venueId
            && $kickoff === substr((string) $placement['kickoff'], 0, 5);
    }

    /** @param array<string, mixed> $payload */
    private function post(array $payload): ResponseInterface
    {
        return HttpClient::create(['timeout' => 5])->request('POST', self::ENGINE_URL, ['json' => $payload]);
    }
}
