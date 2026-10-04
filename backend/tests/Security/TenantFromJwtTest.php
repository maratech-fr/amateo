<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * BL2 — the tenant is derived from the authenticated user's single active
 * membership when no X-Club-Id header is sent. No header, no leak.
 */
#[Group('phase1')]
#[Group('integration')]
final class TenantFromJwtTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    public function testClubResolvedFromJwtWithoutHeader(): void
    {
        [$user, $club, $season] = $this->seedClub('JWT1');
        $team = $this->createTeam($club, $season, 'Own Team');

        // A foreign club with its own team — the user is NOT a member of it.
        [, $otherClub, $otherSeason] = $this->seedClub('JWT2');
        $this->createTeam($otherClub, $otherSeason, 'Foreign Team');

        $this->client->loginUser($user);
        $this->client->request('GET', '/api/teams'); // no X-Club-Id header

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('member', $data);
        self::assertCount(1, $data['member']);
        self::assertSame($team->getId(), $data['member'][0]['id']);
    }

    /**
     * NR AUD-SEC-25. L'en-tête `X-Club-Id` d'un club étranger n'est plus lu : le
     * tenant reste celui de l'adhésion du JWT. Hier un 403 ; aujourd'hui une
     * assertion sur les DONNÉES — le membre du club JWT3 ne voit que son équipe,
     * jamais celle de JWT4 porté dans l'en-tête.
     */
    public function testForeignClubHeaderIsIgnoredAndOnlyOwnDataIsReturned(): void
    {
        [$user, $club, $season] = $this->seedClub('JWT3');
        $team = $this->createTeam($club, $season, 'Own Team');
        [, $otherClub, $otherSeason] = $this->seedClub('JWT4');
        $this->createTeam($otherClub, $otherSeason, 'Foreign Team');

        $this->client->loginUser($user);
        $this->client->request('GET', '/api/teams', [], [], ['HTTP_X-Club-Id' => $otherClub->getId()]);

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data['member']);
        self::assertSame($team->getId(), $data['member'][0]['id'], 'l’en-tête du club étranger est ignoré : seules les données du club du JWT remontent');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get('security.user_password_hasher');
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function seedClub(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('user-' . $tag . '-' . $uid . '@test.com');
        $user->setFirstName('J');
        $user->setLastName('WT');
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

    private function createTeam(Club $club, Season $season, string $name): Team
    {
        $category = new SportCategory;
        $sport = new Sport;
        $sport->setName('Basketball');
        $sport->setSlug('bball-' . uniqid('', true));
        $sport->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();

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
        $team->setName($name);
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }
}
