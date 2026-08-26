<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
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
 * P2-53 RMM-8 (PR-1) — le coach gagne `isVehicled` : défaut false (prudent), écriture
 * lecture round-trip par l'API.
 */
#[Group('integration')]
final class CoachVehicledApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testDefaultsToFalseAndIsWritable(): void
    {
        [, $user] = $this->createClubUser('a');
        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];

        // Par défaut : pas véhiculé (prudent).
        $this->client->request('POST', '/api/coaches', [], [], $headers, json_encode(['firstName' => 'Sans'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        self::assertFalse($this->responseData()['isVehicled'], 'un coach naît non véhiculé');

        // Écriture explicite à true, relue.
        $this->client->request('POST', '/api/coaches', [], [], $headers, json_encode(['firstName' => 'Avec', 'isVehicled' => true], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $data = $this->responseData();
        self::assertTrue($data['isVehicled'], 'isVehicled true est persisté');

        $coach = $this->em->getRepository(Coach::class)->find($data['id']);
        self::assertNotNull($coach);
        self::assertTrue($coach->isVehicled());
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return array{0: Club, 1: User, 2: Season}
     */
    private function createClubUser(string $suffix): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club coach ' . $suffix);
        $club->setSlug('club-coach-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('coach' . $uid . '@test.com');
        $user->setFirstName('Coach');
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

    /** @return array<string, mixed> */
    private function responseData(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
