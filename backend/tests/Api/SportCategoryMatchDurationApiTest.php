<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Sport;
use App\Entity\User;
use App\Tests\TenantGucTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P2-54 RMM-9 PR-1 — la durée de match par catégorie côté API : les bornes sont
 * gardées (422), l'écriture est management (403 au Membre), et NULL revient au
 * défaut de famille (le serveur sert le défaut résolu, le front ne le re-dérive pas).
 */
#[Group('integration')]
final class SportCategoryMatchDurationApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testOutOfRangeDurationsAreRejected(): void
    {
        [$club, $user] = $this->createClubUser('a');
        $sport = $this->sport();
        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];

        // Match sous la borne basse (30).
        $this->client->request('POST', '/api/sport_categories', [], [], $headers, json_encode([
            'sportId' => $sport->getId(), 'name' => 'U13', 'matchMinutes' => 20,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        // Match au-dessus de la borne haute (240).
        $this->client->request('POST', '/api/sport_categories', [], [], $headers, json_encode([
            'sportId' => $sport->getId(), 'name' => 'U13', 'matchMinutes' => 250,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        // Échauffement au-dessus de la borne haute (120).
        $this->client->request('POST', '/api/sport_categories', [], [], $headers, json_encode([
            'sportId' => $sport->getId(), 'name' => 'U13', 'warmupMinutes' => 130,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testAMemberCannotWriteDurations(): void
    {
        [$club, $user] = $this->createClubUser('b');
        $sport = $this->sport();
        $member = $this->createMember($club, 'editor');

        $this->client->request('POST', '/api/sport_categories', [], [], $this->authHeaders($member) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'sportId' => $sport->getId(), 'name' => 'U13', 'matchMinutes' => 90,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);
    }

    public function testNullReturnsToTheFamilyDefault(): void
    {
        [$club, $user] = $this->createClubUser('c');
        $sport = $this->sport();
        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];

        // Création avec des durées propres, sur une catégorie de la famille U13–U15.
        $this->client->request('POST', '/api/sport_categories', [], [], $headers, json_encode([
            'sportId' => $sport->getId(), 'name' => 'U13', 'matchMinutes' => 88, 'warmupMinutes' => 20,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $created = $this->responseData();
        self::assertSame(88, $created['matchMinutes']);
        self::assertSame(20, $created['warmupMinutes']);
        // Le défaut de famille résolu par le serveur (90/30 pour U13) accompagne toujours la lecture.
        self::assertSame(90, $created['defaultMatchMinutes']);
        self::assertSame(30, $created['defaultWarmupMinutes']);

        // PUT avec NULL : « Revenir au défaut » — les colonnes se vident, le défaut de famille reste servi.
        $this->client->request('PUT', '/api/sport_categories/' . $created['id'], [], [], $headers, json_encode([
            'sportId' => $sport->getId(), 'name' => 'U13', 'matchMinutes' => null, 'warmupMinutes' => null,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $reset = $this->responseData();
        self::assertNull($reset['matchMinutes']);
        self::assertNull($reset['warmupMinutes']);
        self::assertSame(90, $reset['defaultMatchMinutes']);
        self::assertSame(30, $reset['defaultWarmupMinutes']);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function sport(): Sport
    {
        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (null === $sport) {
            $uid = uniqid('', true);
            $sport = new Sport;
            $sport->setName('Basket ' . $uid);
            $sport->setSlug('basket-' . $uid);
            $sport->setIsActive(true);
            $this->em->persist($sport);
            $this->em->flush();
        }

        return $sport;
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
        $club->setName('Club cat ' . $suffix);
        $club->setSlug('club-cat-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('cat' . $uid . '@test.com');
        $user->setFirstName('Cat');
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
