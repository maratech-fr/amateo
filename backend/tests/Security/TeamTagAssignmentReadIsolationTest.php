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
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Abus (isolation entre clubs) — les rattachements d'étiquettes que l'écran lit
 * (`GET /api/team_tag_assignments`) sont calculés depuis les équipes : l'identifiant d'une
 * étiquette (« U13 ») est le même pour tous les clubs, mais la liste d'un club ne contient
 * jamais l'équipe d'un autre, ni par sa saison courante, ni en demandant la saison d'un autre
 * club. Il n'y a pas de route d'un rattachement seul (404).
 *
 * Jumeau plus bas : `TeamTagScopeTest::testTagsNeverCarryAnotherClubsTeam` (le résolveur, le
 * mémo d'un processus qui sert deux clubs). Ici : le chemin HTTP, filtre de tenant et RLS compris.
 */
#[Group('integration')]
final class TeamTagAssignmentReadIsolationTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testAClubNeverReadsAnotherClubsTeamInItsTagAssignments(): void
    {
        [, , $user, $ownTeam] = $this->clubWithU13Team('own');
        [, $otherSeason, , $otherTeam] = $this->clubWithU13Team('other');

        [$status, $body] = $this->get($user, '/api/team_tag_assignments');
        self::assertSame(200, $status, $body);
        self::assertStringContainsString($ownTeam->getId() . ':U13', $body, 'témoin : l\'équipe du club porte U13');
        self::assertStringNotContainsString($otherTeam->getId(), $body, 'jamais l\'équipe d\'un autre club');

        [$status, $body] = $this->get($user, '/api/team_tag_assignments', $otherSeason->getId());
        self::assertSame(403, $status, 'la saison d\'un autre club est refusée : ' . $body);
        self::assertStringNotContainsString($otherTeam->getId(), $body, 'le refus ne laisse rien passer de l\'autre club');

        [$status] = $this->get($user, '/api/team_tag_assignments/' . $otherTeam->getId() . ':U13');
        self::assertSame(404, $status, 'pas de route d\'un rattachement seul');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{0: int, 1: string} */
    private function get(User $user, string $path, ?string $seasonId = null): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/ld+json'];
        if (null !== $seasonId) {
            $server['HTTP_X_SEASON_ID'] = $seasonId;
        }
        $this->client->request('GET', $path, [], [], $server);

        return [$this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent()];
    }

    /** @return array{0: Club, 1: Season, 2: User, 3: Team} */
    private function clubWithU13Team(string $suffix): array
    {
        $uid = uniqid($suffix, true);
        $club = new Club;
        $club->setName('Club ' . $suffix);
        $club->setSlug('club-tag-read-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);
        $user = new User;
        $user->setEmail('tag-read-' . $uid . '@test.com');
        $user->setFirstName('Tag');
        $user->setLastName('Lecture');
        $user->setPasswordHash(self::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $sport = new Sport;
        $sport->setName('Basketball');
        $sport->setSlug('bball-tag-read-' . $uid);
        $sport->setIsActive(true);
        $this->em->persist($sport);
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
        if (!$this->em->getRepository(PriorityTier::class)->find(1) instanceof PriorityTier) {
            $tier = new PriorityTier;
            $tier->setId(1);
            $tier->setLabel('S');
            $tier->setName('Senior');
            $tier->setColor('#FF0000');
            $tier->setOrToolsWeight(100);
            $tier->setDefaultMinSessions(2);
            $this->em->persist($tier);
        }
        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U13');
        $category->setAgeMin(12);
        $category->setAgeMax(13);
        $category->setIsCustom(false);
        $category->setSortOrder(0);
        $this->em->persist($category);
        $this->em->flush();
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(1);
        $team->setName('U13 ' . $suffix);
        $team->setSessionsPerWeek(1);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return [$club, $season, $user, $team];
    }
}
