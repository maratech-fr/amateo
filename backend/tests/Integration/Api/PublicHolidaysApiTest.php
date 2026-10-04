<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\PublicHoliday;
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
final class PublicHolidaysApiTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    public function testReturnsNationalUnionZoneWithinWindow(): void
    {
        [$user, $club] = $this->seed('PH1', 'GUADELOUPE');
        $this->seedHoliday(PublicHoliday::NATIONAL, '2025-11-11', '11 novembre');   // national, in window
        $this->seedHoliday('GUADELOUPE', '2026-03-15', 'Fériés Guadeloupe');        // club zone, in window
        $this->seedHoliday('MARTINIQUE', '2026-04-20', 'Fériés Martinique');        // other zone → excluded
        $this->seedHoliday(PublicHoliday::NATIONAL, '2027-01-01', '1er janvier');   // out of window → excluded
        $this->em->flush();

        $this->client->request('GET', '/api/public-holidays', [], [], $this->authHeaders($user, $club));
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame('GUADELOUPE', $data['zone']);
        $dates = array_map(static fn (array $i): string => $i['date'], $data['items']);
        self::assertContains('2025-11-11', $dates);
        self::assertContains('2026-03-15', $dates);
        self::assertNotContains('2026-04-20', $dates, 'another zone must be excluded');
        self::assertNotContains('2027-01-01', $dates, 'out-of-window must be excluded');

        $byDate = [];
        foreach ($data['items'] as $item) {
            $byDate[$item['date']] = $item['national'];
        }
        self::assertTrue($byDate['2025-11-11']);
        self::assertFalse($byDate['2026-03-15']);
    }

    public function testNullZoneStillGetsNational(): void
    {
        [$user, $club] = $this->seed('PH2', null);
        $this->seedHoliday(PublicHoliday::NATIONAL, '2025-11-11', '11 novembre');
        $this->seedHoliday('GUADELOUPE', '2026-03-15', 'Fériés Guadeloupe');
        $this->em->flush();

        $this->client->request('GET', '/api/public-holidays', [], [], $this->authHeaders($user, $club));
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertNull($data['zone']);
        $dates = array_map(static fn (array $i): string => $i['date'], $data['items']);
        self::assertContains('2025-11-11', $dates, 'national fériés apply to every club');
        self::assertNotContains('2026-03-15', $dates, 'no zone → no territory-specific fériés');
    }

    public function testMalformedDateWindowReturns400(): void
    {
        [$user, $club] = $this->seed('PH3', 'GUADELOUPE');
        $this->em->flush();

        $this->client->request('GET', '/api/public-holidays?from=2026-13-01', [], [], $this->authHeaders($user, $club));
        self::assertResponseStatusCodeSame(400);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get('security.user_password_hasher');
        $this->jwt = $container->get(JWTTokenManagerInterface::class);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user, Club $club): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
        ];
    }

    private function seedHoliday(string $zone, string $date, string $label): void
    {
        $h = new PublicHoliday;
        $h->setZone($zone);
        $h->setDate(new DateTimeImmutable($date));
        $h->setLabel($label);
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
        $user->setFirstName('P');
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
