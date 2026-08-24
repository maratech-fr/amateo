<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Club;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamMatchHabit;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use App\Enum\SeasonStatus;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * NR de l'axe contrat backend↔engine pour le placement des matchs (P1-4 PR D,
 * ADR-0003) : la FORME du payload du builder réel (phase1, sans
 * engine) et le POST au VRAI engine (groupe contract — même rituel que
 * ContractSchemaTest pour /generate).
 */
#[Group('integration')]
final class MatchPlacementContractSchemaTest extends KernelTestCase
{
    use TenantGucTrait;

    private const ENGINE_URL = 'http://engine:8000/place-matches';

    #[Group('phase1')]
    public function testPayloadShapeMatchesTheEngineSchema(): void
    {
        [$built] = $this->buildFromSeededClub();
        $payload = $built['payload'];

        // Version DÉRIVÉE de la source ; l'égalité constante⇄engine/CONTRACT_VERSION
        // est gardée par PayloadVersionMatchesContractVersionTest.
        self::assertSame(MatchPlacementPayloadBuilder::CONTRACT_VERSION, $payload['version']);
        foreach (['clubId', 'seasonId', 'solverSeed', 'solverTimeoutSeconds', 'matches', 'venues', 'teams', 'teamLinks', 'slotRotations', 'trainingOccupancies'] as $key) {
            self::assertArrayHasKey($key, $payload);
        }
        // RMM-5 : aucune rotation seedée ⇒ bloc [] (chemin byte-identique côté moteur).
        self::assertSame([], $payload['slotRotations']);

        self::assertSame(1, $built['toPlaceCount']);
        $match = $payload['matches'][0];
        self::assertSame('TO_PLACE', $match['kind']);
        foreach (['id', 'teamId', 'date', 'currentVenueId', 'currentKickoff'] as $key) {
            self::assertArrayHasKey($key, $match);
        }
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $match['date']);

        $venue = $payload['venues'][0];
        self::assertArrayHasKey('matchWindows', $venue);
        self::assertSame(['dayOfWeek' => 6, 'start' => '14:00', 'end' => '22:00'], $venue['matchWindows'][0]);
        self::assertArrayHasKey('unavailabilities', $venue);

        $team = $payload['teams'][0];
        foreach (['id', 'name', 'leagueWindows', 'habits', 'coaches'] as $key) {
            self::assertArrayHasKey($key, $team);
        }
        self::assertSame(['dayOfWeek' => 6, 'kickoff' => '15:30', 'venueId' => null], $team['habits'][0]);
        // L'équipe de test ne mappe pas l'enveloppe → [] + diagnostic INFO
        // (« on accompagne, on ne décide pas »).
        self::assertSame([], $team['leagueWindows']);
        self::assertSame('league_envelope_unresolved', $built['infoDiagnostics'][0]['type']);
    }

    #[Group('contract')]
    public function testContractPostsToRealEngineOrSkipsWhenUnavailable(): void
    {
        [$built] = $this->buildFromSeededClub();

        $client = HttpClient::create(['timeout' => 5]);
        try {
            $response = $client->request('POST', self::ENGINE_URL, ['json' => $built['payload']]);
        } catch (TransportExceptionInterface $exception) {
            self::markTestSkipped('Engine not available: ' . $exception->getMessage());
        }

        self::assertSame(200, $response->getStatusCode());
        $data = $response->toArray(false);
        self::assertSame('completed', $data['status']);
        foreach (['placements', 'unplaced', 'diagnostics'] as $key) {
            self::assertArrayHasKey($key, $data);
        }
        // Le match du samedi est plaçable dans la fenêtre 14:00-22:00 → placé,
        // coup d'envoi DANS la fenêtre (le sens, pas juste un 200).
        self::assertCount(1, $data['placements']);
        self::assertSame([], $data['unplaced']);
        $kickoff = $data['placements'][0]['kickoff'];
        self::assertGreaterThanOrEqual('14:30', $kickoff);
        self::assertLessThanOrEqual('20:15', $kickoff);
    }

    /**
     * NR P1-4 PR E (§7.1 contrat backend↔engine) : le verrou/déverrou pilote les
     * kinds du payload. PLACED+SOLVER = TO_PLACE re-plaçable (avec son placement
     * courant) ; le CADENAS (re-stamp MANUAL) le fige en FIXED ; « rendre au
     * solveur » (SOLVER) le rouvre en TO_PLACE. Si cette bascule casse, le solveur
     * déplace des ancres ou fige tout le calendrier.
     */
    #[Group('phase1')]
    public function testLockAndHandBackFlipThePayloadKind(): void
    {
        [, $fixture, $club, $season, $builder, $venue] = $this->buildFromSeededClub();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');

        $fixture->setStatus(FixtureStatus::PLACED);
        $fixture->setVenueId($venue->getId());
        $fixture->setKickoffTime(new DateTimeImmutable('15:30'));
        $fixture->setPlacementSource(FixturePlacementSource::SOLVER);
        $em->flush();

        $match = $builder->build($club, $season->getId())['payload']['matches'][0];
        self::assertSame('TO_PLACE', $match['kind'], 'un placement SOLVER reste re-plaçable');
        self::assertSame($venue->getId(), $match['currentVenueId'], 'et porte son placement courant (stabilité)');
        self::assertSame('15:30', $match['currentKickoff']);

        $fixture->setPlacementSource(FixturePlacementSource::MANUAL);
        $em->flush();
        $match = $builder->build($club, $season->getId())['payload']['matches'][0];
        self::assertSame('FIXED', $match['kind'], 'le cadenas (MANUAL) fige le match en ancre');
        self::assertSame('15:30', $match['kickoff']);

        $fixture->setPlacementSource(FixturePlacementSource::SOLVER);
        $em->flush();
        self::assertSame('TO_PLACE', $builder->build($club, $season->getId())['payload']['matches'][0]['kind'], 'rendre au solveur rouvre le placement');
    }

    /**
     * @return array{0: array{payload: array<string, mixed>, toPlaceCount: int, infoDiagnostics: list<array<string, mixed>>}, 1: Fixture, 2: Club, 3: Season, 4: MatchPlacementPayloadBuilder, 5: Venue}
     */
    private function buildFromSeededClub(): array
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $builder = self::getContainer()->get(MatchPlacementPayloadBuilder::class);

        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('BC Contrat ' . $uid);
        $club->setSlug('bc-contrat-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 10)));
        $em->persist($club);
        $em->flush();
        $this->scopeGucToClub($club->getId());

        $season = new Season;
        $season->setClubId($club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $em->persist($season);

        $sport = $em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (null === $sport) {
            $sport = new Sport;
            $sport->setName('Basket ' . $uid);
            $sport->setSlug('basket-' . $uid);
            $sport->setIsActive(true);
            $em->persist($sport);
        }
        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U13-' . $uid);
        $em->persist($category);

        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('SF3');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $em->persist($team);

        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName('Mateo');
        $venue->setSource('manual');
        $em->persist($venue);
        $em->flush();

        $window = new VenueMatchWindow;
        $window->setClubId($club->getId());
        $window->setSeasonId($season->getId());
        $window->setVenueId($venue->getId());
        $window->setDayOfWeek(6);
        $window->setStartTime(new DateTimeImmutable('14:00'));
        $window->setEndTime(new DateTimeImmutable('22:00'));
        $em->persist($window);

        $habit = new TeamMatchHabit;
        $habit->setClubId($club->getId());
        $habit->setSeasonId($season->getId());
        $habit->setTeamId($team->getId());
        $habit->setDayOfWeek(6);
        $habit->setKickoffTime(new DateTimeImmutable('15:30'));
        $em->persist($habit);

        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId($team->getId());
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-03')); // Saturday
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('AS Voisins');
        $em->persist($fixture);
        $em->flush();

        return [$builder->build($club, $season->getId()), $fixture, $club, $season, $builder, $venue];
    }
}
