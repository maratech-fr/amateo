<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\OpponentTravel;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\OpponentTravelSource;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * PATCH /api/club/siege : le serveur RE-géocode l'adresse via la BAN et écrit
 * adresse/CP/ville + coordonnées depuis SON hit — une latitude forgée dans le corps est
 * IGNORÉE (patron SEC-15). Adresse introuvable → 422. Le stub BAN déterministe
 * (`BanGeocodingHttpClientStub`) rend deux candidats dont le libellé écho la requête.
 */
#[Group('integration')]
final class ClubSiegeTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private Club $club;

    private User $user;

    public function testSiegeIsGeocodedServerSideAndAForgedLatitudeIsIgnored(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('PATCH', '/api/club/siege', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['address' => '5 rue Emile Duniere Villeurbanne', 'latitude' => 99.0, 'longitude' => 99.0], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['geolocated']);
        // L'adresse rendue est le LIBELLÉ du hit BAN (le stub y ajoute « — Gymnase A »), pas le texte brut.
        self::assertStringContainsString('Gymnase A', (string) $data['address']);

        // La lat/long vient du hit BAN (45.75 / 4.85), JAMAIS de la latitude forgée du corps.
        $this->em->clear();
        $club = $this->em->getRepository(Club::class)->find($this->club->getId());
        self::assertNotNull($club);
        self::assertSame(45.75, $club->getLatitude(), 'la latitude vient de la BAN, jamais du corps (99 forgé ignoré)');
        self::assertSame(4.85, $club->getLongitude());
    }

    public function testChangingTheSiegeInvalidatesTheDerivedOpponentTravels(): void
    {
        // Le club a déjà un siège AILLEURS (40/3) et un trajet adverse calculé depuis lui.
        $this->scopeGucToClub($this->club->getId());
        $this->club->setLatitude(40.0)->setLongitude(3.0);
        $season = new Season;
        $season->setClubId($this->club->getId());
        $season->setName((string) SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        $season->setStartDate(new DateTimeImmutable('today'));
        $season->setEndDate(new DateTimeImmutable('+300 days'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $stale = (new OpponentTravel)
            ->setClubId($this->club->getId())->setSeasonId($season->getId())
            ->setOpponentOrganismeCode('ARA0069STALE')
            ->setSource(OpponentTravelSource::AUTO)->setTravelMinutes(50)
            ->setResolvedAt(new DateTimeImmutable);
        $this->em->persist($stale);
        $this->em->flush();
        $this->em->clear();

        // Le siège DÉMÉNAGE (le stub BAN rend 45.75/4.85, ≠ 40/3).
        $this->client->loginUser($this->user);
        $this->client->request('PATCH', '/api/club/siege', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['address' => '5 rue Emile Duniere Villeurbanne'], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        // Le trajet dérivé de l'ancien siège est PÉRIMÉ → travel_minutes remis à null
        // (le worker le recalculera depuis le nouveau siège).
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        $row = $this->em->getRepository(OpponentTravel::class)->findOneBy(['opponentOrganismeCode' => 'ARA0069STALE']);
        self::assertInstanceOf(OpponentTravel::class, $row);
        self::assertNull($row->getTravelMinutes(), 'un siège qui déménage périme les trajets dérivés');
    }

    public function testAddressNotFoundIs422(): void
    {
        $this->client->loginUser($this->user);

        // Requête trop courte (< 3 caractères) → aucun hit → 422 parlant, aucune écriture.
        $this->client->request('PATCH', '/api/club/siege', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['address' => 'ab'], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('Siège Test Club');
        $this->club->setSlug('siege-test-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->club->setFfbbClubCode('SIE' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('siege' . $uid . '@test.com');
        $this->user->setFirstName('Siege');
        $this->user->setLastName('Tester');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $cu = new ClubUser;
        $cu->setClubId($this->club->getId());
        $cu->setUserId($this->user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);
        $this->em->flush();
    }
}
