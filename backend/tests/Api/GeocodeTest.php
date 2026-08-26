<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Tests\TenantGucTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P2-53 RMM-8 (PR-1) — la route de géocodage (BAN stubbée) : elle sert des
 * candidats {label, lat, long, score} à un gestionnaire, refuse une requête vide
 * ou trop longue (422) et se ferme au non-management (403).
 */
#[Group('integration')]
final class GeocodeTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testCandidatesAreServedToAManager(): void
    {
        [$club, $user] = $this->createClubUser('a');
        unset($club);

        $this->client->request('GET', '/api/geocode', ['q' => '8 rue du Test'], [], $this->authHeaders($user));
        self::assertResponseIsSuccessful();
        $candidates = $this->responseData()['candidates'] ?? null;

        self::assertIsArray($candidates);
        self::assertNotEmpty($candidates, 'la BAN stubbée sert des candidats');
        $first = $candidates[0];
        self::assertArrayHasKey('label', $first);
        self::assertArrayHasKey('latitude', $first);
        self::assertArrayHasKey('longitude', $first);
        self::assertArrayHasKey('score', $first);
    }

    public function testEmptyOrTooLongQueryIsRefused(): void
    {
        [, $user] = $this->createClubUser('b');

        $this->client->request('GET', '/api/geocode', ['q' => ''], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(422, 'une requête vide est refusée');

        $this->client->request('GET', '/api/geocode', ['q' => str_repeat('a', 250)], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(422, 'une requête de plus de 200 caractères est refusée');
    }

    public function testNonManagementMemberIsForbidden(): void
    {
        [$club] = $this->createClubUser('c');
        $member = $this->createMember($club, 'member');

        $this->client->request('GET', '/api/geocode', ['q' => '8 rue du Test'], [], $this->authHeaders($member));
        self::assertResponseStatusCodeSame(403, 'le géocodage est management-only');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function createMember(Club $club, string $role): User
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $uid = uniqid($role, true);
        $user = new User;
        $user->setEmail($role . $uid . '@test.com');
        $user->setFirstName('N');
        $user->setLastName('Member');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $user;
    }

    /**
     * @return array{0: Club, 1: User}
     */
    private function createClubUser(string $suffix): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club geo ' . $suffix);
        $club->setSlug('club-geo-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('geo' . $uid . '@test.com');
        $user->setFirstName('Geo');
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
        $this->em->flush();

        return [$club, $user];
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
