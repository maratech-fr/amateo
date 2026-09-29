<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\SeasonStatus;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P4-271 — le PUT d'un créneau idéal PEUT RETIRER son gymnase (idiome full-replace) : `venueId`
 * `null` passe le créneau à « aucun gymnase », pas seulement le changer. Sans ce chemin, un
 * gestionnaire ne pouvait que remplacer le gymnase, jamais le vider — il fallait supprimer +
 * recréer. On prouve de bout en bout que le PUT à `venueId: null` ressort un `venueId` null.
 *
 * Non-bloquant (groupe `integration`, tourne dans `unit-tests`).
 */
#[Group('integration')]
final class TeamMatchHabitVenueClearApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $token;

    public function testPutWithNullVenueClearsTheVenue(): void
    {
        $team = $this->team();
        $venue = $this->venue();

        // POST : un créneau idéal AVEC gymnase.
        $created = $this->requestJson('POST', '/api/team_match_habits', ['teamId' => $team, 'dayOfWeek' => 6, 'kickoffTime' => '15:30', 'venueId' => $venue, 'week' => 'A']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($venue, $created['venueId'] ?? null, 'le gymnase est bien posé à la création');
        $id = $created['id'];

        // PUT : le même créneau SANS gymnase (venueId null) → le gymnase est RETIRÉ.
        $updated = $this->requestJson('PUT', '/api/team_match_habits/' . $id, ['teamId' => $team, 'dayOfWeek' => 6, 'kickoffTime' => '15:30', 'venueId' => null, 'week' => 'A']);
        self::assertResponseStatusCodeSame(200);
        self::assertNull($updated['venueId'], 'le PUT à venueId null RETIRE le gymnase du créneau idéal');
        self::assertSame('A', $updated['week'] ?? null, 'les autres champs restent posés');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $passwordHasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);
        $this->club = (new Club)->setName('TMH Club')->setSlug('tmh-' . $uid)->setTimezone('Europe/Paris')
            ->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        $user = (new User)->setEmail('tmh-' . $uid . '@test.com')->setFirstName('T')->setLastName('H');
        $user->setPasswordHash($passwordHasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));

        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($this->season);
        $this->em->flush();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $uri, array $payload): array
    {
        $this->client->request($method, $uri, [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode($payload, \JSON_THROW_ON_ERROR));

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function team(): string
    {
        $team = (new Team)
            ->setClubId($this->club->getId())
            ->setSeasonId($this->season->getId())
            ->setSportCategoryId($this->uuid())
            ->setPriorityTierId(3)
            ->setName('T' . substr($this->uuid(), 0, 6))
            ->setSessionsPerWeek(2)
            ->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return (string) $team->getId();
    }

    private function venue(): string
    {
        $venue = (new Venue)
            ->setClubId($this->club->getId())
            ->setSeasonId($this->season->getId())
            ->setName('V' . substr($this->uuid(), 0, 6))
            ->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return (string) $venue->getId();
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
