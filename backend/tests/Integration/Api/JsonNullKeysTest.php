<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
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

/**
 * NR — les clés à valeur null restent PRÉSENTES dans les réponses
 * `Accept: application/json` (skip_null_values: false, api_platform.yaml).
 *
 * Le frontend compare en strict (`null === x`) : une clé omise arrive `undefined`
 * et casse la lecture — `chosenScheduleId` null lu « validé » (activeByEntry),
 * `parentEntryId` null → entrée mère plus reconnue comme racine (roots/radar).
 * Bug constaté en live le 2026-07-23 (données BCCL, Toussaint découpée).
 */
#[Group('phase1')]
#[Group('integration')]
final class JsonNullKeysTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    public function testCalendarEntryKeepsNullParentEntryIdInJson(): void
    {
        [$user, $club] = $this->seed('JNK1');

        // Une closure RACINE : parentEntryId est null — la clé doit rester présente.
        $this->post($user, $club, [
            'kind' => 'period',
            'title' => 'Fermeture racine',
            'startDate' => '2026-05-04',
            'endDate' => '2026-05-10',
            'periodType' => 'closure',
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/calendar_entries?kind=period', [], [], [
            ...$this->authHeaders($user, $club),
            'HTTP_ACCEPT' => 'application/json',
        ]);
        self::assertResponseIsSuccessful();
        $items = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($items);
        self::assertNotSame([], $items, 'expected at least the seeded entry');
        $entry = $items[0];
        self::assertArrayHasKey('parentEntryId', $entry, 'null parentEntryId must not be omitted from application/json');
        self::assertNull($entry['parentEntryId']);
        self::assertArrayHasKey('schoolHolidayId', $entry, 'null schoolHolidayId must not be omitted from application/json');
        self::assertNull($entry['schoolHolidayId']);
    }

    public function testSchedulePlanKeepsNullChosenScheduleIdInJson(): void
    {
        [$user, $club] = $this->seed('JNK2');

        // Le plan naît du geste Adapter (ADR-0002 amendé 2026-07-24) — on le crée
        // explicitement ; jamais validé ici : chosenScheduleId est null et la clé
        // doit rester présente.
        $this->post($user, $club, [
            'kind' => 'period',
            'title' => 'Fermeture non validée',
            'startDate' => '2026-05-04',
            'endDate' => '2026-05-10',
            'periodType' => 'closure',
        ]);
        self::assertResponseStatusCodeSame(201);
        $entryId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];
        $this->client->request('POST', '/api/schedule_plans', [], [], [
            ...$this->authHeaders($user, $club),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode(['calendarEntryId' => $entryId], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/schedule_plans', [], [], [
            ...$this->authHeaders($user, $club),
            'HTTP_ACCEPT' => 'application/json',
        ]);
        self::assertResponseIsSuccessful();
        $items = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($items);
        self::assertNotSame([], $items, 'expected the closure period plan');
        $plan = $items[0];
        self::assertArrayHasKey('chosenScheduleId', $plan, 'null chosenScheduleId must not be omitted from application/json');
        self::assertNull($plan['chosenScheduleId']);
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

    /**
     * @param array<string, mixed> $payload
     */
    private function post(User $user, Club $club, array $payload): void
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], [
            ...$this->authHeaders($user, $club),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{0: User, 1: Club}
     */
    private function seed(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-' . strtolower($tag) . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('user-' . strtolower($tag) . '-' . $uid . '@test.com');
        $user->setFirstName('J');
        $user->setLastName('N');
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

        return [$user, $club];
    }
}
