<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\SeasonStatus;
use App\EventListener\TenantFilterListener;
use App\Service\SeasonResolver;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Season isolation NR (transition-de-saison P1, risk #1): the moment a club
 * holds two seasons, every read/write must stay inside the SELECTED season
 * (X-Season-Id, else the calendar-derived current one) — and a season id from
 * another club must be rejected, never silently fall back.
 *
 * Dates are computed relative to today's season-year so the suite never flips
 * around the July-15 pivot.
 */
#[Group('phase1')]
#[Group('integration')]
final class SeasonIsolationTest extends WebTestCase
{
    use CreatesPeopleAndPlacesTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testCollectionsAreScopedToTheSelectedSeason(): void
    {
        [$club, $user, $seasonN, $seasonN1] = $this->createClubWithTwoSeasons();
        $this->createVenue($club, $seasonN, 'Gym saison N');
        $this->createVenue($club, $seasonN1, 'Gym saison N+1');
        $auth = $this->authHeaders($user);

        // No header → current season only.
        $this->client->request('GET', '/api/venues', [], [], $auth);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Gym saison N'], $this->memberNames());

        // Explicit selection → the draft season only.
        $this->client->request('GET', '/api/venues', [], [], $auth + [
            'HTTP_X-Season-Id' => $seasonN1->getId(),
        ]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Gym saison N+1'], $this->memberNames());
    }

    public function testItemReadOfAnotherSeasonEntityIs404(): void
    {
        [$club, $user, , $seasonN1] = $this->createClubWithTwoSeasons();
        $venueN1 = $this->createVenue($club, $seasonN1, 'Gym saison N+1');
        $venueN1Id = $venueN1->getId();
        // Drop the identity map: em->find must hit SQL (and thus the filter),
        // as it would on a fresh production request.
        $this->em->clear();

        // Current season selected (no header) → the N+1 venue must be invisible.
        $this->client->request('GET', '/api/venues/' . $venueN1Id, [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(404);
    }

    public function testPostStampsTheSelectedSeason(): void
    {
        [, $user, $seasonN, $seasonN1] = $this->createClubWithTwoSeasons();

        $this->client->request('POST', '/api/venues', [], [], $this->authHeaders($user) + [
            'HTTP_X-Season-Id' => $seasonN1->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => 'Gym créé en N+1', 'source' => 'manual'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $venue = $this->em->getRepository(Venue::class)->findOneBy(['name' => 'Gym créé en N+1']);
        self::assertNotNull($venue);
        self::assertSame($seasonN1->getId(), $venue->getSeasonId());
        self::assertNotSame($seasonN->getId(), $venue->getSeasonId());
    }

    public function testForeignClubSeasonHeaderIsRejected(): void
    {
        [, $user] = $this->createClubWithTwoSeasons();
        $foreignSeason = $this->createForeignClubSeason();

        $this->client->request('GET', '/api/venues', [], [], $this->authHeaders($user) + [
            'HTTP_X-Season-Id' => $foreignSeason->getId(),
        ]);
        self::assertResponseStatusCodeSame(403);
        $this->assertSelfHealMarkerIsPresent();
    }

    public function testUnknownSeasonHeaderIsRejected(): void
    {
        [, $user] = $this->createClubWithTwoSeasons();

        $this->client->request('GET', '/api/venues', [], [], $this->authHeaders($user) + [
            'HTTP_X-Season-Id' => '00000000-0000-4000-8000-000000000000',
        ]);
        self::assertResponseStatusCodeSame(403);
        $this->assertSelfHealMarkerIsPresent();
    }

    public function testMalformedSeasonHeaderIsRejected(): void
    {
        [, $user] = $this->createClubWithTwoSeasons();

        $this->client->request('GET', '/api/venues', [], [], $this->authHeaders($user) + [
            'HTTP_X-Season-Id' => 'not-a-uuid',
        ]);
        self::assertResponseStatusCodeSame(403);
        $this->assertSelfHealMarkerIsPresent();
    }

    public function testResetSeasonOnAPastSeasonIsRefused(): void
    {
        [$club, $user] = $this->createClubWithTwoSeasons();
        $this->scopeGucToClub($club->getId());
        $past = $this->createSeason($club, SeasonResolver::seasonYear(new DateTimeImmutable('today')) - 1);
        $this->em->flush();

        // Wiping an archived season is refused (409) — the archive is frozen.
        $this->client->request('DELETE', '/api/reset-season', [], [], $this->authHeaders($user) + [
            'HTTP_X-Season-Id' => $past->getId(),
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    /**
     * Un réglage de règle implicite (bien-être) est season-scopé : posé sur la saison N, il ne
     * doit PAS teindre la collection RÉSOLUE de la saison N+1 (qui reste au défaut HARD).
     */
    public function testImplicitRuleSettingsAreScopedToTheSelectedSeason(): void
    {
        [, $user, , $seasonN1] = $this->createClubWithTwoSeasons();
        $auth = $this->authHeaders($user);

        // PUT sur la saison COURANTE (sans header) : coachRestDay assoupli en PREFERRED.
        $this->client->request('PUT', '/api/implicit_rule_settings/coachRestDay', [], [], $auth + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['intensity' => 'PREFERRED', 'minRestDays' => 2], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        // La saison courante voit l'assouplissement.
        $this->client->request('GET', '/api/implicit_rule_settings', [], [], $auth);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('PREFERRED', $this->resolvedIntensity('coachRestDay'), 'la saison courante voit son réglage');

        // La saison N+1 reste au défaut : le réglage de N ne fuit pas.
        $this->client->request('GET', '/api/implicit_rule_settings', [], [], $auth + [
            'HTTP_X-Season-Id' => $seasonN1->getId(),
        ]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('HARD', $this->resolvedIntensity('coachRestDay'), 'la saison N+1 reste au défaut — pas de fuite entre saisons');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** L'intensité résolue d'une règle dans la dernière réponse collection. */
    private function resolvedIntensity(string $ruleKey): string
    {
        /** @var array{member?: list<array{ruleKey: string, intensity: string}>} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        foreach ($data['member'] ?? [] as $rule) {
            if ($rule['ruleKey'] === $ruleKey) {
                return $rule['intensity'];
            }
        }

        self::fail(\sprintf('la règle %s doit figurer dans la collection résolue', $ruleKey));
    }

    /**
     * Stateless JWT firewall: a Bearer token must ride EVERY request
     * (loginUser only carries the first one).
     *
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /**
     * Season N runs the current season-year, N+1 the next — computed from
     * today so the current/draft split is stable whatever the real date.
     *
     * @return array{0: Club, 1: User, 2: Season, 3: Season}
     */
    private function createClubWithTwoSeasons(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club saison');
        $club->setSlug('club-season-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('SEA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('season' . $uid . '@test.com');
        $user->setFirstName('Saison');
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

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $seasonN = $this->createSeason($club, $year);
        $seasonN1 = $this->createSeason($club, $year + 1);
        $this->em->flush();

        return [$club, $user, $seasonN, $seasonN1];
    }

    private function createForeignClubSeason(): Season
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club étranger');
        $club->setSlug('club-foreign-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('FOR' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = $this->createSeason($club, $year);
        $this->em->flush();

        return $season;
    }

    private function createSeason(Club $club, int $startYear): Season
    {
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName($startYear . '-' . ($startYear + 1));
        $season->setStartDate(new DateTimeImmutable($startYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);

        return $season;
    }

    private function createVenue(Club $club, Season $season, string $name): Venue
    {
        $this->scopeGucToClub($club->getId());
        $venue = $this->newVenue($club->getId(), $season->getId(), $name);
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    /** @return list<string> */
    private function memberNames(): array
    {
        /** @var array{member?: list<array{name: string}>} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $names = array_map(static fn (array $m): string => $m['name'], $data['member'] ?? []);
        sort($names);

        return $names;
    }

    /**
     * D-39 — le marqueur d'auto-réparation doit accompagner CHAQUE rejet de saison.
     *
     * Ce n'est pas un détail d'en-tête : le backend 403 TOUTE requête portant une saison
     * morte, `/api/me` comprise. Sans ce marqueur, le front ne peut pas distinguer « lâche
     * ta saison » d'un refus d'autorisation légitime — il garderait la saison morte et
     * resterait en boucle 403 SANS chemin de retour. Un producteur, un consommateur, et
     * jusqu'ici aucun test des deux côtés.
     */
    private function assertSelfHealMarkerIsPresent(): void
    {
        self::assertTrue(
            $this->client->getResponse()->headers->has(TenantFilterListener::STALE_SEASON_HEADER),
            \sprintf(
                'Le rejet de saison doit porter %s — sans lui le frontend ne peut pas se réparer et boucle en 403.',
                TenantFilterListener::STALE_SEASON_HEADER,
            ),
        );
    }
}
