<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\OpponentVenueLink;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueUnavailability;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentVenueLinkSource;
use App\Enum\SeasonStatus;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\TravelTimeCache;
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
 * Fixture CRUD surface (spec gestion-matchs palier A): friendly (no
 * competition), home placement (venue + kickoff), and DTO validation.
 */
#[Group('phase1')]
#[Group('integration')]
final class FixtureApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const TEAM_ID = '11111111-1111-4111-8111-111111111111';

    private const VENUE_ID = '22222222-2222-4222-8222-222222222222';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private User $user;

    public function testCreatesAFriendlyWithNoCompetition(): void
    {
        $data = $this->post([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-01',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Amical voisin',
        ]);
        self::assertResponseStatusCodeSame(201);
        // Null props are omitted from the serialized output.
        self::assertNull($data['competitionId'] ?? null);
        self::assertSame('UNPLACED', $data['status']);
        self::assertNull($data['kickoffTime'] ?? null);
    }

    public function testAManualCreationIsTreatedAndExposesTheReviewFields(): void
    {
        // PR-3a — a hand-entered rencontre is a manager gesture → REVIEWED, and
        // the resource serves reviewState/reviewedAt/pendingDeviations/ffbbRencontreId.
        $data = $this->post([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-01',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Amical voisin',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('REVIEWED', $data['reviewState']);
        self::assertNotNull($data['reviewedAt'] ?? null);
        self::assertSame([], $data['pendingDeviations']);
        self::assertNull($data['ffbbRencontreId'] ?? null);
    }

    public function testPuttingStatusPlacedTreatsTheFixtureAndCannotWriteReviewState(): void
    {
        $created = $this->post([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-08',
            'homeAway' => 'HOME',
            'opponentLabel' => 'À placer',
        ]);
        // A forged reviewState in the PUT body is ignored (FixtureInput has no such
        // field); the D6 rule alone drives it — placing treats the fixture.
        $this->putFixture($created['id'], [
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-08',
            'homeAway' => 'HOME',
            'opponentLabel' => 'À placer',
            'venueId' => '22222222-2222-4222-8222-222222222222',
            'kickoffTime' => '16:30',
            'status' => 'PLACED',
            'reviewState' => 'NEW',
        ]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('REVIEWED', $this->responseData()['reviewState'], 'placing treats it (D6), the echoed reviewState is ignored');
    }

    public function testPlacesAHomeFixtureWithVenueAndKickoff(): void
    {
        $created = $this->post([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-08',
            'homeAway' => 'HOME',
            'opponentLabel' => 'À placer',
        ]);

        // PUT = full replace → resend the required identity fields plus the placement.
        $this->client->request('PUT', '/api/fixtures/' . $created['id'], [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-08',
            'homeAway' => 'HOME',
            'opponentLabel' => 'À placer',
            'venueId' => '22222222-2222-4222-8222-222222222222',
            'kickoffTime' => '16:30',
            'status' => 'PLACED',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseData();
        self::assertSame('16:30', $data['kickoffTime']);
        self::assertSame('PLACED', $data['status']);
        self::assertSame('22222222-2222-4222-8222-222222222222', $data['venueId']);
    }

    public function testHandBackToSolverOnUntouchedPlacementEcho(): void
    {
        $placed = $this->createPlaced('2026-11-15');
        $this->putFixture($placed['id'], $placed + ['placementSource' => 'SOLVER']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('SOLVER', $this->responseData()['placementSource']);
    }

    public function testHandBackRejectedWhenPlacementChangesInTheSamePut(): void
    {
        $placed = $this->createPlaced('2026-11-22');
        $this->putFixture($placed['id'], ['kickoffTime' => '18:00', 'placementSource' => 'SOLVER'] + $placed);
        self::assertResponseStatusCodeSame(422);
    }

    public function testHandBackRejectedWhenTheMatchLeavesPlaced(): void
    {
        $placed = $this->createPlaced('2026-11-29');
        $this->putFixture($placed['id'], ['status' => 'UNPLACED', 'placementSource' => 'SOLVER'] + $placed);
        self::assertResponseStatusCodeSame(422);
    }

    public function testEchoPutWithoutSourceStampsManual(): void
    {
        $placed = $this->createPlaced('2026-12-06');
        $this->putFixture($placed['id'], $placed);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('MANUAL', $this->responseData()['placementSource']);
    }

    public function testUnplacingClearsThePlacementSource(): void
    {
        $placed = $this->createPlaced('2026-12-13');
        $this->putFixture($placed['id'], ['status' => 'UNPLACED', 'venueId' => '', 'kickoffTime' => ''] + $placed);
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->responseData()['placementSource'] ?? null);
    }

    public function testCreatingWithSolverSourceIsRejected(): void
    {
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-12-20',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Faux solveur',
            'placementSource' => 'SOLVER',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * BCK-27 — un libellé d'adversaire plus long que la colonne (VARCHAR(180)) doit
     * être refusé en 422 PARLANT (violations non vides), jamais franchir jusqu'au 500
     * SQL. La borne DTO (Assert\Length) est la maison unique de ce refus.
     */
    public function testRejectsOverlongOpponentLabel(): void
    {
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-01',
            'homeAway' => 'HOME',
            'opponentLabel' => str_repeat('A', 181), // colonne opponent_label = VARCHAR(180)
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422, (string) $this->client->getResponse()->getContent());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertNotEmpty($data['violations'] ?? [], 'un 422 muet (violations vides) afficherait « An error occurred »');
    }

    public function testRejectsMalformedKickoffTime(): void
    {
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-01',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Bad time',
            'kickoffTime' => '25h',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testRejectsUnknownHomeAway(): void
    {
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-01',
            'homeAway' => 'NEUTRAL',
            'opponentLabel' => 'Bad side',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testRejectsNonUuidTeamId(): void
    {
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => 'not-a-uuid',
            'matchDate' => '2026-11-01',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Bad id',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testRejectsCompetitionOutsideScope(): void
    {
        // A well-formed but out-of-scope competition id (tenant/season filter hides
        // it → the processor cannot resolve it) must be rejected, not silently kept.
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'competitionId' => '33333333-3333-4333-8333-333333333333',
            'matchDate' => '2026-11-01',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Ghost competition',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testRefusesPlacementOnAnUnavailableVenueEvenForAFriendly(): void
    {
        // D2 rule 1 — a venue unavailable on the match date refuses ANY placement,
        // friendly included (no competition here).
        $this->persistVenue();
        $this->persistUnavailability('2026-11-01', '2026-11-30', 'Travaux');
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-07',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Voisin',
            'venueId' => self::VENUE_ID,
            'kickoffTime' => '16:30',
            'status' => 'PLACED',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('indisponible', (string) $this->client->getResponse()->getContent());
    }

    public function testRefusesCompetitionPlacementWhenNoMatchWindowThatDay(): void
    {
        // D2 rule 2 — the club declares match access, but on Monday, while the match
        // is a Saturday (2026-11-07). A competition match then has no slot that day.
        $this->persistVenue();
        $competitionId = $this->createCompetition();
        $this->persistMatchWindow(1, '18:00', '22:00');
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'competitionId' => $competitionId,
            'matchDate' => '2026-11-07',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Adversaire',
            'venueId' => self::VENUE_ID,
            'kickoffTime' => '16:30',
            'status' => 'PLACED',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        // NB: le corps JSON échappe l'apostrophe en ' — on assert sur un fragment sans apostrophe.
        self::assertStringContainsString('accès match le samedi', (string) $this->client->getResponse()->getContent());
    }

    public function testRefusesCompetitionPlacementWhenKickoffOutsideMatchWindow(): void
    {
        // D2 rule 2 — a Saturday access window exists but the kickoff (20:00) sits
        // outside it. Exercised through the PUT (update) path.
        $this->persistVenue();
        $competitionId = $this->createCompetition();
        $this->persistMatchWindow(6, '14:00', '18:00');
        $created = $this->post([
            'teamId' => self::TEAM_ID,
            'competitionId' => $competitionId,
            'matchDate' => '2026-11-07',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Adversaire',
        ]);
        $this->putFixture($created['id'], [
            'teamId' => self::TEAM_ID,
            'competitionId' => $competitionId,
            'matchDate' => '2026-11-07',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Adversaire',
            'venueId' => self::VENUE_ID,
            'kickoffTime' => '20:00',
            'status' => 'PLACED',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('hors fenêtre', (string) $this->client->getResponse()->getContent());
    }

    public function testAllowsFriendlyPlacementOutsideMatchWindow(): void
    {
        // D2 rule 2 — a FRIENDLY (no competition) is free off any match slot: the
        // kickoff (20:00) is outside the only Saturday window, yet the placement holds.
        $this->persistVenue();
        $this->persistMatchWindow(6, '14:00', '18:00');
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-07',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Amical voisin',
            'venueId' => self::VENUE_ID,
            'kickoffTime' => '20:00',
            'status' => 'PLACED',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
    }

    public function testAllowsCompetitionPlacementWhenClubDeclaresNoMatchWindow(): void
    {
        // D2 rule 2 — a club that has NOT adopted match windows has nothing to
        // enforce: a competition placement with no window at all is allowed.
        $this->persistVenue();
        $competitionId = $this->createCompetition();
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'competitionId' => $competitionId,
            'matchDate' => '2026-11-07',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Adversaire',
            'venueId' => self::VENUE_ID,
            'kickoffTime' => '16:30',
            'status' => 'PLACED',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Le trajet d'une rencontre EXTÉRIEURE est DÉRIVÉ de la rencontre (champ additif
     * `awayTravel`), calculé EN BATCH par le provider de collection. Preuve de toute la PR :
     * deux rencontres du MÊME club adverse dans DEUX salles différentes portent DEUX trajets
     * différents. Repli « gymnase supposé » marqué ; domicile → null.
     */
    public function testAwayTravelIsDerivedFromEachFixtureVenue(): void
    {
        // Siège du club localisé (sans quoi aucun trajet ne se calcule).
        $this->scopeGucToClub($this->club->getId());
        $this->club->setLatitude(45.70)->setLongitude(4.90);
        $this->em->flush();

        // ADV1 : deux salles distinctes → deux liens → deux trajets (le cœur de la PR).
        $a = $this->seedAway('ARA0069AAA', 'ASVEL - 1', 'SALLE ALPHA');
        $b = $this->seedAway('ARA0069AAA', 'ASVEL - 2', 'SALLE BRAVO');
        $this->seedLink('ARA0069AAA', 'SALLE ALPHA', 'Gymnase Alpha', 45.80, 5.00);
        $this->seedLink('ARA0069AAA', 'SALLE BRAVO', 'Gymnase Bravo', 45.90, 5.10);
        $this->seedCache(45.80, 5.00, 25);
        $this->seedCache(45.90, 5.10, 40);

        // ADV2 : une salle appariée (deux rencontres) + une salle orpheline → repli « gymnase
        // le plus fréquent » (Gymnase Charlie), marqué approché.
        $this->seedAway('ARA0069BBB', 'BC - 1', 'SALLE CHARLIE');
        $this->seedAway('ARA0069BBB', 'BC - 2', 'SALLE CHARLIE');
        $orphan = $this->seedAway('ARA0069BBB', 'BC - 3', 'SALLE INCONNUE');
        $this->seedLink('ARA0069BBB', 'SALLE CHARLIE', 'Gymnase Charlie', 46.00, 5.20);
        $this->seedCache(46.00, 5.20, 55);

        // Un domicile → awayTravel null.
        $home = $this->seedHome('ARA0069AAA', 'ASVEL - 1');

        $this->client->request('GET', '/api/fixtures', [], [], $this->authHeaders());
        self::assertResponseStatusCodeSame(200);
        $byId = [];
        foreach ($this->responseData()['member'] ?? [] as $row) {
            $byId[$row['id']] = $row;
        }

        // Deux trajets DIFFÉRENTS pour deux salles du même adversaire.
        self::assertSame('linked', $byId[$a]['awayTravel']['basis']);
        self::assertSame(25, $byId[$a]['awayTravel']['oneWayMinutes']);
        self::assertSame('Gymnase Alpha', $byId[$a]['awayTravel']['venueLabel']);
        self::assertFalse($byId[$a]['awayTravel']['approximated']);
        self::assertSame(40, $byId[$b]['awayTravel']['oneWayMinutes'], 'la 2ᵉ salle porte SON propre trajet');

        // Repli « gymnase supposé » approché.
        self::assertSame('most_frequent', $byId[$orphan]['awayTravel']['basis']);
        self::assertSame('Gymnase Charlie', $byId[$orphan]['awayTravel']['venueLabel']);
        self::assertSame(55, $byId[$orphan]['awayTravel']['oneWayMinutes']);
        self::assertTrue($byId[$orphan]['awayTravel']['approximated']);

        // Domicile : aucun trajet adverse (champ omis à la sérialisation quand null).
        self::assertNull($byId[$home]['awayTravel'] ?? null);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->user = $this->createClubUser();
    }

    /** @return array<string, mixed> the full-replace body of a freshly PLACED fixture */
    private function createPlaced(string $date): array
    {
        $created = $this->post([
            'teamId' => self::TEAM_ID,
            'matchDate' => $date,
            'homeAway' => 'HOME',
            'opponentLabel' => 'Boucle manuelle',
        ]);
        $body = [
            'teamId' => self::TEAM_ID,
            'matchDate' => $date,
            'homeAway' => 'HOME',
            'opponentLabel' => 'Boucle manuelle',
            'venueId' => '22222222-2222-4222-8222-222222222222',
            'kickoffTime' => '16:30',
            'status' => 'PLACED',
        ];
        $this->putFixture($created['id'], $body);
        self::assertResponseStatusCodeSame(200);

        return ['id' => $created['id']] + $body;
    }

    /** @param array<string, mixed> $body */
    private function putFixture(string $id, array $body): void
    {
        unset($body['id']);
        $this->client->request('PUT', '/api/fixtures/' . $id, [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function seedAway(string $code, string $opponentLabel, string $fbiVenueLabel): string
    {
        return $this->seedFixture($code, $opponentLabel, $fbiVenueLabel, FixtureHomeAway::AWAY);
    }

    private function seedHome(string $code, string $opponentLabel): string
    {
        return $this->seedFixture($code, $opponentLabel, null, FixtureHomeAway::HOME);
    }

    private function seedFixture(string $code, string $opponentLabel, ?string $fbiVenueLabel, FixtureHomeAway $homeAway): string
    {
        $this->scopeGucToClub($this->club->getId());
        $fixture = new Fixture;
        $fixture->setClubId($this->club->getId());
        $fixture->setSeasonId($this->season->getId());
        $fixture->setTeamId(self::TEAM_ID);
        $fixture->setMatchDate(new DateTimeImmutable('+10 days'));
        $fixture->setHomeAway($homeAway);
        $fixture->setOpponentLabel($opponentLabel);
        $fixture->setOpponentOrganismeCode($code);
        $fixture->setFbiVenueLabel($fbiVenueLabel);
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture->getId();
    }

    private function seedLink(string $code, string $fbiLabel, string $venueLabel, float $lat, float $lon): void
    {
        $this->scopeGucToClub($this->club->getId());
        $normalizer = self::getContainer()->get(VenueLabelNormalizer::class);
        $link = (new OpponentVenueLink)
            ->setClubId($this->club->getId())
            ->setOpponentOrganismeCode($code)
            ->setFbiLabel($fbiLabel)
            ->setFbiLabelNorm($normalizer->normalize($fbiLabel))
            ->setVenueLabel($venueLabel)
            ->setLatitude($lat)
            ->setLongitude($lon)
            ->setSource(OpponentVenueLinkSource::AUTO);
        $this->em->persist($link);
        $this->em->flush();
    }

    private function seedCache(float $destLat, float $destLon, int $minutes): void
    {
        self::getContainer()->get(TravelTimeCache::class)->store($this->club->getId(), IgnRoutingClient::PROFILE_CAR, 45.70, 4.90, $destLat, $destLon, $minutes);
    }

    private function post(array $body): array
    {
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));

        return $this->responseData();
    }

    private function createClubUser(): User
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club fixture');
        $club->setSlug('club-fixture-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('fixture' . $uid . '@test.com');
        $user->setFirstName('Fix');
        $user->setLastName('Ture');
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
        // Matches need a settled season plan (cockpit state 3); point the plan at a
        // version so this API test targets fixture behaviour, not the socle guard.
        $this->settleSeasonPlan($season);
        $this->club = $club;
        $this->season = $season;

        return $user;
    }

    private function persistVenue(): void
    {
        if (null !== $this->em->find(Venue::class, self::VENUE_ID)) {
            return;
        }
        $venue = new Venue;
        $venue->setId(self::VENUE_ID);
        $venue->setClubId($this->club->getId());
        $venue->setSeasonId($this->season->getId());
        $venue->setName('Gymnase Matéo');
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();
    }

    private function persistMatchWindow(int $dayOfWeek, string $start, string $end): void
    {
        $window = new VenueMatchWindow;
        $window->setClubId($this->club->getId());
        $window->setSeasonId($this->season->getId());
        $window->setVenueId(self::VENUE_ID);
        $window->setDayOfWeek($dayOfWeek);
        $window->setStartTime(new DateTimeImmutable($start));
        $window->setEndTime(new DateTimeImmutable($end));
        $this->em->persist($window);
        $this->em->flush();
    }

    private function persistUnavailability(string $start, string $end, ?string $label = null): void
    {
        $unavailability = new VenueUnavailability;
        $unavailability->setClubId($this->club->getId());
        $unavailability->setSeasonId($this->season->getId());
        $unavailability->setVenueId(self::VENUE_ID);
        $unavailability->setStartDate(new DateTimeImmutable($start));
        $unavailability->setEndDate(new DateTimeImmutable($end));
        $unavailability->setLabel($label);
        $this->em->persist($unavailability);
        $this->em->flush();
    }

    private function createCompetition(): string
    {
        $competition = new Competition;
        $competition->setClubId($this->club->getId());
        $competition->setSeasonId($this->season->getId());
        $competition->setTeamId(self::TEAM_ID);
        $competition->setName('Championnat');
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $this->em->persist($competition);
        $this->em->flush();

        return $competition->getId();
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /** @return array<string, mixed> */
    private function responseData(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
