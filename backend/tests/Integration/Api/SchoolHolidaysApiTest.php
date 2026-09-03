<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\SchoolHolidayPeriod;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Group('phase1')]
#[Group('integration')]
final class SchoolHolidaysApiTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    public function testReturnsHolidaysOfClubZone(): void
    {
        [$user, $club] = $this->seed('SH1', 'A');
        $this->seedHoliday('A', 'toussaint', '2025-10-18', '2025-11-02');
        $this->seedHoliday('B', 'toussaint', '2025-10-18', '2025-11-02');
        $this->em->flush();

        $this->client->request('GET', '/api/school-holidays', [], [], $this->authHeaders($user, $club));
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame('A', $data['zone']);
        self::assertNotEmpty($data['items']);
        foreach ($data['items'] as $item) {
            self::assertSame('toussaint', $item['holidayType']);
        }
    }

    public function testZoneNullReturnsEmptyItems(): void
    {
        [$user, $club] = $this->seed('SH2', null);
        $this->seedHoliday('A', 'toussaint', '2025-10-18', '2025-11-02');
        $this->em->flush();

        $this->client->request('GET', '/api/school-holidays', [], [], $this->authHeaders($user, $club));
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertNull($data['zone']);
        self::assertSame([], $data['items']);
    }

    public function testInvalidSchoolZoneOnClubIsRejected(): void
    {
        [$user, $club] = $this->seed('SH5', 'A');

        // Zone must be A/B/C — an arbitrary string would silently break
        // /api/school-holidays (empty list, no error).
        $this->client->request('PUT', "/api/clubs/{$club->getId()}", [], [], [
            ...$this->authHeaders($user, $club),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => $club->getName(),
            'slug' => $club->getSlug(),
            'timezone' => 'Europe/Paris',
            'locale' => 'fr',
            'schoolZone' => 'Z',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422, 'an invalid school zone must be rejected');
    }

    public function testWindowExcludesOutOfSeasonHolidays(): void
    {
        [$user, $club] = $this->seed('SH3', 'A');
        // In season (2025-09-01 → 2026-06-30).
        $this->seedHoliday('A', 'toussaint', '2025-10-18', '2025-11-02');
        // Way outside the season.
        $this->seedHoliday('A', 'ete', '2027-07-03', '2027-08-31');
        $this->em->flush();

        $this->client->request('GET', '/api/school-holidays', [], [], $this->authHeaders($user, $club));
        self::assertResponseIsSuccessful();
        $types = array_map(static fn (array $i): string => $i['holidayType'], json_decode((string) $this->client->getResponse()->getContent(), true)['items']);
        self::assertContains('toussaint', $types);
        self::assertNotContains('ete', $types);
    }

    public function testMalformedDateWindowReturns400(): void
    {
        [$user, $club] = $this->seed('SH4', 'A');
        $this->em->flush();

        // Non-existent calendar date passes a naive regex but must be rejected.
        $this->client->request('GET', '/api/school-holidays?from=2026-13-01', [], [], $this->authHeaders($user, $club));
        self::assertResponseStatusCodeSame(400);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get('security.user_password_hasher');
        $this->jwt = $container->get(JWTTokenManagerInterface::class);

        // These tests assert on the FULL holiday set returned for a zone (e.g.
        // "every item is toussaint") and seed exactly what they need, so they
        // require an isolated table. Any pre-existing rows — the shipped JSON seed
        // (app:school-holidays:seed) or a leftover from
        // another run — would both duplicate the natural key (unique violation) and
        // pollute the assertions. `school_holiday_period` is global (no club_id, no
        // RLS), so a plain DELETE clears it deterministically before each test.
        $this->em->getConnection()->executeStatement('DELETE FROM school_holiday_period');
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user, Club $club): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
            'HTTP_X-Club-Id' => $club->getId(),
        ];
    }

    private function seedHoliday(string $zone, string $type, string $start, string $end): void
    {
        $h = new SchoolHolidayPeriod;
        $h->setZone($zone);
        $h->setHolidayType($type);
        $h->setSchoolYear('2025-2026');
        $h->setLabel('Test ' . $type);
        $h->setStartDate(new DateTimeImmutable($start));
        $h->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($h);
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function seed(string $tag, ?string $zone): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $club->setSchoolZone($zone);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('user-' . $tag . '-' . $uid . '@test.com');
        $user->setFirstName('S');
        $user->setLastName('H');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);

        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);

        $this->em->flush();

        return [$user, $club, $season];
    }
}
