<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use App\Enum\SeasonStatus;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET/POST /api/fixtures/league-validation — le geste « validé ligue » en lot (lot L).
 *
 * Le compte et l'application partagent le MÊME prédicat : un domicile UNPLACED
 * portant heure + gymnase identifié, sans écart en attente. L'application pose
 * VALIDATED + source MANUAL, et cette rencontre ressort alors en ANCRE FIXE dans la
 * charge utile envoyée au solveur de placement (la preuve passe par l'engine réel du
 * docker-compose ; il se skippe s'il est indisponible, même rituel que le placement).
 */
#[Group('integration')]
final class LeagueValidatedFixturesControllerTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testNonManagementMemberIs403OnBothRoutes(): void
    {
        [, $clubId] = $this->createClub();
        $editorToken = $this->addMember($clubId, 'editor');

        $this->client->request('GET', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testSocleNotChosenIs409OnBothRoutes(): void
    {
        [$token] = $this->createClub(settleSocle: false);

        $this->client->request('GET', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(409);

        $this->client->request('POST', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testCountReturnsOnlyEligibleHomeFixtures(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);

        // Éligible : domicile UNPLACED, heure + gymnase, sans écart. Une date FUTURE
        // reste éligible (elle porte heure + gymnase, donc enregistrée côté fédération).
        $this->eligible($clubId, $seasonId, $team->getId(), $venue->getId(), '2099-03-14');

        // Inéligibles, un par raison :
        $noKickoff = $this->createFixture($clubId, $seasonId, $team->getId(), '2099-03-15');
        $noKickoff->setVenueId($venue->getId());
        $noVenue = $this->createFixture($clubId, $seasonId, $team->getId(), '2099-03-16');
        $noVenue->setKickoffTime(new DateTimeImmutable('15:30'));
        $away = $this->createFixture($clubId, $seasonId, $team->getId(), '2099-03-17');
        $away->setHomeAway(FixtureHomeAway::AWAY);
        $away->setVenueId($venue->getId());
        $away->setKickoffTime(new DateTimeImmutable('15:30'));
        $withDeviation = $this->createFixture($clubId, $seasonId, $team->getId(), '2099-03-18');
        $withDeviation->setVenueId($venue->getId());
        $withDeviation->setKickoffTime(new DateTimeImmutable('15:30'));
        $withDeviation->putPendingDeviation(['field' => 'date', 'appValue' => '2099-03-18', 'sourceValue' => '2099-03-25', 'channel' => 'FBI_XLSX', 'seenAt' => '2026-10-01T00:00:00+00:00', 'autoApplied' => false]);
        $this->em->flush();

        $this->client->request('GET', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['count']);
    }

    public function testConfirmSwitchesEligibleToValidatedManualAndIsReplayable(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $eligible = $this->eligible($clubId, $seasonId, $team->getId(), $venue->getId(), '2099-03-14');
        $untouched = $this->createFixture($clubId, $seasonId, $team->getId(), '2099-03-15'); // no venue/kickoff → skipped

        $this->client->request('POST', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['confirmed']);

        $this->em->refresh($eligible);
        self::assertSame(FixtureStatus::VALIDATED, $eligible->getStatus());
        self::assertSame(FixturePlacementSource::MANUAL, $eligible->getPlacementSource());

        $this->em->refresh($untouched);
        self::assertSame(FixtureStatus::UNPLACED, $untouched->getStatus());

        // Rejouable : le prédicat exclut VALIDATED, une seconde application ne trouve rien.
        $this->client->request('POST', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $again = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(0, $again['confirmed']);
    }

    /**
     * NR — une rencontre basculée « validé ligue » ressort en ANCRE FIXE : le solveur
     * range les autres matchs autour d'elle et ne la réécrit jamais (source MANUAL).
     * Miroir de {@see PlaceMatchesControllerTest::testAManualAnchorIsNeverRewritten},
     * mais l'ancre naît ici du geste « validé ligue », pas d'un placement manuel.
     */
    public function testAConfirmedFixtureBecomesAFixedAnchorForTheSolver(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->createWindow($clubId, $seasonId, $venue->getId(), 6, '14:00', '22:30');
        // Un domicile déjà daté côté fédération (samedi 20:30), validé ligue en lot.
        $anchor = $this->eligible($clubId, $seasonId, $team->getId(), $venue->getId(), '2026-10-03', '20:30');
        $other = $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-03');

        $this->client->request('POST', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $this->em->refresh($anchor);
        self::assertSame(FixtureStatus::VALIDATED, $anchor->getStatus());
        self::assertSame(FixturePlacementSource::MANUAL, $anchor->getPlacementSource());

        // La charge utile envoyée au solveur porte cette rencontre en kind=FIXED.
        $club = $this->em->find(Club::class, $clubId);
        self::assertInstanceOf(Club::class, $club);
        $builder = self::getContainer()->get(MatchPlacementPayloadBuilder::class);
        self::assertInstanceOf(MatchPlacementPayloadBuilder::class, $builder);
        $matches = $builder->build($club, $seasonId)['payload']['matches'];
        self::assertIsArray($matches);
        $anchorRow = null;
        foreach ($matches as $row) {
            self::assertIsArray($row);
            if (($row['id'] ?? null) === $anchor->getId()) {
                $anchorRow = $row;
            }
        }
        self::assertIsArray($anchorRow, 'la rencontre validée ligue doit figurer dans la charge utile du solveur');
        self::assertSame('FIXED', $anchorRow['kind'] ?? null);
        self::assertSame($venue->getId(), $anchorRow['venueId'] ?? null);
        self::assertSame('20:30', $anchorRow['kickoff'] ?? null);

        // Et à la résolution réelle, l'ancre n'est jamais réécrite ; l'autre se range autour.
        // Le rail de placement vide l'EM : on RELIT par id (find réattache depuis la base)
        // plutôt que refresh sur une entité devenue détachée.
        [$anchorId, $otherId] = [$anchor->getId(), $other->getId()];
        $this->placeOrSkip($token);
        $this->em->clear();
        $freshAnchor = $this->em->find(Fixture::class, $anchorId);
        self::assertInstanceOf(Fixture::class, $freshAnchor);
        self::assertSame('20:30', $freshAnchor->getKickoffTime()?->format('H:i'));
        self::assertSame(FixturePlacementSource::MANUAL, $freshAnchor->getPlacementSource());
        $freshOther = $this->em->find(Fixture::class, $otherId);
        self::assertInstanceOf(Fixture::class, $freshOther);
        self::assertSame(FixtureStatus::PLACED, $freshOther->getStatus());
        $kickoff = $freshOther->getKickoffTime()?->format('H:i');
        self::assertNotNull($kickoff);
        self::assertLessThanOrEqual('19:00', $kickoff);
    }

    /**
     * NR — la DIVERGENCE ASSUMÉE avec le contrôle d'accès du geste unitaire : une
     * rencontre à domicile dont l'heure tombe HORS des créneaux d'accès match déclarés
     * du gymnase reste ÉLIGIBLE et bascule (la source fédérale fait foi), et le radar la
     * signale (`ACCESS_WINDOW_LOST`). Sans ce témoin, la divergence se refermerait par
     * accident à la prochaine passe (le prédicat se remettrait à contrôler l'accès).
     */
    public function testAnOutOfAccessWindowHomeFixtureStaysEligibleAndTheRadarSignalsIt(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);

        // Un créneau d'accès match 14:00–18:00 le jour du match — que le coup d'envoi
        // (20:30) NE couvre PAS. Le geste unitaire refuserait cette pose ; le lot, non.
        $date = '2099-03-14';
        $day = (int) new DateTimeImmutable($date)->format('N');
        $this->createWindow($clubId, $seasonId, $venue->getId(), $day, '14:00', '18:00');
        $fixtureId = $this->eligible($clubId, $seasonId, $team->getId(), $venue->getId(), $date, '20:30')->getId();

        // (1) Le prédicat ne fait PAS le contrôle d'accès : la rencontre reste validable.
        $this->client->request('GET', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $count = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(1, $count['count'], 'hors créneau d\'accès mais enregistrée côté fédération → éligible');

        // (2) Elle bascule malgré tout (la réalité fédérale fait foi). L'EM a été vidé par
        // le GET intermédiaire : on RELIT par id plutôt que refresh sur une entité détachée.
        $this->client->request('POST', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $this->em->clear();
        $fixture = $this->em->find(Fixture::class, $fixtureId);
        self::assertInstanceOf(Fixture::class, $fixture);
        self::assertSame(FixtureStatus::VALIDATED, $fixture->getStatus());
        self::assertSame(FixturePlacementSource::MANUAL, $fixture->getPlacementSource());

        // (3) L'incohérence n'est pas tue : le radar la signale (ACCESS_WINDOW_LOST).
        $this->client->request('GET', '/api/fixtures/conflicts', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $radar = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($radar['conflicts'] ?? null);
        $signalled = array_filter(
            $radar['conflicts'],
            static fn (array $c): bool => 'ACCESS_WINDOW_LOST' === ($c['type'] ?? null) && ($c['fixture']['fixtureId'] ?? null) === $fixture->getId(),
        );
        self::assertCount(1, $signalled, 'le radar signale la rencontre validée hors créneau d\'accès');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Le test enchaîne DEUX requêtes (validation ligue, puis placement) : sans ça le
        // noyau redémarre entre les deux et détache les entités (refresh impossible).
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** POST /api/fixtures/place — skip the test when the engine is not up (502). */
    private function placeOrSkip(string $token): void
    {
        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        if (502 === $this->client->getResponse()->getStatusCode()) {
            self::markTestSkipped('Engine not available');
        }
        self::assertResponseStatusCodeSame(200);
    }

    /** A home fixture made eligible: UNPLACED + venue + kickoff, no pending deviation. */
    private function eligible(string $clubId, string $seasonId, string $teamId, string $venueId, string $date, string $kickoff = '15:30'): Fixture
    {
        $fixture = $this->createFixture($clubId, $seasonId, $teamId, $date);
        $fixture->setVenueId($venueId);
        $fixture->setKickoffTime(new DateTimeImmutable($kickoff));
        $this->em->flush();

        return $fixture;
    }

    /**
     * @return array{0: string, 1: string, 2: string} [adminToken, clubId, seasonId]
     */
    private function createClub(bool $settleSocle = true): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('BC League ' . $uid);
        $club->setSlug('bc-league-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('league' . $uid . '@test.com');
        $user->setFirstName('League');
        $user->setLastName('User');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $season = new Season;
        $season->setClubId($club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        if ($settleSocle) {
            $this->settleSeasonPlan($season);
        }

        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return [$token, $club->getId(), $season->getId()];
    }

    private function addMember(string $clubId, string $role): string
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $uid = uniqid($role, true);
        $user = new User;
        $user->setEmail($role . $uid . '@test.com');
        $user->setFirstName('N');
        $user->setLastName('M');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);

        $this->scopeGucToClub($clubId);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function createTeam(string $clubId, string $seasonId): Team
    {
        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (null === $sport) {
            $uid = uniqid('', true);
            $sport = new Sport;
            $sport->setName('Basket ' . $uid);
            $sport->setSlug('basket-' . $uid);
            $sport->setIsActive(true);
            $this->em->persist($sport);
        }
        $category = new SportCategory;
        $category->setClubId($clubId);
        $category->setSportId($sport->getId());
        $category->setName('U13-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($clubId);
        $team->setSeasonId($seasonId);
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('SF3');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function createVenue(string $clubId, string $seasonId): Venue
    {
        $venue = new Venue;
        $venue->setClubId($clubId);
        $venue->setSeasonId($seasonId);
        $venue->setName('Mateo');
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function createWindow(string $clubId, string $seasonId, string $venueId, int $day, string $start, string $end): void
    {
        $window = new VenueMatchWindow;
        $window->setClubId($clubId);
        $window->setSeasonId($seasonId);
        $window->setVenueId($venueId);
        $window->setDayOfWeek($day);
        $window->setStartTime(new DateTimeImmutable($start));
        $window->setEndTime(new DateTimeImmutable($end));
        $this->em->persist($window);
        $this->em->flush();
    }

    private function createFixture(string $clubId, string $seasonId, string $teamId, string $date): Fixture
    {
        // Match de COMPÉTITION : un domicile sans compétition sortirait du payload de
        // placement (les amicaux ne sont plus confiés au solveur, P4-193).
        $competition = new Competition;
        $competition->setClubId($clubId);
        $competition->setSeasonId($seasonId);
        $competition->setTeamId($teamId);
        $competition->setName('D2-' . uniqid('', true));
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $this->em->persist($competition);
        $this->em->flush();

        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($seasonId);
        $fixture->setTeamId($teamId);
        $fixture->setCompetitionId($competition->getId());
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv');
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }
}
