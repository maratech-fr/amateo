<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Controller\VenueExternalLabelController;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\FixtureHomeAway;
use App\Enum\SeasonStatus;
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
 * P4-187a — « Rattacher » / « Retirer » un libellé de salle à un gymnase
 * ({@see VenueExternalLabelController}). POST ajoute l'alias
 * (normalisé, idempotent) puis backfille les domiciles du club encore sans salle ;
 * un libellé déjà porté par un autre gymnase (ou vide) → 422 ; DELETE retire
 * l'alias sans dépointer aucune rencontre ; une saison archivée refuse (409).
 */
#[Group('integration')]
final class VenueExternalLabelApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testAttachStoresTheNormalizedAliasAndBackfillsHomeFixtures(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $venue = $this->createVenue($club, $season, 'Palais des Sports');
        $home1 = $this->createFixture($club, $season, FixtureHomeAway::HOME, 'GYMNASE MATEO');
        $home2 = $this->createFixture($club, $season, FixtureHomeAway::HOME, 'Gymnase Matéo');
        $away = $this->createFixture($club, $season, FixtureHomeAway::AWAY, 'GYMNASE MATEO');

        $body = $this->post($user, $venue->getId(), 'GYMNASE MATEO');
        self::assertResponseIsSuccessful();
        self::assertSame('gymnase mateo', $body['label']);
        self::assertSame($venue->getId(), $body['venueId']);
        self::assertSame(2, $body['attached'], 'les deux domiciles au même libellé sont rattachés, l\'extérieur non');

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        self::assertSame(['gymnase mateo'], $this->em->getRepository(Venue::class)->find($venue->getId())?->getExternalLabels());
        self::assertSame($venue->getId(), $this->em->getRepository(Fixture::class)->find($home1)?->getVenueId());
        self::assertSame($venue->getId(), $this->em->getRepository(Fixture::class)->find($home2)?->getVenueId());
        self::assertNull($this->em->getRepository(Fixture::class)->find($away)?->getVenueId(), 'un extérieur n\'est jamais rattaché');
    }

    public function testReAttachIsIdempotentAndCountsOnlyNewlyAttached(): void
    {
        [$club, $user, $season] = $this->createClubUser('b');
        $venue = $this->createVenue($club, $season, 'Palais des Sports');
        $this->createFixture($club, $season, FixtureHomeAway::HOME, 'GYMNASE MATEO');

        self::assertSame(1, $this->post($user, $venue->getId(), 'GYMNASE MATEO')['attached']);
        $again = $this->post($user, $venue->getId(), 'GYMNASE MATEO');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $again['attached'], 'un re-POST ne recompte rien : les domiciles sont déjà rattachés');
        self::assertSame(['gymnase mateo'], $this->em->getRepository(Venue::class)->find($venue->getId())?->getExternalLabels());
    }

    public function testAttachIsRefusedWhenTheLabelBelongsToAnotherVenue(): void
    {
        [$club, $user, $season] = $this->createClubUser('c');
        $venueA = $this->createVenue($club, $season, 'Gymnase Alpha');
        $venueB = $this->createVenue($club, $season, 'Gymnase Beta');

        $this->post($user, $venueA->getId(), 'GYMNASE MATEO');
        self::assertResponseIsSuccessful();
        $this->post($user, $venueB->getId(), 'GYMNASE MATEO');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Gymnase Alpha', (string) $this->client->getResponse()->getContent());
    }

    public function testAttachIsRefusedForAnEmptyLabel(): void
    {
        [$club, $user, $season] = $this->createClubUser('d');
        $venue = $this->createVenue($club, $season, 'Palais des Sports');

        $this->post($user, $venue->getId(), '  !!  ');
        self::assertResponseStatusCodeSame(422);
    }

    public function testAttachIsRefusedForAnOversizedLabel(): void
    {
        [$club, $user, $season] = $this->createClubUser('dl');
        $venue = $this->createVenue($club, $season, 'Palais des Sports');

        $this->post($user, $venue->getId(), str_repeat('gymnase ', 40));
        self::assertResponseStatusCodeSame(422);
    }

    public function testAttachIsRefusedBeyondThirtyAliasesOnOneVenue(): void
    {
        [$club, $user, $season] = $this->createClubUser('dc');
        $venue = $this->createVenue($club, $season, 'Palais des Sports');

        // Les 30 premiers alias sont semés en base (le limiteur d'API de test mordrait
        // avant le plafond) ; seule la 31e graphie passe par la route.
        for ($i = 1; $i <= 30; ++$i) {
            $venue->addExternalLabel(\sprintf('salle %d', $i));
        }
        $this->em->flush();
        $this->post($user, $venue->getId(), 'salle 31');
        self::assertResponseStatusCodeSame(422);
        // Un alias déjà porté reste acceptable (idempotence), le plafond ne le bloque pas.
        $this->post($user, $venue->getId(), 'salle 1');
        self::assertResponseIsSuccessful();
    }

    public function testDetachRemovesTheAliasWithoutUnlinkingAnyFixture(): void
    {
        [$club, $user, $season] = $this->createClubUser('e');
        $venue = $this->createVenue($club, $season, 'Palais des Sports');
        $home = $this->createFixture($club, $season, FixtureHomeAway::HOME, 'GYMNASE MATEO');
        $this->post($user, $venue->getId(), 'GYMNASE MATEO');

        $this->client->request('DELETE', '/api/venues/' . $venue->getId() . '/external-labels/gymnase%20mateo', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(204);

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        self::assertSame([], $this->em->getRepository(Venue::class)->find($venue->getId())?->getExternalLabels());
        // Le domicile déjà rattaché GARDE son gymnase : retirer l'alias ne dépointe rien.
        self::assertSame($venue->getId(), $this->em->getRepository(Fixture::class)->find($home)?->getVenueId());

        $this->client->request('DELETE', '/api/venues/' . $venue->getId() . '/external-labels/gymnase%20mateo', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(204, 'DELETE est idempotent');
    }

    public function testAttachOnAnArchivedSeasonVenueIsRefused(): void
    {
        [$club, $user] = $this->createClubUser('f');
        $this->scopeGucToClub($club->getId());
        $pastYear = SeasonResolver::seasonYear(new DateTimeImmutable('today')) - 1;
        $past = new Season;
        $past->setClubId($club->getId());
        $past->setName((string) $pastYear);
        $past->setStartDate(new DateTimeImmutable($pastYear . '-08-01'));
        $past->setEndDate(new DateTimeImmutable(($pastYear + 1) . '-07-15'));
        $past->setStatus(SeasonStatus::ARCHIVED);
        $past->setTransitionData([]);
        $this->em->persist($past);
        $this->em->flush();
        $venue = $this->createVenue($club, $past, 'Vieux Gymnase');

        $this->post($user, $venue->getId(), 'GYMNASE MATEO');
        self::assertResponseStatusCodeSame(409);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function post(User $user, string $venueId, string $label): array
    {
        $this->client->request('POST', '/api/venues/' . $venueId . '/external-labels', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode(['label' => $label], \JSON_THROW_ON_ERROR));

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function createVenue(Club $club, Season $season, string $name): Venue
    {
        $this->scopeGucToClub($club->getId());
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function createFixture(Club $club, Season $season, FixtureHomeAway $homeAway, string $venueLabel): string
    {
        $this->scopeGucToClub($club->getId());
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-04'));
        $fixture->setHomeAway($homeAway);
        $fixture->setOpponentLabel('Adversaire');
        $fixture->setFbiVenueLabel($venueLabel);
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture->getId();
    }

    /**
     * @return array{0: Club, 1: User, 2: Season}
     */
    private function createClubUser(string $suffix): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club alias ' . $suffix);
        $club->setSlug('club-alias-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('alias' . $uid . '@test.com');
        $user->setFirstName('Alias');
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

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }
}
