<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Club;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\OpponentVenueLink;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamMatchHabit;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use App\Enum\OpponentVenueLinkSource;
use App\Enum\SeasonStatus;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\TravelTimeCache;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\OpponentTravelProjection;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
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
        foreach (['id', 'name', 'leagueWindows', 'habits', 'coaches', 'matchMinutes', 'warmupMinutes'] as $key) {
            self::assertArrayHasKey($key, $team);
        }
        self::assertSame(['dayOfWeek' => 6, 'kickoff' => '15:30', 'venueId' => null], $team['habits'][0]);
        // Durées PAR CATÉGORIE (P4-203) : la catégorie « U13-… » sans override
        // hérite du défaut de famille U13-U15 = 90 / 30.
        self::assertSame(90, $team['matchMinutes']);
        self::assertSame(30, $team['warmupMinutes']);
        // L'équipe de test ne mappe pas l'enveloppe → [] + diagnostic INFO
        // (« on accompagne, on ne décide pas »).
        self::assertSame([], $team['leagueWindows']);
        self::assertSame('league_envelope_unresolved', $built['infoDiagnostics'][0]['type']);
    }

    /**
     * NR P4-203 (§7.1 contrat backend↔engine) : le payload porte les durées PAR
     * ÉQUIPE (matchMinutes/warmupMinutes), résolues par MatchDurationResolver sur
     * la catégorie de l'équipe — override de club quand la catégorie en porte un,
     * sinon défaut de famille. Le moteur en dépend pour tenir la salle sur la
     * seule durée du match (D1).
     */
    #[Group('phase1')]
    public function testPerTeamDurationsCarryClubOverrideElseFamilyDefault(): void
    {
        [, $fixture, $club, $season, $builder] = $this->buildFromSeededClub();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');

        // Sans override : la catégorie « U13-… » hérite du défaut de famille 90 / 30.
        $team = $builder->build($club, $season->getId())['payload']['teams'][0];
        self::assertSame(90, $team['matchMinutes']);
        self::assertSame(30, $team['warmupMinutes']);

        // Override de club posé sur la catégorie → il prime le défaut de famille.
        $teamEntity = $em->getRepository(Team::class)->find($fixture->getTeamId());
        self::assertInstanceOf(Team::class, $teamEntity);
        $category = $em->getRepository(SportCategory::class)->find($teamEntity->getSportCategoryId());
        self::assertInstanceOf(SportCategory::class, $category);
        $category->setMatchMinutes(70);
        $category->setWarmupMinutes(20);
        $em->flush();

        $team = $builder->build($club, $season->getId())['payload']['teams'][0];
        self::assertSame(70, $team['matchMinutes']);
        self::assertSame(20, $team['warmupMinutes']);
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

        $fixture->setStatus(FixtureStatus::PLACED, new DateTimeImmutable);
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
     * NR P4-193 (§7.1 contrat backend↔engine) : un AMICAL (competitionId null)
     * n'est jamais confié au solveur. Il n'est jamais TO_PLACE : non placé →
     * ABSENT du payload ; placé et ancré → FIXED (son gymnase reste protégé) ;
     * AWAY → footprint informatif comme un match de compétition. Un match de
     * championnat, lui, reste TO_PLACE (inchangé). Si cette partition casse, le
     * solveur se remet à déplacer/placer des amicaux.
     */
    #[Group('phase1')]
    public function testFriendlyFixturesAreHandledApartFromCompetitionOnes(): void
    {
        [, $seededFixture, $club, $season, $builder, $venue] = $this->buildFromSeededClub();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $teamId = $seededFixture->getTeamId();

        $friendlyUnplaced = $this->makeFixture($em, $club, $season, $teamId, '2026-10-10', FixtureHomeAway::HOME);
        $friendlyPlaced = $this->makeFixture($em, $club, $season, $teamId, '2026-10-17', FixtureHomeAway::HOME);
        $friendlyPlaced->setStatus(FixtureStatus::PLACED, new DateTimeImmutable);
        $friendlyPlaced->setVenueId($venue->getId());
        $friendlyPlaced->setKickoffTime(new DateTimeImmutable('15:30'));
        $friendlyPlaced->setPlacementSource(FixturePlacementSource::MANUAL);
        $friendlyAway = $this->makeFixture($em, $club, $season, $teamId, '2026-10-24', FixtureHomeAway::AWAY);
        $em->flush();

        $matches = $builder->build($club, $season->getId())['payload']['matches'];
        $byId = [];
        foreach ($matches as $row) {
            $byId[$row['id']] = $row;
        }

        // Amical non placé : ABSENT (ni TO_PLACE, ni FIXED).
        self::assertArrayNotHasKey($friendlyUnplaced->getId(), $byId, 'un amical non placé ne va jamais au solveur');
        // Amical placé et ancré : FIXED, jamais TO_PLACE (son gymnase reste protégé).
        self::assertSame('FIXED', $byId[$friendlyPlaced->getId()]['kind'] ?? null);
        self::assertSame($venue->getId(), $byId[$friendlyPlaced->getId()]['venueId'] ?? null);
        // Amical extérieur : AWAY, comme une rencontre de compétition.
        self::assertSame('AWAY', $byId[$friendlyAway->getId()]['kind'] ?? null);
        // D3 — la ligne AWAY porte le champ de contrat roundTripMinutes ; trajet
        // inconnu (aucun opponent_travel seedé) → 0 (aucune extension côté solveur).
        self::assertArrayHasKey('roundTripMinutes', $byId[$friendlyAway->getId()]);
        self::assertSame(0, $byId[$friendlyAway->getId()]['roundTripMinutes']);
        // Championnat (la rencontre seedée, UNPLACED) : TO_PLACE — inchangé.
        self::assertSame('TO_PLACE', $byId[$seededFixture->getId()]['kind'] ?? null);
    }

    /**
     * NR D3 (§7.1 contrat backend↔engine) : la ligne AWAY du payload de placement
     * porte le trajet aller-retour (2 × aller simple) projeté par
     * {@see OpponentTravelProjection} — la MÊME projection que le radar.
     * Le solveur étend la fenêtre AWAY du coach de cette durée (réplique de
     * MatchFootprint). Un adversaire sans trajet reste à 0.
     */
    #[Group('phase1')]
    public function testAwayLineCarriesTheProjectedRoundTripTravel(): void
    {
        [, $seededFixture, $club, $season, $builder] = $this->buildFromSeededClub();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $teamId = $seededFixture->getTeamId();

        // Une rencontre extérieure estampillée d'un code organisme dont le club a un
        // trajet aller simple de 40 min.
        $away = $this->makeFixture($em, $club, $season, $teamId, '2026-10-24', FixtureHomeAway::AWAY);
        $away->setOpponentOrganismeCode('ORGCONTRAT');
        $away->setOpponentLabel('ASVEL - 2');
        $away->setFbiVenueLabel('SALLE CONTRAT');
        $em->flush();

        // Le trajet se résout par le lien de la salle + le cache (siège → gymnase) : lien vers
        // un gymnase (45.76, 4.86) et trajet aller simple 40 min en cache.
        $this->seedLinkAndCache($em, $club, 'ORGCONTRAT', 'SALLE CONTRAT', 45.76, 4.86, 40);

        $matches = $builder->build($club, $season->getId())['payload']['matches'];
        $awayRow = null;
        foreach ($matches as $row) {
            if ($row['id'] === $away->getId()) {
                $awayRow = $row;
            }
        }
        self::assertNotNull($awayRow, 'la rencontre extérieure figure au payload');
        // 2 × aller simple : 40 → 80.
        self::assertSame(80, $awayRow['roundTripMinutes']);
    }

    /**
     * NR D3 (§7.1 contrat backend↔engine) : le trajet aller-retour émis est CLAMPÉ à la
     * borne du schéma engine (24 h = 1440 min). Un aller-simple aberrant (800 min → 1600
     * aller-retour) ferait sinon rejeter TOUT le payload en 422 (`round_trip_minutes` `le=1440`).
     */
    #[Group('phase1')]
    public function testRoundTripTravelIsClampedToTheSchemaBound(): void
    {
        [, $seededFixture, $club, $season, $builder] = $this->buildFromSeededClub();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $teamId = $seededFixture->getTeamId();

        $away = $this->makeFixture($em, $club, $season, $teamId, '2026-10-24', FixtureHomeAway::AWAY);
        $away->setOpponentOrganismeCode('ORGLOIN');
        $away->setOpponentLabel('Bout du monde');
        $away->setFbiVenueLabel('SALLE LOIN');
        $em->flush();

        // Aller simple aberrant de 800 min → aller-retour 1600, au-delà de la borne engine.
        $this->seedLinkAndCache($em, $club, 'ORGLOIN', 'SALLE LOIN', 46.50, 5.50, 800);

        $matches = $builder->build($club, $season->getId())['payload']['matches'];
        $awayRow = null;
        foreach ($matches as $row) {
            if ($row['id'] === $away->getId()) {
                $awayRow = $row;
            }
        }
        self::assertNotNull($awayRow, 'la rencontre extérieure figure au payload');
        // Clampé à 1440 (24 h), pas 1600 : le payload reste recevable par le schéma engine.
        self::assertSame(1440, $awayRow['roundTripMinutes']);
    }

    private function makeFixture(EntityManagerInterface $em, Club $club, Season $season, string $teamId, string $date, FixtureHomeAway $homeAway): Fixture
    {
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId($teamId);
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway($homeAway);
        $fixture->setOpponentLabel('Amical');
        $em->persist($fixture);

        return $fixture;
    }

    /**
     * Apparie un libellé de salle à un gymnase ({@see OpponentVenueLink}) et met en cache le
     * trajet siège(45.70,4.90) → gymnase — la façon dont un trajet AWAY se résout désormais.
     */
    private function seedLinkAndCache(EntityManagerInterface $em, Club $club, string $code, string $fbiLabel, float $lat, float $lon, int $oneWay): void
    {
        $normalizer = self::getContainer()->get(VenueLabelNormalizer::class);
        $link = (new OpponentVenueLink)
            ->setClubId($club->getId())
            ->setOpponentOrganismeCode($code)
            ->setFbiLabel($fbiLabel)
            ->setFbiLabelNorm($normalizer->normalize($fbiLabel))
            ->setVenueLabel($fbiLabel)
            ->setLatitude($lat)
            ->setLongitude($lon)
            ->setSource(OpponentVenueLinkSource::MANUAL);
        $em->persist($link);
        $em->flush();
        self::getContainer()->get(TravelTimeCache::class)->store($club->getId(), IgnRoutingClient::PROFILE_CAR, 45.70, 4.90, $lat, $lon, $oneWay);
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
        // Siège localisé : sans coordonnées, la projection de trajet ne rend rien.
        $club->setLatitude(45.70);
        $club->setLongitude(4.90);
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

        // La rencontre seedée est un match de CHAMPIONNAT (competitionId non nul) :
        // un amical ne va plus au solveur (P4-193), il sortirait du payload et
        // viderait ce test — c'est la compétition qui prouve le kind TO_PLACE.
        $competition = new Competition;
        $competition->setClubId($club->getId());
        $competition->setSeasonId($season->getId());
        $competition->setTeamId($team->getId());
        $competition->setName('D2-' . $uid);
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $em->persist($competition);

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
        $fixture->setCompetitionId($competition->getId());
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-03')); // Saturday
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('AS Voisins');
        $em->persist($fixture);
        $em->flush();

        return [$builder->build($club, $season->getId()), $fixture, $club, $season, $builder, $venue];
    }
}
