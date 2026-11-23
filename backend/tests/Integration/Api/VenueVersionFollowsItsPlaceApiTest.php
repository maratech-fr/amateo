<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot 3, PR 4 : l'adresse et la position d'un gymnase vivent sur son lieu au club, pas sur sa ligne.
 * La version servie par `/api/venues` (celle que l'écran renvoie en `If-Match`) change quand l'adresse
 * ou la position change, comme quand elles étaient sur la ligne ; un PUT qui ne change rien ne la
 * change pas (sinon l'écran ouvert ailleurs se verrait refuser une écriture sans raison).
 */
#[Group('integration')]
final class VenueVersionFollowsItsPlaceApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testCorrectingTheAddressChangesTheVersionOfTheVenue(): void
    {
        $user = $this->seed();
        $created = $this->send($user, 'POST', '/api/venues', ['name' => 'Gymnase Nord', 'address' => '12 rue du Nord', 'latitude' => '45.7700000', 'longitude' => '4.8800000']);

        $after = $this->send($user, 'PUT', '/api/venues/' . $created['id'], ['name' => 'Gymnase Nord', 'address' => '14 rue du Nord']);

        self::assertSame('14 rue du Nord', $after['address']);
        self::assertGreaterThan($created['version'], $after['version'], 'une adresse corrigée change la version du gymnase');
    }

    public function testMovingTheVenueChangesItsVersion(): void
    {
        $user = $this->seed();
        $created = $this->send($user, 'POST', '/api/venues', ['name' => 'Gymnase Nord', 'latitude' => '45.7700000', 'longitude' => '4.8800000']);

        $after = $this->send($user, 'PUT', '/api/venues/' . $created['id'], ['name' => 'Gymnase Nord', 'latitude' => '45.7800000', 'longitude' => '4.8800000']);

        self::assertGreaterThan($created['version'], $after['version'], 'une position corrigée change la version du gymnase');
    }

    public function testAPutThatChangesNothingKeepsTheVersion(): void
    {
        $user = $this->seed();
        $created = $this->send($user, 'POST', '/api/venues', ['name' => 'Gymnase Nord', 'address' => '12 rue du Nord', 'latitude' => '45.7700000', 'longitude' => '4.8800000']);

        $after = $this->send($user, 'PUT', '/api/venues/' . $created['id'], ['name' => 'Gymnase Nord', 'address' => '12 rue du Nord', 'latitude' => '45.7700000', 'longitude' => '4.8800000']);

        self::assertSame($created['version'], $after['version'], 'mêmes nom, adresse et position : la version ne bouge pas');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param array<string, string> $payload
     *
     * @return array{id: string, version: int, address: ?string}
     */
    private function send(User $user, string $method, string $uri, array $payload): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->request($method, $uri, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'], json_encode(['source' => 'manual'] + $payload, \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        /** @var array{id: string, version: int, address: ?string} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body;
    }

    private function seed(): User
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Version Club')->setSlug('version-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')
            ->setOnboardingCompleted(true)->setFfbbClubCode('VER' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);
        $user = (new User)->setEmail('version-' . $uid . '@test.com')->setFirstName('V')->setLastName('C');
        $user->setPasswordHash(self::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $this->em->persist((new ClubUser)->setClubId($club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $this->em->persist((new Season)->setClubId($club->getId())->setName($year . '-' . ($year + 1))
            ->setStartDate(new DateTimeImmutable($year . '-08-01'))->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]));
        $this->em->flush();

        return $user;
    }
}
