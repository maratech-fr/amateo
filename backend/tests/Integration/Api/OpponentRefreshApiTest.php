<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentLocationPrecision;
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
 * P2-54 PR-2b — l'orchestrateur `POST /api/opponents/refresh` : management-gated
 * (403 souverain), cap dur AVANT réseau (422), limiteur par utilisateur (429), et une
 * réponse à TROIS blocs (`codes`/`autoLocated`/`travel`) toujours bien formée — chaque
 * passe est INDÉPENDANTE, aucune n'annule les autres.
 */
#[Group('integration')]
final class OpponentRefreshApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testANonManagementMemberIsForbidden(): void
    {
        [$club, , $season] = $this->seedClub();
        $this->awayFixture($club, $season, 'ARA0069R01', 'Adversaire');
        $member = $this->addMember($club, 'member');

        $this->post($member, '/api/opponents/refresh');
        self::assertResponseStatusCodeSame(403, 'assertManager() d\'abord — un simple membre est refusé');
    }

    public function testOverTheCapIsRefusedBeforeAnyNetworkCall(): void
    {
        [$club, $user, $season] = $this->seedClub();
        for ($i = 1; $i <= 201; ++$i) {
            $this->awayFixtureNoCode($club, $season, \sprintf('Adversaire distinct %03d', $i));
        }

        $this->post($user, '/api/opponents/refresh');
        self::assertResponseStatusCodeSame(422);
        $error = (string) ($this->responseData()['error'] ?? '');
        self::assertStringContainsString('201', $error);
        self::assertStringContainsString('maximum 200', $error);
    }

    public function testTheResponseHasTheThreeIndependentPasses(): void
    {
        [$club, $user, $season] = $this->seedClub();
        $this->awayFixture($club, $season, 'ARA0069R02', 'Adversaire localisable');
        $this->directory('ARA0069R02', OpponentLocationPrecision::CITY, 'Villeurbanne', '69100');

        $this->post($user, '/api/opponents/refresh');
        self::assertResponseStatusCodeSame(200);

        $data = $this->responseData();
        // Chaque passe a produit sa forme — aucune n'a avorté les autres (best-effort).
        self::assertSame(['codes', 'autoLocated', 'travel'], array_keys($data));
        self::assertSame(['resolved', 'unresolved', 'skipped', 'stamped'], array_keys((array) $data['codes']));
        self::assertSame(['located', 'ambiguous', 'unmatched', 'skipped'], array_keys((array) $data['autoLocated']));
        self::assertSame(['resolved', 'unresolved', 'skippedManual'], array_keys((array) $data['travel']));
    }

    public function testTheLimiterTripsAtElevenCallsForOneUser(): void
    {
        [$club, $user, $season] = $this->seedClub();
        $this->awayFixture($club, $season, 'ARA0069R03', 'Adversaire');

        // 10/h par utilisateur, NON assoupli en test à dessein : le 11ᵉ appel du MÊME
        // utilisateur est refusé (429). L'utilisateur est frais → aucune pollution d'un
        // autre test (pas de FLUSHALL).
        for ($i = 0; $i < 10; ++$i) {
            $this->post($user, '/api/opponents/refresh');
            self::assertResponseStatusCodeSame(200, "l'appel {$i} reste sous la borne");
        }
        $this->post($user, '/api/opponents/refresh');
        self::assertResponseStatusCodeSame(429, 'le 11ᵉ appel dépasse la borne');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function post(User $user, string $url): void
    {
        $this->client->request('POST', $url, [], [], $this->authHeaders($user) + ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json']);
    }

    private function addMember(Club $club, string $role): User
    {
        $uid = uniqid('mbr', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $member = new User;
        $member->setEmail($uid . '@test.com');
        $member->setFirstName('Me');
        $member->setLastName('Mbre');
        $member->setPasswordHash($hasher->hashPassword($member, 'pass'));
        $this->em->persist($member);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($member->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $member;
    }

    /**
     * @return array{0: Club, 1: User, 2: Season}
     */
    private function seedClub(): array
    {
        $uid = uniqid('refresh', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club refresh ' . $uid);
        $club->setSlug('club-refresh-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setLatitude(45.70);
        $club->setLongitude(4.90);
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail($uid . '@test.com');
        $user->setFirstName('R');
        $user->setLastName('Efresh');
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

    private function awayFixture(Club $club, Season $season, string $code, string $opponentLabel): void
    {
        $this->scopeGucToClub($club->getId());
        $fixture = $this->baseFixture($club, $season, $opponentLabel);
        $fixture->setOpponentOrganismeCode($code);
        $this->em->persist($fixture);
        $this->em->flush();
    }

    private function awayFixtureNoCode(Club $club, Season $season, string $opponentLabel): void
    {
        $this->scopeGucToClub($club->getId());
        $this->em->persist($this->baseFixture($club, $season, $opponentLabel));
        $this->em->flush();
    }

    private function baseFixture(Club $club, Season $season, string $opponentLabel): Fixture
    {
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
        $fixture->setMatchDate(new DateTimeImmutable('+10 days'));
        $fixture->setHomeAway(FixtureHomeAway::AWAY);
        $fixture->setOpponentLabel($opponentLabel);

        return $fixture;
    }

    private function directory(string $code, OpponentLocationPrecision $precision, ?string $city, ?string $postalCode): void
    {
        $entry = new OpponentDirectoryEntry($code, $city ?? 'Adversaire', $precision);
        $entry->setCity($city)->setPostalCode($postalCode)->setLatitude(45.7)->setLongitude(4.85);
        $this->em->persist($entry);
        $this->em->flush();
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
