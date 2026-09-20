<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentVenueLink;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentLocationPrecision;
use App\Enum\OpponentVenueLinkSource;
use App\Enum\SeasonStatus;
use App\Repository\OpponentVenueLinkRepository;
use App\Repository\OpponentVenueSuggestionRepository;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\OpponentVenueLinkManager;
use App\Service\Geo\TravelTimeCache;
use App\Service\SeasonResolver;
use App\Service\TravelComputeLock;
use App\Tests\Double\IgnRoutingHttpClientStub;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * P2-54 (amendement 2026-09-20) — l'API d'appariement « libellé → gymnase » d'un
 * adversaire, GROUPÉE PAR CLUB adverse : GET /api/opponents/travel (lecture),
 * POST /{code}/venues (ajouter), POST /{code}/venue-links (apparier un orphelin),
 * PUT/DELETE /venue-links/{id} (ré-apparier/fusionner, retirer), POST /travel/resolve.
 */
#[Group('integration')]
final class OpponentTravelApiTest extends WebTestCase
{
    use TenantGucTrait;

    /** Siège du club, pour que les trajets se résolvent depuis une origine connue. */
    private const float SIEGE_LAT = 45.70;

    private const float SIEGE_LON = 4.90;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testTheReadFeedGroupsByOpponentClubWithVenuesAndUnmatchedLabels(): void
    {
        [$club, $user, $season] = $this->seedClub();

        // ORGA : deux rencontres, deux libellés ; « SALLE A » a un lien (trajet en cache),
        // « SALLE B » n'en a pas → « à apparier ».
        $this->awayFixture($club, $season, 'ARA0069001', 'ASVEL - 1', 'SALLE A');
        $this->awayFixture($club, $season, 'ARA0069001', 'ASVEL - 2', 'SALLE B');
        $this->directory('ARA0069001', OpponentLocationPrecision::VENUE, 'Lyon');
        $this->link($club, 'ARA0069001', 'SALLE A', 'Gymnase A', '166900001', 45.80, 5.00, OpponentVenueLinkSource::AUTO);
        $this->cacheTravel($club, 45.80, 5.00, 25);

        // ORGB : une rencontre sans salle de fichier → « club sans gymnase », aucun libellé à apparier.
        $this->awayFixtureNoVenueLabel($club, $season, 'ARA0069002', 'Meyzieu Basket');
        $this->directory('ARA0069002', OpponentLocationPrecision::CITY, 'Meyzieu');

        // Un adversaire SANS code fédéral (non appariable).
        $this->awayFixtureNoCode($club, $season, 'Club sans code');

        $this->client->request('GET', '/api/opponents/travel', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseData();
        self::assertTrue($data['clubGeolocated'], 'le siège du club est localisé');

        $byCode = [];
        foreach ($data['opponents'] as $opponent) {
            $byCode[$opponent['code'] ?? 'NULL'] = $opponent;
        }
        self::assertCount(3, $byCode);

        $orga = $byCode['ARA0069001'];
        self::assertSame('Lyon', $orga['city']);
        self::assertSame('VENUE', $orga['precision']);
        self::assertSame(2, $orga['fixtureCount'], 'deux rencontres contre cet adversaire');
        self::assertCount(1, $orga['venues']);
        self::assertSame('Gymnase A', $orga['venues'][0]['label']);
        self::assertSame(25, $orga['venues'][0]['travelMinutes']);
        self::assertSame('done', $orga['venues'][0]['travelStatus']);
        self::assertFalse($orga['venues'][0]['approximated']);
        self::assertSame(1, $orga['venues'][0]['fixtureCount'], '« SALLE A » porte une rencontre');
        self::assertNull($orga['venues'][0]['fallbackVenueName'], 'seul gymnase → aucun repli');
        self::assertSame(
            [['label' => 'SALLE B', 'fixtureCount' => 1]],
            $orga['unmatchedLabels'],
            '« SALLE B » sans lien = à apparier',
        );

        $orgb = $byCode['ARA0069002'];
        self::assertSame([], $orgb['venues'], 'aucun gymnase connu');
        self::assertSame([], $orgb['unmatchedLabels'], 'aucune salle de fichier → rien à apparier');
        self::assertSame('Meyzieu', $orgb['city']);

        $noCode = $byCode['NULL'];
        self::assertNull($noCode['code']);
        self::assertSame([], $noCode['venues']);
    }

    public function testTwoLinksExposeTheFallbackVenueNameForRemoval(): void
    {
        [$club, $user, $season] = $this->seedClub();
        $this->awayFixture($club, $season, 'ARA0069010', 'BC - 1', 'SALLE PRINCIPALE');
        $this->awayFixture($club, $season, 'ARA0069010', 'BC - 2', 'SALLE PRINCIPALE');
        $this->awayFixture($club, $season, 'ARA0069010', 'BC - 3', 'SALLE SECONDAIRE');
        $this->directory('ARA0069010', OpponentLocationPrecision::VENUE, 'Lyon');
        $this->link($club, 'ARA0069010', 'SALLE PRINCIPALE', 'Gymnase Principal', '166900010', 45.80, 5.00, OpponentVenueLinkSource::AUTO);
        $this->link($club, 'ARA0069010', 'SALLE SECONDAIRE', 'Gymnase Secondaire', '166900011', 45.81, 5.01, OpponentVenueLinkSource::AUTO);

        $this->client->request('GET', '/api/opponents/travel', [], [], $this->authHeaders($user));
        $venues = [];
        foreach ($this->responseData()['opponents'] as $opponent) {
            if ('ARA0069010' === ($opponent['code'] ?? null)) {
                foreach ($opponent['venues'] as $venue) {
                    $venues[$venue['label']] = $venue;
                }
            }
        }
        // Le repli du gymnase secondaire (1 rencontre) est le principal (2 rencontres, le plus fréquent).
        self::assertSame('Gymnase Principal', $venues['Gymnase Secondaire']['fallbackVenueName']);
        // Le repli du principal est le secondaire (le seul autre).
        self::assertSame('Gymnase Secondaire', $venues['Gymnase Principal']['fallbackVenueName']);
    }

    public function testAddVenueCreatesAManualLinkAndWarmsTravel(): void
    {
        [$club, $user, $season] = $this->seedClub();
        $this->awayFixture($club, $season, 'ARA0069020', 'Adversaire', 'SALLE DU FICHIER');
        $this->directory('ARA0069020', OpponentLocationPrecision::CITY, 'Bron');

        // Ajouter un gymnase par COORDONNÉES (sans ref fédérale → aucun appel FFBB au partagé).
        $this->post($user, '/api/opponents/ARA0069020/venues', [
            'venueLabel' => 'Gymnase choisi',
            'fbiLabel' => 'SALLE DU FICHIER',
            'latitude' => 45.80,
            'longitude' => 5.00,
        ]);
        self::assertResponseStatusCodeSame(200);
        $body = $this->responseData();
        self::assertSame('ARA0069020', $body['opponentOrganismeCode']);
        self::assertSame('Gymnase choisi', $body['label']);
        self::assertSame('MANUAL', $body['source']);
        self::assertSame(IgnRoutingHttpClientStub::DRIVING_MINUTES, $body['travelMinutes'], 'warmTravel a chauffé le trajet via le stub IGN');
        self::assertSame(1, $body['targetFixtureCount'], 'la rencontre « SALLE DU FICHIER » pointe ce gymnase');

        $this->scopeGucToClub($club->getId());
        $link = self::getContainer()->get(OpponentVenueLinkRepository::class)->findOneByKey($club->getId(), 'ARA0069020', 'salle du fichier');
        self::assertInstanceOf(OpponentVenueLink::class, $link);
        self::assertSame(OpponentVenueLinkSource::MANUAL, $link->getSource());
    }

    public function testPairAnOrphanLabelRequiresAnAwayLabelOfThatOpponent(): void
    {
        [$club, $user, $season] = $this->seedClub();
        $this->awayFixture($club, $season, 'ARA0069030', 'Adversaire', 'VRAIE SALLE');
        $this->directory('ARA0069030', OpponentLocationPrecision::CITY, 'Bron');

        // Un libellé qui n'est sur AUCUNE rencontre AWAY de cet adversaire → 422.
        $this->post($user, '/api/opponents/ARA0069030/venue-links', [
            'fbiLabel' => 'SALLE INVENTEE',
            'venueLabel' => 'Gymnase',
            'latitude' => 45.80,
            'longitude' => 5.00,
        ]);
        self::assertResponseStatusCodeSame(422, 'un libellé absent des rencontres AWAY est refusé');

        // Le vrai libellé orphelin → 200, lien créé.
        $this->post($user, '/api/opponents/ARA0069030/venue-links', [
            'fbiLabel' => 'VRAIE SALLE',
            'venueLabel' => 'Gymnase',
            'latitude' => 45.80,
            'longitude' => 5.00,
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testRepointReturnsTheTargetResultingFixtureCount(): void
    {
        [$club, $user, $season] = $this->seedClub();
        $this->awayFixture($club, $season, 'ARA0069040', 'BC - 1', 'SALLE X');
        $this->awayFixture($club, $season, 'ARA0069040', 'BC - 2', 'SALLE Y');
        $this->directory('ARA0069040', OpponentLocationPrecision::VENUE, 'Lyon');
        // Deux liens : X (1 rencontre) et Y (1 rencontre) vers deux gymnases différents.
        $this->link($club, 'ARA0069040', 'SALLE X', 'Gymnase X', '166900040', 45.80, 5.00, OpponentVenueLinkSource::AUTO);
        $linkY = $this->link($club, 'ARA0069040', 'SALLE Y', 'Gymnase Y', '166900041', 45.81, 5.01, OpponentVenueLinkSource::AUTO);

        // Fusionner Y DANS X : re-pointer le lien Y vers le gymnase de X (mêmes coordonnées).
        $this->put($user, '/api/opponents/venue-links/' . $linkY->getId(), [
            'venueLabel' => 'Gymnase X',
            'latitude' => 45.80,
            'longitude' => 5.00,
        ]);
        self::assertResponseStatusCodeSame(200);
        // Les deux libellés pointent désormais ce gymnase → le compte résultant est 2.
        self::assertSame(2, $this->responseData()['targetFixtureCount'], '« qui en portera 2 » après la fusion');
    }

    public function testDeleteRemovesTheLocalLinkAndIsIdempotent404(): void
    {
        [$club, $user, $season] = $this->seedClub();
        $this->awayFixture($club, $season, 'ARA0069050', 'BC - 1', 'SALLE Z');
        $link = $this->link($club, 'ARA0069050', 'SALLE Z', 'Gymnase Z', '166900050', 45.80, 5.00, OpponentVenueLinkSource::MANUAL);

        $this->client->request('DELETE', '/api/opponents/venue-links/' . $link->getId(), [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(204);

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        self::assertNull(self::getContainer()->get(OpponentVenueLinkRepository::class)->find($link->getId()), 'le lien local est retiré');

        // Un second DELETE (ou un id inconnu) → 404.
        $this->client->request('DELETE', '/api/opponents/venue-links/' . $link->getId(), [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(404);
    }

    public function testWritesAreForbiddenForANonManagementMember(): void
    {
        [$club, , $season] = $this->seedClub();
        $this->awayFixture($club, $season, 'ARA0069060', 'Adversaire', 'SALLE M');
        $member = $this->addMember($club, 'member');

        $this->post($member, '/api/opponents/ARA0069060/venues', [
            'venueLabel' => 'Gymnase',
            'fbiLabel' => 'SALLE M',
            'latitude' => 45.80,
            'longitude' => 5.00,
        ]);
        self::assertResponseStatusCodeSame(403, 'un simple membre ne peut pas apparier un gymnase');
    }

    public function testAForeignClubsLinkIs404OnRepointAndDelete(): void
    {
        [$clubA, , $seasonA] = $this->seedClub();
        $this->awayFixture($clubA, $seasonA, 'ARA0069070', 'BC - 1', 'SALLE F');
        $link = $this->link($clubA, 'ARA0069070', 'SALLE F', 'Gymnase F', '166900070', 45.80, 5.00, OpponentVenueLinkSource::MANUAL);

        // Un AUTRE club (B) tente de re-pointer / supprimer le lien de A → 404 byte-identique.
        [, $userB] = $this->seedClub();
        $this->put($userB, '/api/opponents/venue-links/' . $link->getId(), ['venueLabel' => 'X', 'latitude' => 45.80, 'longitude' => 5.00]);
        self::assertResponseStatusCodeSame(404, 'le lien d\'un autre club est invisible (RLS) → 404');

        $this->client->request('DELETE', '/api/opponents/venue-links/' . $link->getId(), [], [], $this->authHeaders($userB));
        self::assertResponseStatusCodeSame(404);
    }

    public function testTravelResolveQueuesTheComputation(): void
    {
        [$club, $user, $season] = $this->seedClub();
        $this->awayFixture($club, $season, 'ARA0069R21', 'Adversaire', 'SALLE R');
        $this->directory('ARA0069R21', OpponentLocationPrecision::CITY, 'Bron');

        $this->client->request('POST', '/api/opponents/travel/resolve', [], [], $this->authHeaders($user) + ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->responseData()['queued']);
        self::assertFalse($this->responseData()['alreadyRunning']);
    }

    public function testTravelResolveRefusesWhenAComputationIsAlreadyRunning(): void
    {
        [$club, $user, $season] = $this->seedClub();
        $this->awayFixture($club, $season, 'ARA0069R22', 'Adversaire', 'SALLE R');

        $lock = self::getContainer()->get(TravelComputeLock::class);
        $token = $lock->acquire($club->getId(), 60);
        self::assertNotNull($token);
        try {
            $this->client->request('POST', '/api/opponents/travel/resolve', [], [], $this->authHeaders($user) + ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseStatusCodeSame(200);
            self::assertFalse($this->responseData()['queued']);
            self::assertTrue($this->responseData()['alreadyRunning']);
        } finally {
            $lock->release($club->getId(), $token);
        }
    }

    public function testVenueSuggestionsAreForbiddenForANonManagementMember(): void
    {
        [$club, , $season] = $this->seedClub();
        $code = 'ARA00690S1';
        $this->awayFixture($club, $season, $code, 'ADVERSAIRE SUGG', 'SALLE S');
        $member = $this->addMember($club, 'member');

        $this->client->request('GET', '/api/opponents/' . $code . '/venue-suggestions', [], [], $this->authHeaders($member) + ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testVenueSuggestionsRejectACodeThatIsNotAnAwayOpponent(): void
    {
        [, $user] = $this->seedClub();

        $this->client->request('GET', '/api/opponents/ARA0069XXX/venue-suggestions', [], [], $this->authHeaders($user) + ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testVenueSuggestionsAreShapedAndSortedApiFirstThenManualByCount(): void
    {
        [$club, $user, $season] = $this->seedClub();
        $code = 'ARA00690S2';
        $this->awayFixture($club, $season, $code, 'ADVERSAIRE MULTI GYMS', 'SALLE S');

        $suggestions = $this->suggestions();
        $suggestions->upsertFromApi($code, 'GYMNASE FEDERAL', 'Lyon', '69001', 45.70, 4.80);
        $suggestions->upsertManual($code, '166900901', 'GYMNASE POPULAIRE', null, null, 45.60, 4.70);
        $suggestions->increment($code, '166900901');
        $suggestions->increment($code, '166900901');
        $suggestions->upsertManual($code, '166900902', 'GYMNASE RARE', null, null, 45.50, 4.60);
        $suggestions->increment($code, '166900902');

        $this->client->request('GET', '/api/opponents/' . $code . '/venue-suggestions', [], [], $this->authHeaders($user) + ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseData();
        self::assertSame($code, $data['code']);

        /** @var list<array<string, mixed>> $list */
        $list = $data['suggestions'];
        self::assertCount(3, $list);
        self::assertSame('FFBB_API', $list[0]['source']);
        self::assertNull($list[0]['externalRef']);
        self::assertSame('MANUAL', $list[1]['source']);
        self::assertSame(2, $list[1]['chosenByCount']);
        self::assertSame(1, $list[2]['chosenByCount']);
        self::assertSame(
            ['externalRef', 'label', 'city', 'postalCode', 'latitude', 'longitude', 'source', 'chosenByCount', 'lastChosenAt'],
            array_keys($list[0]),
        );
    }

    /**
     * SEC-19 — les gestes d'appariement MANUEL sont bornés PAR UTILISATEUR (30/h), non
     * assouplis en test. On isole cette borne du limiteur `api` global.
     */
    public function testTheManualLimiterTripsAtThirtyOneForOneUser(): void
    {
        [$club, $user, $season] = $this->seedClub();
        $code = 'ARA00690L1';
        $this->awayFixture($club, $season, $code, 'ADVERSAIRE LIMITE', 'SALLE L');

        $limiter = self::getContainer()->get('limiter.opponent_travel_manual');
        self::assertInstanceOf(RateLimiterFactory::class, $limiter);
        for ($i = 0; $i < 30; ++$i) {
            self::assertTrue($limiter->create($user->getId())->consume(1)->isAccepted(), "jeton {$i} sous la borne");
        }

        $this->post($user, '/api/opponents/' . $code . '/venues', [
            'venueLabel' => 'Gymnase', 'fbiLabel' => 'SALLE L', 'latitude' => 45.80, 'longitude' => 5.00,
        ]);
        self::assertResponseStatusCodeSame(429, 'le 31ᵉ épinglage dépasse la borne manuelle');
        self::assertStringContainsString('gymnases', (string) $this->client->getResponse()->getContent());
    }

    public function testAddingMoreVenuesThanTheCapForOneOpponentIsRejected(): void
    {
        [$club, $user, $season] = $this->seedClub();
        $code = 'ARA00690C1';
        $this->awayFixture($club, $season, $code, 'ADVERSAIRE CAP', 'SALLE CAP');

        // Sème le maximum de gymnases (coordonnées seules, MANUAL) pour cet adversaire.
        for ($i = 0; $i < OpponentVenueLinkManager::MAX_VENUES_PER_OPPONENT; ++$i) {
            $this->link($club, $code, 'SALLE ' . $i, 'Gymnase ' . $i, null, 45.80, 5.00, OpponentVenueLinkSource::MANUAL);
        }

        // Le gymnase suivant (nouvelle clé) dépasse la borne → 422, sans être validé contre les
        // rencontres (l'ajout avant tout match — cas playoff — doit rester possible sous la borne).
        $this->post($user, '/api/opponents/' . $code . '/venues', [
            'venueLabel' => 'Gymnase de trop', 'latitude' => 45.81, 'longitude' => 5.01,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Trop de gymnases', (string) $this->client->getResponse()->getContent());
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function suggestions(): OpponentVenueSuggestionRepository
    {
        $repository = self::getContainer()->get(OpponentVenueSuggestionRepository::class);
        self::assertInstanceOf(OpponentVenueSuggestionRepository::class, $repository);

        return $repository;
    }

    private function addMember(Club $club, string $role): User
    {
        $uid = uniqid('mbr', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $member = new User;
        $member->setEmail($uid . '@test.com');
        $member->setFirstName('Me');
        $member->setLastName('Mbre');
        $member->setPasswordHash($hasher->hashPassword($member, 'pass'));
        $this->em->persist($member);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($member->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $member;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(User $user, string $url, array $body): void
    {
        $this->client->request('POST', $url, [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(User $user, string $url, array $body): void
    {
        $this->client->request('PUT', $url, [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function link(Club $club, string $code, string $fbiLabel, string $venueLabel, ?string $ref, float $lat, float $lon, OpponentVenueLinkSource $source): OpponentVenueLink
    {
        $this->scopeGucToClub($club->getId());
        $normalizer = self::getContainer()->get(VenueLabelNormalizer::class);
        $link = (new OpponentVenueLink)
            ->setClubId($club->getId())
            ->setOpponentOrganismeCode($code)
            ->setFbiLabel($fbiLabel)
            ->setFbiLabelNorm($normalizer->normalize($fbiLabel))
            ->setVenueExternalRef($ref)
            ->setVenueLabel($venueLabel)
            ->setLatitude($lat)
            ->setLongitude($lon)
            ->setSource($source);
        $this->em->persist($link);
        $this->em->flush();

        return $link;
    }

    private function cacheTravel(Club $club, float $destLat, float $destLon, int $minutes): void
    {
        self::getContainer()->get(TravelTimeCache::class)->store($club->getId(), IgnRoutingClient::PROFILE_CAR, self::SIEGE_LAT, self::SIEGE_LON, $destLat, $destLon, $minutes);
    }

    /**
     * @return array{0: Club, 1: User, 2: Season}
     */
    private function seedClub(): array
    {
        $uid = uniqid('trav', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club trajet ' . $uid);
        $club->setSlug('club-trajet-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setLatitude(self::SIEGE_LAT);
        $club->setLongitude(self::SIEGE_LON);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail($uid . '@test.com');
        $user->setFirstName('T');
        $user->setLastName('Rajet');
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

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $user, $season];
    }

    private function awayFixture(Club $club, Season $season, string $code, string $opponentLabel, string $fbiVenueLabel): void
    {
        $fixture = $this->baseFixture($club, $season, $opponentLabel);
        $fixture->setOpponentOrganismeCode($code);
        $fixture->setFbiVenueLabel($fbiVenueLabel);
        $this->em->persist($fixture);
        $this->em->flush();
    }

    private function awayFixtureNoVenueLabel(Club $club, Season $season, string $code, string $opponentLabel): void
    {
        $fixture = $this->baseFixture($club, $season, $opponentLabel);
        $fixture->setOpponentOrganismeCode($code);
        $this->em->persist($fixture);
        $this->em->flush();
    }

    private function awayFixtureNoCode(Club $club, Season $season, string $opponentLabel): void
    {
        $this->em->persist($this->baseFixture($club, $season, $opponentLabel));
        $this->em->flush();
    }

    private function baseFixture(Club $club, Season $season, string $opponentLabel): Fixture
    {
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
        $fixture->setMatchDate(new DateTimeImmutable('+10 days'));
        $fixture->setHomeAway(FixtureHomeAway::AWAY);
        $fixture->setOpponentLabel($opponentLabel);

        return $fixture;
    }

    private function directory(string $code, OpponentLocationPrecision $precision, ?string $city): void
    {
        $entry = new OpponentDirectoryEntry($code, $city ?? 'Adversaire', $precision);
        $entry->setCity($city)->setLatitude(45.7)->setLongitude(4.85);
        $this->em->persist($entry);
        $this->em->flush();
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

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
