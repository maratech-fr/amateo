<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentTravel;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentLocationPrecision;
use App\Enum\OpponentTravelSource;
use App\Enum\SeasonStatus;
use App\Repository\OpponentVenueSuggestionRepository;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * P2-54 RMM-9 PR-3 — the read shape of GET /api/opponents/travel (the display feed
 * the travel radar UI consumes): per distinct AWAY opponent, precision, location
 * name, one-way travel, the server-computed `approximated` flag, and the
 * AUTO/MANUAL source. A localised opponent, a MANUAL override, and a non-localised
 * one (no stamped code) are asserted together.
 */
#[Group('integration')]
final class OpponentTravelApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testTheReadFeedShapesEachOpponent(): void
    {
        [$club, $user, $season] = $this->seedClub();

        // 1) An opponent located at VENUE precision, with an AUTO travel of 22 min.
        $this->awayFixture($club, $season, 'ARA0069001', 'Gymnase visité FC');
        $this->directory('ARA0069001', OpponentLocationPrecision::VENUE, 'Halle Clemenceau', 'Grenoble');
        $this->travel($club, $season, 'ARA0069001', 22, OpponentTravelSource::AUTO, null);

        // 2) An opponent known only at CITY precision → approximated, no travel yet.
        $this->awayFixture($club, $season, 'ARA0069002', 'Meyzieu Basket');
        $this->directory('ARA0069002', OpponentLocationPrecision::CITY, null, 'Meyzieu');

        // 3) A MANUAL override (a hand-pinned gym) → source MANUAL, not approximated.
        $this->awayFixture($club, $season, 'ARA0069003', 'Adversaire corrigé');
        $this->directory('ARA0069003', OpponentLocationPrecision::CITY, null, 'Bron');
        $this->travel($club, $season, 'ARA0069003', 31, OpponentTravelSource::MANUAL, 'Le vrai gymnase');

        // 4) A non-localised opponent: no stamped code at all.
        $this->awayFixtureNoCode($club, $season, 'Club sans code');

        $this->client->request('GET', '/api/opponents/travel', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);
        $opponents = $this->responseData()['opponents'];
        $byLabel = [];
        foreach ($opponents as $opponent) {
            $byLabel[$opponent['opponentLabel']] = $opponent;
        }
        self::assertCount(4, $byLabel);

        $venue = $byLabel['Gymnase visité FC'];
        self::assertTrue($venue['located']);
        self::assertSame('VENUE', $venue['precision']);
        self::assertSame('Halle Clemenceau', $venue['locationName']);
        self::assertSame(22, $venue['travelMinutes']);
        self::assertSame('done', $venue['travelStatus'], 'trajet présent → done');
        self::assertFalse($venue['approximated']);
        self::assertSame('AUTO', $venue['source']);

        $city = $byLabel['Meyzieu Basket'];
        self::assertTrue($city['located']);
        self::assertSame('CITY', $city['precision']);
        self::assertSame('Meyzieu', $city['locationName']);
        self::assertNull($city['travelMinutes']);
        // Localisé mais sans trajet ET aucun calcul en cours (C5 : pending jamais) → unavailable.
        self::assertSame('unavailable', $city['travelStatus']);
        self::assertTrue($city['approximated'], 'city precision is the server-computed « approché » flag');
        self::assertNull($city['source']);

        $manual = $byLabel['Adversaire corrigé'];
        self::assertSame('MANUAL', $manual['source']);
        self::assertSame('Le vrai gymnase', $manual['overrideVenueLabel']);
        self::assertSame('Le vrai gymnase', $manual['locationName']);
        self::assertSame('VENUE', $manual['precision'], 'a hand-pinned gym is venue-precise, never approximated');
        self::assertFalse($manual['approximated']);
        self::assertSame(31, $manual['travelMinutes']);
        self::assertSame('done', $manual['travelStatus']);

        $unlocated = $byLabel['Club sans code'];
        self::assertFalse($unlocated['located']);
        self::assertNull($unlocated['opponentOrganismeCode']);
        self::assertNull($unlocated['precision']);
        self::assertNull($unlocated['travelMinutes']);
        self::assertSame('unavailable', $unlocated['travelStatus'], 'pas de lieu à router → unavailable');
    }

    /**
     * P2-54 « adversaire multi-gymnases » — deux équipes du MÊME organisme (« - 1 » et
     * « - 2 ») sont DEUX entrées distinctes, libellés BRUTS conservés, teamKey distincts.
     * Chacune résout son trajet équipe → club : « - 2 » porte une surcharge équipe
     * (scope TEAM), « - 1 » retombe sur le défaut club (scope CLUB). Ville et code postal
     * de l'annuaire sont exposés.
     */
    public function testEntriesAreGroupedPerOpponentTeamAndResolveTeamThenClub(): void
    {
        [$club, $user, $season] = $this->seedClub();

        $code = 'ARA00690T1';
        $this->awayFixture($club, $season, $code, 'BASKET 5EME - 1');
        $this->awayFixture($club, $season, $code, 'BASKET 5EME - 2');
        $this->directoryFull($code, OpponentLocationPrecision::VENUE, 'Halle Clemenceau', 'Lyon', '69001');
        $this->travel($club, $season, $code, 60, OpponentTravelSource::AUTO, null); // club default
        $this->teamTravel($club, $season, $code, $this->teamKey('BASKET 5EME - 2'), 20, 'Gymnase équipe 2');

        $this->client->request('GET', '/api/opponents/travel', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);
        $byLabel = [];
        foreach ($this->responseData()['opponents'] as $opponent) {
            $byLabel[$opponent['opponentLabel']] = $opponent;
        }
        self::assertCount(2, $byLabel, 'deux équipes du même organisme = deux entrées');

        $team1 = $byLabel['BASKET 5EME - 1'];
        self::assertSame($this->teamKey('BASKET 5EME - 1'), $team1['opponentTeamKey']);
        self::assertSame('CLUB', $team1['scope'], '« - 1 » n\'a pas de ligne équipe → défaut club');
        self::assertSame(60, $team1['travelMinutes']);
        self::assertSame('Lyon', $team1['city']);
        self::assertSame('69001', $team1['postalCode']);

        $team2 = $byLabel['BASKET 5EME - 2'];
        self::assertSame($this->teamKey('BASKET 5EME - 2'), $team2['opponentTeamKey']);
        self::assertSame('TEAM', $team2['scope'], '« - 2 » porte sa propre surcharge → scope TEAM');
        self::assertSame(20, $team2['travelMinutes']);
        self::assertSame('MANUAL', $team2['source']);
        self::assertSame('Gymnase équipe 2', $team2['overrideVenueLabel']);
    }

    /**
     * Une surcharge MANUAL d'ÉQUIPE puis une CLUB : la ligne équipe survit (elle n'est
     * jamais écrasée par la portée CLUB, qui ne touche que la ligne club).
     */
    public function testManualTeamThenClubKeepsTheTeamOverride(): void
    {
        [$club, $user, $season] = $this->seedClub();

        $code = 'ARA00690T2';
        $this->awayFixture($club, $season, $code, 'ALLIANCE - 1');
        $this->awayFixture($club, $season, $code, 'ALLIANCE - 2');
        $this->directoryFull($code, OpponentLocationPrecision::CITY, null, 'Bron', '69500');

        // Pin a gym for team « - 2 » (scope TEAM by default when a teamKey is given).
        $this->post($user, '/api/opponents/travel/manual', [
            'opponentOrganismeCode' => $code,
            'opponentTeamKey' => $this->teamKey('ALLIANCE - 2'),
            'venueLabel' => 'Gymnase de l\'équipe 2',
            'latitude' => 45.7,
            'longitude' => 4.9,
        ]);
        self::assertResponseStatusCodeSame(200);
        $teamResponse = $this->responseData();
        self::assertSame('TEAM', $teamResponse['scope']);
        self::assertSame($this->teamKey('ALLIANCE - 2'), $teamResponse['opponentTeamKey']);

        // Then pin a CLUB default (no teamKey) — must NOT overwrite the team row.
        $this->post($user, '/api/opponents/travel/manual', [
            'opponentOrganismeCode' => $code,
            'venueLabel' => 'Gymnase par défaut du club',
            'latitude' => 45.6,
            'longitude' => 4.8,
        ]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('CLUB', $this->responseData()['scope']);

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $teamRow = $this->em->getRepository(OpponentTravel::class)->findOneBy(['opponentOrganismeCode' => $code, 'opponentTeamKey' => $this->teamKey('ALLIANCE - 2')]);
        self::assertInstanceOf(OpponentTravel::class, $teamRow);
        self::assertSame('Gymnase de l\'équipe 2', $teamRow->getOverrideVenueLabel(), 'la ligne équipe MANUAL survit à la surcharge CLUB');
    }

    /**
     * « Rétablir l'automatique » sur une ligne ÉQUIPE = SUPPRESSION (A3) : la ligne équipe
     * disparaît et la rencontre retombe sur le défaut du club.
     */
    public function testAutoOnATeamDeletesItAndFallsBackToTheClub(): void
    {
        [$club, $user, $season] = $this->seedClub();

        $code = 'ARA00690T3';
        $this->awayFixture($club, $season, $code, 'RIVAL - 1');
        $this->directoryFull($code, OpponentLocationPrecision::CITY, null, 'Vaulx', '69120');
        $this->travel($club, $season, $code, 55, OpponentTravelSource::MANUAL, 'Gymnase club'); // club default
        $this->teamTravel($club, $season, $code, $this->teamKey('RIVAL - 1'), 12, 'Gymnase équipe 1');

        $this->post($user, '/api/opponents/travel/auto', [
            'opponentOrganismeCode' => $code,
            'opponentTeamKey' => $this->teamKey('RIVAL - 1'),
        ]);
        self::assertResponseStatusCodeSame(200);
        // The response reflects the fallback: the team is gone, the club row now governs.
        self::assertSame(55, $this->responseData()['travelMinutes']);

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        self::assertNull(
            $this->em->getRepository(OpponentTravel::class)->findOneBy(['opponentOrganismeCode' => $code, 'opponentTeamKey' => $this->teamKey('RIVAL - 1')]),
            'la ligne équipe a été supprimée (A3)',
        );
        self::assertInstanceOf(
            OpponentTravel::class,
            $this->em->getRepository(OpponentTravel::class)->findOneBy(['opponentOrganismeCode' => $code, 'opponentTeamKey' => null]),
            'la ligne club survit et gouverne désormais',
        );
    }

    /**
     * P2-54 PR-2 — l'endpoint des suggestions partagées : management-only (A6, 403 pour
     * un simple membre), 422 pour un code qui n'est pas un adversaire AWAY de la saison,
     * et la FORME + le TRI (FFBB_API d'abord, puis MANUAL par compte décroissant).
     */
    public function testVenueSuggestionsAreForbiddenForANonManagementMember(): void
    {
        [$club, , $season] = $this->seedClub();
        $code = 'ARA00690S1';
        $this->awayFixture($club, $season, $code, 'ADVERSAIRE SUGG');
        $member = $this->addMember($club, 'member');

        $this->client->request('GET', '/api/opponents/' . $code . '/venue-suggestions', [], [], $this->authHeaders($member) + ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(403, 'A6 : assertManager() d\'abord, un simple membre est refusé');
    }

    public function testVenueSuggestionsRejectACodeThatIsNotAnAwayOpponent(): void
    {
        [, $user] = $this->seedClub();

        $this->client->request('GET', '/api/opponents/ARA0069XXX/venue-suggestions', [], [], $this->authHeaders($user) + ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422, 'un code sans rencontre AWAY cette saison → 422');
    }

    public function testVenueSuggestionsAreShapedAndSortedApiFirstThenManualByCount(): void
    {
        [$club, $user, $season] = $this->seedClub();
        $code = 'ARA00690S2';
        $this->awayFixture($club, $season, $code, 'ADVERSAIRE MULTI GYMS');

        $suggestions = $this->suggestions();
        // Une observation FFBB_API (sans ref) + deux choix MANUAL de popularités distinctes.
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

        // Tri : FFBB_API d'abord, puis MANUAL par compte décroissant.
        self::assertSame('FFBB_API', $list[0]['source']);
        self::assertNull($list[0]['externalRef'], 'une suggestion FFBB_API n\'a pas de référence de salle');
        self::assertSame('GYMNASE FEDERAL', $list[0]['label']);
        self::assertSame('MANUAL', $list[1]['source']);
        self::assertSame('GYMNASE POPULAIRE', $list[1]['label']);
        self::assertSame(2, $list[1]['chosenByCount']);
        self::assertSame('166900901', $list[1]['externalRef']);
        self::assertSame('MANUAL', $list[2]['source']);
        self::assertSame(1, $list[2]['chosenByCount'], 'le moins choisi vient après');

        // Forme : toutes les clés attendues, exactement.
        self::assertSame(
            ['externalRef', 'label', 'city', 'postalCode', 'latitude', 'longitude', 'source', 'chosenByCount', 'lastChosenAt'],
            array_keys($list[0]),
        );
        self::assertSame('Lyon', $list[0]['city']);
        self::assertSame('69001', $list[0]['postalCode']);
    }

    /**
     * SEC-19 — POST /api/opponents/travel/manual est borné PAR UTILISATEUR (30/h), non
     * assoupli en test à dessein. On isole CETTE borne du limiteur `api` global (30/min
     * en test) en pré-consommant 30 jetons du limiteur manuel pour l'utilisateur, puis en
     * ne faisant QU'UN appel HTTP : le 31ᵉ jeton MANUEL est refusé, avec SON message (et
     * non « API rate limit exceeded »). Utilisateur frais → clé de limiteur vierge, aucune
     * pollution d'un autre test (jamais de FLUSHALL).
     */
    public function testTheManualLimiterTripsAtThirtyOneForOneUser(): void
    {
        [$club, $user, $season] = $this->seedClub();
        $code = 'ARA00690L1';
        $this->awayFixture($club, $season, $code, 'ADVERSAIRE LIMITE');

        // Épuise la borne manuelle (30/h) hors HTTP, sur la clé exacte du contrôleur
        // (l'id utilisateur) — le même Redis partagé que le contrôleur lira.
        $limiter = self::getContainer()->get('limiter.opponent_travel_manual');
        self::assertInstanceOf(RateLimiterFactory::class, $limiter);
        for ($i = 0; $i < 30; ++$i) {
            self::assertTrue($limiter->create($user->getId())->consume(1)->isAccepted(), "jeton {$i} sous la borne");
        }

        // Un SEUL appel HTTP (1 jeton `api` seulement, très sous 30/min) : le 31ᵉ jeton
        // MANUEL est refusé, et c'est bien la borne SEC-19 (son message) qui tranche.
        $this->post($user, '/api/opponents/travel/manual', ['opponentOrganismeCode' => $code, 'venueLabel' => 'Gymnase', 'latitude' => 45.7, 'longitude' => 4.8]);
        self::assertResponseStatusCodeSame(429, 'le 31ᵉ épinglage dépasse la borne manuelle');
        self::assertStringContainsString('gymnases', (string) $this->client->getResponse()->getContent(), 'c\'est bien la borne manuelle (SEC-19), pas le limiteur api global');
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

    private function teamKey(string $label): string
    {
        $normalizer = self::getContainer()->get(VenueLabelNormalizer::class);
        self::assertInstanceOf(VenueLabelNormalizer::class, $normalizer);

        return $normalizer->normalize(trim($label));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(User $user, string $url, array $body): void
    {
        $this->client->request('POST', $url, [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function directoryFull(string $code, OpponentLocationPrecision $precision, ?string $venueLabel, ?string $city, ?string $postalCode): void
    {
        $entry = new OpponentDirectoryEntry($code, $city ?? 'Adversaire', $precision);
        $entry->setCity($city)->setPostalCode($postalCode)->setVenueLabel($venueLabel)->setLatitude(45.7)->setLongitude(4.85);
        $this->em->persist($entry);
        $this->em->flush();
    }

    private function teamTravel(Club $club, Season $season, string $code, string $teamKey, int $minutes, string $overrideLabel): void
    {
        $this->scopeGucToClub($club->getId());
        $row = (new OpponentTravel)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setOpponentOrganismeCode($code)
            ->setOpponentTeamKey($teamKey)
            ->setTravelMinutes($minutes)
            ->setSource(OpponentTravelSource::MANUAL)
            ->setOverrideVenueLabel($overrideLabel)
            ->setOverrideLatitude(45.6)
            ->setOverrideLongitude(4.7)
            ->setResolvedAt(new DateTimeImmutable);
        $this->em->persist($row);
        $this->em->flush();
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

    private function awayFixture(Club $club, Season $season, string $code, string $opponentLabel): void
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

    private function directory(string $code, OpponentLocationPrecision $precision, ?string $venueLabel, ?string $city): void
    {
        $entry = new OpponentDirectoryEntry($code, $city ?? 'Adversaire', $precision);
        $entry->setCity($city)->setVenueLabel($venueLabel)->setLatitude(45.7)->setLongitude(4.85);
        $this->em->persist($entry);
        $this->em->flush();
    }

    private function travel(Club $club, Season $season, string $code, int $minutes, OpponentTravelSource $source, ?string $overrideLabel): void
    {
        $this->scopeGucToClub($club->getId());
        $row = (new OpponentTravel)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setOpponentOrganismeCode($code)
            ->setTravelMinutes($minutes)
            ->setSource($source)
            ->setResolvedAt(new DateTimeImmutable);
        if (null !== $overrideLabel) {
            $row->setOverrideVenueLabel($overrideLabel)->setOverrideLatitude(45.6)->setOverrideLongitude(4.7);
        }
        $this->em->persist($row);
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
