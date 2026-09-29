<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\MatchPlacementPayloadBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * P4-240 — NR BLOQUANT (axe §7.1 « constraint semantics ») du placement des matchs, contre le
 * VRAI moteur : le contrat de placement ne peut plus dériver en silence côté engine.
 *
 * Deux promesses que le frontend et le gestionnaire tiennent pour acquises :
 *   1. tout ce qui est plaçable EST placé (une fenêtre large, aucun conflit → zéro `unplaced`) ;
 *   2. le VOCABULAIRE des raisons d'échec est EXACT — un code hors de l'énumération figée
 *      (`no_access_window` · `no_league_intersection` · `venue_unavailable` · `venue_full` ·
 *      `not_selected`) casserait le mapping front (l'énum OpenAPI + `UnplacedReason`) sans qu'aucun
 *      typecheck ne le voie, la sérialisation étant à la main des deux côtés. On falsifie les deux
 *      raisons que le solveur produit à partir des DONNÉES (gymnase fermé → `venue_unavailable` ;
 *      gymnase saturé → `venue_full`), et on interdit tout code hors énumération.
 *
 * Étape NOMMÉE du job `blocking-tests` de `.github/workflows/ci.yml` (qui démarre déjà php-fpm +
 * engine) ET ligne de `docs/testing/blocking-tests.md` — gardé par `BlockingTestsListMatchesCiTest`.
 * Groupe `phase1` pour être ramassé par `--group phase1`. Skip propre si le moteur est absent
 * (mêmes conventions que les autres tests de contrat cross-stack).
 */
#[Group('phase1')]
#[Group('contract')]
final class MatchPlacementSemanticsGateTest extends TestCase
{
    private const string ENGINE_URL = 'http://engine:8000/place-matches';
    private const string SATURDAY = '2026-10-03'; // ISO day 6
    /** L'énumération FIGÉE des raisons d'échec — miroir de l'enum OpenAPI + `UnplacedReason` (front). */
    private const array REASON_VOCABULARY = [
        'no_access_window',
        'no_league_intersection',
        'venue_unavailable',
        'venue_full',
        'not_selected',
    ];

    public function testEveryPlaceableMatchIsPlaced(): void
    {
        // Une fenêtre samedi large (13:00-22:30 → 570 min) tient deux matchs de 105 min sans
        // conflit : les deux DOIVENT être placés, aucun `unplaced`.
        $result = $this->solve([
            'matches' => [
                ['id' => 'm1', 'teamId' => 't1', 'date' => self::SATURDAY, 'kind' => 'TO_PLACE'],
                ['id' => 'm2', 'teamId' => 't2', 'date' => self::SATURDAY, 'kind' => 'TO_PLACE'],
            ],
            'venues' => [$this->venue('v1', [['13:00', '22:30']])],
            'teams' => [$this->team('t1'), $this->team('t2')],
        ]);

        self::assertCount(2, $result['placements'], 'deux matchs plaçables doivent tous deux être placés');
        self::assertSame([], $result['unplaced'], 'aucun match ne doit rester non placé sur une fenêtre large');
    }

    public function testTeamIdealSlotIsHonoured(): void
    {
        // P4-271 (axe « constraint semantics ») — le créneau idéal (habitude) est un BONUS
        // d'attraction : sur une fenêtre large et sans conflit, le match DOIT atterrir sur
        // (jour, heure, gymnase) de l'habitude, même face à un gymnase alternatif ouvert.
        $result = $this->solve([
            'matches' => [['id' => 'm1', 'teamId' => 't1', 'date' => self::SATURDAY, 'kind' => 'TO_PLACE']],
            'venues' => [$this->venue('v1', [['13:00', '22:30']]), $this->venue('v2', [['13:00', '22:30']])],
            'teams' => [$this->teamWithIdeal('t1', 6, '15:30', 'v1')],
        ]);

        self::assertSame([], $result['unplaced']);
        self::assertCount(1, $result['placements']);
        self::assertSame('v1', $result['placements'][0]['venueId'], 'le match atterrit sur le gymnase de l\'idéal');
        self::assertStringStartsWith('15:30', (string) $result['placements'][0]['kickoff'], 'sur l\'heure de l\'idéal');
    }

    public function testAClosedVenueYieldsVenueUnavailable(): void
    {
        // Le seul gymnase est indisponible à la date → le match ne peut être placé nulle part.
        $result = $this->solve([
            'matches' => [['id' => 'm1', 'teamId' => 't1', 'date' => self::SATURDAY, 'kind' => 'TO_PLACE']],
            'venues' => [[
                'id' => 'v1',
                'name' => 'V1',
                'matchWindows' => [['dayOfWeek' => 6, 'start' => '13:00', 'end' => '22:30']],
                'unavailabilities' => [['startDate' => '2026-10-01', 'endDate' => '2026-10-05']],
            ]],
            'teams' => [$this->team('t1')],
        ]);

        self::assertSame([], $result['placements']);
        self::assertCount(1, $result['unplaced']);
        self::assertSame('venue_unavailable', $result['unplaced'][0]['reason']);
        self::assertContains($result['unplaced'][0]['reason'], self::REASON_VOCABULARY);
        self::assertNotSame('', (string) $result['unplaced'][0]['message'], 'la raison doit porter un message lisible');
    }

    public function testASaturatedVenueYieldsVenueFullAndReasonsStayInTheVocabulary(): void
    {
        // Une fenêtre de 105 min = UN seul créneau ; deux matchs → un placé, l'autre sans aucun
        // créneau licite libre → `venue_full` (jamais `not_selected` : le gymnase est saturé).
        $result = $this->solve([
            'matches' => [
                ['id' => 'm1', 'teamId' => 't1', 'date' => self::SATURDAY, 'kind' => 'TO_PLACE'],
                ['id' => 'm2', 'teamId' => 't2', 'date' => self::SATURDAY, 'kind' => 'TO_PLACE'],
            ],
            'venues' => [$this->venue('v1', [['14:00', '15:45']])],
            'teams' => [$this->team('t1'), $this->team('t2')],
        ]);

        self::assertCount(1, $result['placements']);
        self::assertCount(1, $result['unplaced']);
        self::assertSame('venue_full', $result['unplaced'][0]['reason']);
        foreach ($result['unplaced'] as $unplaced) {
            self::assertContains(
                $unplaced['reason'],
                self::REASON_VOCABULARY,
                \sprintf('raison hors énumération figée : %s', $unplaced['reason']),
            );
        }
    }

    /**
     * @param array{matches: list<array<string, mixed>>, venues: list<array<string, mixed>>, teams: list<array<string, mixed>>} $problem
     *
     * @return array<string, mixed>
     */
    private function solve(array $problem): array
    {
        $payload = [
            'version' => MatchPlacementPayloadBuilder::CONTRACT_VERSION,
            'clubId' => 'club-placement-gate',
            'seasonId' => 'season-placement-gate',
            'solverSeed' => 42,
            'solverTimeoutSeconds' => 10,
            'matches' => $problem['matches'],
            'venues' => $problem['venues'],
            'teams' => $problem['teams'],
            'teamLinks' => [],
            'trainingOccupancies' => [],
        ];

        $client = HttpClient::create(['timeout' => 30]);

        try {
            $response = $client->request('POST', self::ENGINE_URL, ['json' => $payload]);
            self::assertSame(200, $response->getStatusCode());
            $result = $response->toArray(false);
            self::assertSame('completed', $result['status'], 'le scénario de placement doit rester résoluble');

            return $result;
        } catch (TransportExceptionInterface $exception) {
            self::markTestSkipped('Engine not available: ' . $exception->getMessage());
        }
    }

    /**
     * @param list<array{0: string, 1: string}> $windows Saturday (ISO 6) [start, end] ranges
     *
     * @return array<string, mixed>
     */
    private function venue(string $id, array $windows): array
    {
        return [
            'id' => $id,
            'name' => strtoupper($id),
            'matchWindows' => array_map(
                static fn (array $w): array => ['dayOfWeek' => 6, 'start' => $w[0], 'end' => $w[1]],
                $windows,
            ),
            'unavailabilities' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function team(string $id): array
    {
        return ['id' => $id, 'name' => strtoupper($id), 'leagueWindows' => [], 'habits' => [], 'coaches' => []];
    }

    /** Une équipe avec un créneau idéal (habitude) {jour ISO, heure, gymnase}. @return array<string, mixed> */
    private function teamWithIdeal(string $id, int $dayOfWeek, string $kickoff, string $venueId): array
    {
        return [
            'id' => $id,
            'name' => strtoupper($id),
            'leagueWindows' => [],
            'habits' => [['dayOfWeek' => $dayOfWeek, 'kickoff' => $kickoff, 'venueId' => $venueId]],
            'coaches' => [],
        ];
    }
}
