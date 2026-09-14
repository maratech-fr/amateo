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
use App\Enum\FixtureStatus;
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

    public function testFbiLabelsInventoryGroupsByNormalizedLabelWithCountsAndConfirmedVenue(): void
    {
        [$club, $user, $season] = $this->createClubUser('inv');
        $venue = $this->createVenue($club, $season, 'Palais des Sports');
        // Deux graphies « GYMNASE MATEO » + une « Gymnase Matéo » → une seule clé ;
        // la graphie la plus fréquente est « GYMNASE MATEO ».
        $this->createFixture($club, $season, FixtureHomeAway::HOME, 'GYMNASE MATEO');
        $this->createFixture($club, $season, FixtureHomeAway::HOME, 'GYMNASE MATEO');
        $this->createFixture($club, $season, FixtureHomeAway::HOME, 'Gymnase Matéo', status: FixtureStatus::PLACED);
        $this->createFixture($club, $season, FixtureHomeAway::AWAY, 'GYMNASE MATEO');

        $rows = $this->getInventory($user)['labels'];
        self::assertCount(1, $rows, 'les deux graphies fusionnent sur une clé normalisée, l\'extérieur ne compte pas');
        self::assertSame('gymnase mateo', $rows[0]['labelKey']);
        self::assertSame('GYMNASE MATEO', $rows[0]['displayLabel'], 'la graphie brute la plus fréquente');
        self::assertSame(3, $rows[0]['homeCount']);
        self::assertSame(1, $rows[0]['placedCount']);
        self::assertSame(2, $rows[0]['unplacedCount']);
        self::assertNull($rows[0]['venueId'], 'aucun alias confirmé → venueId null');

        // Confirmer l'alias → la ligne pointe le gymnase.
        $this->post($user, $venue->getId(), 'GYMNASE MATEO');
        self::assertResponseIsSuccessful();
        $rows = $this->getInventory($user)['labels'];
        self::assertSame($venue->getId(), $rows[0]['venueId'], 'l\'alias confirmé désigne le gymnase');
    }

    public function testFbiLabelsInventorySuggestsAnUnanimousVenueAndNullOnDivergence(): void
    {
        [$club, $user, $season] = $this->createClubUser('sug');
        $venueA = $this->createVenue($club, $season, 'Gymnase Alpha');
        $venueB = $this->createVenue($club, $season, 'Gymnase Beta');
        // Deux domiciles au même libellé, tous deux placés au MÊME gymnase A, aucun
        // alias confirmé → suggestion unanime = A.
        $this->createFixture($club, $season, FixtureHomeAway::HOME, 'GYMNASE MATEO', venueId: $venueA->getId(), status: FixtureStatus::PLACED);
        $this->createFixture($club, $season, FixtureHomeAway::HOME, 'GYMNASE MATEO', venueId: $venueA->getId(), status: FixtureStatus::PLACED);

        $rows = $this->getInventory($user)['labels'];
        self::assertNull($rows[0]['venueId']);
        self::assertSame($venueA->getId(), $rows[0]['suggestedVenueId'], 'un gymnase unanime est suggéré');

        // Un troisième domicile placé sur B → divergence → plus aucune suggestion.
        $this->createFixture($club, $season, FixtureHomeAway::HOME, 'GYMNASE MATEO', venueId: $venueB->getId(), status: FixtureStatus::PLACED);
        $rows = $this->getInventory($user)['labels'];
        self::assertNull($rows[0]['suggestedVenueId'], 'deux gymnases candidats → jamais un pari');
    }

    public function testReassignRepointsUnplacedStripsPreviousOwnerAndKeepsPlaced(): void
    {
        [$club, $user, $season] = $this->createClubUser('rea');
        $wrong = $this->createVenue($club, $season, 'Mauvais Gymnase');
        $right = $this->createVenue($club, $season, 'Bon Gymnase');
        // Trois domiciles au libellé, déjà pointés (à tort) sur le mauvais gymnase :
        // deux UNPLACED (à re-pointer) et un PLACED (témoin, à conserver).
        $u1 = $this->createFixture($club, $season, FixtureHomeAway::HOME, 'GYMNASE MATEO', venueId: $wrong->getId());
        $u2 = $this->createFixture($club, $season, FixtureHomeAway::HOME, 'GYMNASE MATEO', venueId: $wrong->getId());
        $placed = $this->createFixture($club, $season, FixtureHomeAway::HOME, 'GYMNASE MATEO', venueId: $wrong->getId(), status: FixtureStatus::PLACED);

        // L'alias appartient d'abord au mauvais gymnase.
        $this->post($user, $wrong->getId(), 'GYMNASE MATEO');
        self::assertResponseIsSuccessful();

        // Sans drapeau, poser l'alias sur le bon gymnase serait refusé (unicité).
        $this->post($user, $right->getId(), 'GYMNASE MATEO');
        self::assertResponseStatusCodeSame(422);

        // Avec reassign : l'alias change de porteur, les 2 UNPLACED basculent, le PLACED reste.
        $body = $this->postBody($user, $right->getId(), ['label' => 'GYMNASE MATEO', 'reassign' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame('gymnase mateo', $body['label']);
        self::assertSame($right->getId(), $body['venueId']);
        self::assertSame(2, $body['attached'], 'les deux domiciles non placés sont re-pointés');
        self::assertSame(1, $body['kept'], 'le domicile placé garde sa salle et est compté kept');
        self::assertSame($wrong->getId(), $body['previousVenueId'], 'l\'ancien porteur de l\'alias est servi');

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        self::assertSame($right->getId(), $this->em->getRepository(Fixture::class)->find($u1)?->getVenueId());
        self::assertSame($right->getId(), $this->em->getRepository(Fixture::class)->find($u2)?->getVenueId());
        self::assertSame($wrong->getId(), $this->em->getRepository(Fixture::class)->find($placed)?->getVenueId(), 'un domicile placé n\'est jamais re-pointé');
        self::assertSame([], $this->em->getRepository(Venue::class)->find($wrong->getId())?->getExternalLabels(), 'l\'ancien gymnase perd l\'alias');
        self::assertSame(['gymnase mateo'], $this->em->getRepository(Venue::class)->find($right->getId())?->getExternalLabels());
    }

    public function testReassignTowardTheVenueAlreadyCarryingTheAliasIsIdempotent(): void
    {
        [$club, $user, $season] = $this->createClubUser('idem');
        $venue = $this->createVenue($club, $season, 'Palais des Sports');
        $this->createFixture($club, $season, FixtureHomeAway::HOME, 'GYMNASE MATEO');

        // Premier reassign : pose l'alias + re-pointe l'unique UNPLACED (previousVenueId null).
        $first = $this->postBody($user, $venue->getId(), ['label' => 'GYMNASE MATEO', 'reassign' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $first['attached']);
        self::assertNull($first['previousVenueId'], 'aucun ancien porteur');

        // Second reassign identique : le domicile pointe déjà le bon gymnase → rien à re-pointer.
        $again = $this->postBody($user, $venue->getId(), ['label' => 'GYMNASE MATEO', 'reassign' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $again['attached'], 'idempotent : rien de nouveau à re-pointer');
        self::assertNull($again['previousVenueId']);
        self::assertSame(['gymnase mateo'], $this->em->getRepository(Venue::class)->find($venue->getId())?->getExternalLabels());
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function getInventory(User $user): array
    {
        $this->client->request('GET', '/api/venues/fbi-labels', [], [], $this->authHeaders($user));
        self::assertResponseIsSuccessful();

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function postBody(User $user, string $venueId, array $body): array
    {
        $this->client->request('POST', '/api/venues/' . $venueId . '/external-labels', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
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

    private function createFixture(Club $club, Season $season, FixtureHomeAway $homeAway, string $venueLabel, ?string $venueId = null, ?FixtureStatus $status = null): string
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
        if (null !== $venueId) {
            $fixture->setVenueId($venueId);
        }
        if ($status instanceof FixtureStatus) {
            $fixture->setStatus($status, new DateTimeImmutable);
        }
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
