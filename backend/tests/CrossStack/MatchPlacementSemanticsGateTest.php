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
        'club_rule_no_slot',
        'team_venue_forbidden',
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

    public function testAHardClubRuleIsHonouredAndExcludesTheIdeal(): void
    {
        // P4-272 ③ (axe « constraint semantics ») — une règle HARD saisie par le club
        // (« pas après 18h ») DOIT être honorée par le VRAI moteur : le créneau idéal
        // (habitude 20:30) la viole → il est exclu, le match atterrit sur un créneau
        // conforme (≤ 18:00), et n'est pas laissé non placé (aucun repli).
        $result = $this->solve([
            'matches' => [['id' => 'm1', 'teamId' => 't1', 'date' => self::SATURDAY, 'kind' => 'TO_PLACE']],
            'venues' => [$this->venue('v1', [['13:00', '22:30']])],
            'teams' => [$this->teamWithIdeal('t1', 6, '20:30', 'v1')],
            'clubRules' => [['ruleType' => 'HARD', 'daysOfWeek' => [6], 'kickoffMin' => null, 'kickoffMax' => '18:00']],
        ]);

        self::assertSame([], $result['unplaced'], 'une règle HARD ne laisse pas le match non placé s\'il reste un créneau conforme');
        self::assertCount(1, $result['placements']);
        self::assertLessThanOrEqual('18:00', substr((string) $result['placements'][0]['kickoff'], 0, 5), 'le coup d\'envoi respecte la règle HARD');
        self::assertStringStartsNotWith('20:30', (string) $result['placements'][0]['kickoff'], 'le créneau idéal violant la règle n\'est pas choisi');
    }

    public function testAHardClubRuleLeavingNoSlotYieldsClubRuleNoSlot(): void
    {
        // P4-272 ③ — le seul accès est 20:00-22:30 ; une règle HARD « pas après 18h »
        // vide le domaine pourtant licite → raison NOMMÉE `club_rule_no_slot` (distincte
        // de no_access_window / no_league_intersection), dans le vocabulaire figé.
        $result = $this->solve([
            'matches' => [['id' => 'm1', 'teamId' => 't1', 'date' => self::SATURDAY, 'kind' => 'TO_PLACE']],
            'venues' => [$this->venue('v1', [['20:00', '22:30']])],
            'teams' => [$this->team('t1')],
            'clubRules' => [['ruleType' => 'HARD', 'daysOfWeek' => [6], 'kickoffMin' => null, 'kickoffMax' => '18:00']],
        ]);

        self::assertSame([], $result['placements']);
        self::assertCount(1, $result['unplaced']);
        self::assertSame('club_rule_no_slot', $result['unplaced'][0]['reason']);
        self::assertContains($result['unplaced'][0]['reason'], self::REASON_VOCABULARY);
        self::assertNotSame('', (string) $result['unplaced'][0]['message']);
    }

    public function testAForbiddenVenueIsNeverChosenEvenWhenItIsTheOnlyFreeSlot(): void
    {
        // P4-272 ④ (axe « constraint semantics ») — contre le VRAI moteur : un gymnase
        // INTERDIT à l'équipe n'est jamais retenu, même s'il offre le seul créneau idéal.
        // Deux gymnases ouverts, l'idéal sur v1 (interdit) → le match atterrit sur v2, PAS
        // sur v1, et n'est pas laissé non placé (aucun repli dans l'interdit).
        $result = $this->solve([
            'matches' => [['id' => 'm1', 'teamId' => 't1', 'date' => self::SATURDAY, 'kind' => 'TO_PLACE']],
            'venues' => [$this->venue('v1', [['13:00', '22:30']]), $this->venue('v2', [['13:00', '22:30']])],
            'teams' => [$this->teamForbidding('t1', 6, '15:30', 'v1', ['v1'])],
        ]);

        self::assertSame([], $result['unplaced'], 'un gymnase interdit ne laisse pas le match non placé s\'il reste un gymnase autorisé');
        self::assertCount(1, $result['placements']);
        self::assertSame('v2', $result['placements'][0]['venueId'], 'le match évite le gymnase interdit, même s\'il portait l\'idéal');
    }

    public function testAForbiddenVenueLeavingNoOtherVenueYieldsTeamVenueForbidden(): void
    {
        // P4-272 ④ — le SEUL gymnase ouvert est interdit à l'équipe : un créneau licite
        // existait, mais seulement dans l'interdit → raison NOMMÉE `team_venue_forbidden`
        // (distincte de venue_unavailable / no_access_window), dans le vocabulaire figé.
        $result = $this->solve([
            'matches' => [['id' => 'm1', 'teamId' => 't1', 'date' => self::SATURDAY, 'kind' => 'TO_PLACE']],
            'venues' => [$this->venue('v1', [['13:00', '22:30']])],
            'teams' => [$this->teamForbidding('t1', 6, '15:30', 'v1', ['v1'])],
        ]);

        self::assertSame([], $result['placements'], 'le moteur ne pose jamais un match dans un gymnase interdit');
        self::assertCount(1, $result['unplaced']);
        self::assertSame('team_venue_forbidden', $result['unplaced'][0]['reason']);
        self::assertContains($result['unplaced'][0]['reason'], self::REASON_VOCABULARY);
        self::assertNotSame('', (string) $result['unplaced'][0]['message']);
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

    public function testFixedMatchFinishingAfterMidnightStillPlacesTheOthers(): void
    {
        // ENG-48 (axe « constraint semantics ») — un match posé À LA MAIN (FIXED) qui démarre à
        // 22:30 pour 120 min FINIT à 00:30 (après minuit ; la ligue peut l'autoriser, « pas de
        // surprise », aucun refus backend). Le domaine de compaction du groupe (gymnase, date) doit
        // atteindre cette fin : borné à 24:00, l'ancre rendait TOUT le groupe infaisable et un match
        // par ailleurs plaçable à côté restait non placé. Le correctif absorbe l'ancre → l'autre
        // match est placé.
        $result = $this->solve([
            'matches' => [
                ['id' => 'm1', 'teamId' => 't1', 'date' => self::SATURDAY, 'kind' => 'TO_PLACE'],
                ['id' => 'fx', 'teamId' => 't2', 'date' => self::SATURDAY, 'kind' => 'FIXED', 'venueId' => 'v1', 'kickoff' => '22:30'],
            ],
            'venues' => [$this->venue('v1', [['13:00', '18:00']])],
            'teams' => [$this->team('t1'), $this->teamWithMatchMinutes('t2', 120)],
        ]);

        self::assertSame([], $result['unplaced'], 'un match plaçable à côté d\'une ancre finissant après minuit doit être placé');
        self::assertCount(1, $result['placements']);
        self::assertSame('m1', $result['placements'][0]['matchId']);
    }

    /**
     * @param array{matches: list<array<string, mixed>>, venues: list<array<string, mixed>>, teams: list<array<string, mixed>>, clubRules?: list<array<string, mixed>>} $problem
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
            'clubRules' => $problem['clubRules'] ?? [],
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

    /** Une équipe avec une durée de match explicite — D1 : le gymnase tient [kickoff, kickoff+matchMinutes]. @return array<string, mixed> */
    private function teamWithMatchMinutes(string $id, int $matchMinutes): array
    {
        return ['id' => $id, 'name' => strtoupper($id), 'leagueWindows' => [], 'habits' => [], 'coaches' => [], 'matchMinutes' => $matchMinutes];
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

    /**
     * Une équipe avec un créneau idéal ET une liste de gymnases INTERDITS (P4-272 ④).
     *
     * @param list<string> $forbiddenVenueIds
     *
     * @return array<string, mixed>
     */
    private function teamForbidding(string $id, int $dayOfWeek, string $kickoff, string $venueId, array $forbiddenVenueIds): array
    {
        return [
            'id' => $id,
            'name' => strtoupper($id),
            'leagueWindows' => [],
            'habits' => [['dayOfWeek' => $dayOfWeek, 'kickoff' => $kickoff, 'venueId' => $venueId]],
            'coaches' => [],
            'forbiddenVenueIds' => $forbiddenVenueIds,
        ];
    }
}
