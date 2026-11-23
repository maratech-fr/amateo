<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Enum\SeasonStatus;
use App\Plans\PlanComposition;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression: a real Bearer JWT (not loginUser, which pre-injects the token and
 * hides listener ordering) must resolve the caller's club from the token BEFORE
 * collection reads run — otherwise the tenant is unresolved, RLS is not applied,
 * and another club's data leaks. The tenant listener therefore runs AFTER the
 * security firewall.
 */
#[Group('phase1')]
#[Group('integration')]
final class TenantJwtIsolationTest extends WebTestCase
{
    use StartsFreshBrowserSession;

    use TenantGucTrait;
    use VerifiesRegistration;

    private static int $ip = 0;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    public function testFreshUserSeesOnlyOwnClubViaJwt(): void
    {
        // Club A owns a team (seeded directly).
        $clubA = $this->seedClubWithTeam();

        // Club B registers via the API and gets a real JWT.
        $token = $this->register('BJWT' . uniqid());
        self::assertNotSame('', $token);

        // GET /api/teams with the JWT only (no X-Club-Id) → club B is empty.
        $this->client->request('GET', '/api/teams', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('member', $data);
        self::assertCount(0, $data['member'], 'a fresh club must not see club A\'s team');
        self::assertNotSame($clubA->getId(), '');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function register(string $ara): string
    {
        // SEC-16 : le cookie d’auth de l’identité précédente ne doit pas partir
        // avec cette inscription (sinon 429 du quota par utilisateur).
        $this->startFreshBrowserSession($this->client);
        $ip = '10.9.' . intdiv(self::$ip, 254) . '.' . (self::$ip % 254 + 1);
        ++self::$ip;
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => strtolower($ara) . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'B', 'lastName' => 'Jwt', 'ara' => strtoupper($ara), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        return $this->verifyRegistration($this->client, strtolower($ara) . '@test.fr');
    }

    private function seedClubWithTeam(): Club
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club A');
        $club->setSlug('club-a-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('AAA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $this->scopeGucToClub($club->getId());

        $sport = new Sport;
        $sport->setName('Basketball');
        $sport->setSlug('bball-' . $uid);
        $sport->setIsActive(true);
        $this->em->persist($sport);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);

        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U11');
        $category->setIsCustom(false);
        $category->setSortOrder(0);
        $this->em->persist($category);

        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (!$tier instanceof PriorityTier) {
            $tier = new PriorityTier;
            $tier->setId(1);
            $tier->setLabel('S');
            $tier->setName('Senior');
            $tier->setColor('#FF0000');
            $tier->setOrToolsWeight(100);
            $tier->setDefaultMinSessions(2);
            $this->em->persist($tier);
        }
        $this->em->flush();

        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId($tier->getId());
        $team->setName('Club A Team');
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();
        self::getContainer()->get(PlanComposition::class)->ensureLines($team->getSeasonId(), [$team->getId() => ['sessionsPerWeek' => 2]]);
        $this->em->flush();

        return $club;
    }
}
