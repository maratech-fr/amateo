<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\PriorityTier;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P4-269 — le radar « une personne à deux endroits » sur le planning d'entraînement
 * EN VIGUEUR, servi en lecture. Réservé aux gestionnaires (SEC-07) et strictement
 * scopé au club appelant (§7.1 tenant : un club ne voit jamais les conflits d'un autre).
 */
#[Group('integration')]
final class TrainingPlacedConflictsApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testManagerSeesTheLivePersonConflict(): void
    {
        [, $userA, $ctxA] = $this->createClubWithConflict('a');

        $this->client->request('GET', '/api/training/placed-conflicts', [], [], $this->authHeaders($userA));
        self::assertResponseStatusCodeSame(200);

        $data = $this->responseData();
        self::assertTrue($data['seasonPlanChosen']);
        self::assertCount(1, $data['conflicts']);
        self::assertSame($ctxA['coach'], $data['conflicts'][0]['personId']);
        self::assertSame(2, $data['conflicts'][0]['dayOfWeek']);
        self::assertArrayHasKey('first', $data['conflicts'][0]);
        self::assertArrayHasKey('second', $data['conflicts'][0]);
    }

    public function testANonManagerIsRefused(): void
    {
        [$clubA] = $this->createClubWithConflict('perm');
        $editor = $this->createMember($clubA, 'editor');

        $this->client->request('GET', '/api/training/placed-conflicts', [], [], $this->authHeaders($editor));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnotherClubsConflictNeverLeaks(): void
    {
        // Club A a un conflit ; club B n'a rien. B ne voit jamais celui de A.
        $this->createClubWithConflict('leaka');
        [, $userB] = $this->createClubWithConflict('leakb', withConflict: false);

        $this->client->request('GET', '/api/training/placed-conflicts', [], [], $this->authHeaders($userB));
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->responseData()['conflicts']);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * A club whose in-effect season plan places two overlapping sessions in two gyms,
     * both coached by the same person → exactly one person conflict (unless withConflict
     * is false, in which case no session is placed).
     *
     * @return array{0: Club, 1: User, 2: array{coach: string}}
     */
    private function createClubWithConflict(string $suffix, bool $withConflict = true): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club ' . $suffix);
        $club->setSlug('tpc-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('tpc' . $uid . '@test.com');
        $user->setFirstName('Tpc');
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

        $schedule = $this->settleSeasonPlan($season);

        $coachId = $this->buildConflictData($club->getId(), $season->getId(), $schedule->getId(), $withConflict);

        return [$club, $user, ['coach' => $coachId]];
    }

    private function buildConflictData(string $clubId, string $seasonId, string $scheduleId, bool $withConflict): string
    {
        $sport = (new Sport)->setName('Basketball')->setSlug('bball-' . uniqid('', true))->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();
        $category = (new SportCategory)->setClubId($clubId)->setSportId($sport->getId())->setName('U11')->setIsCustom(false)->setSortOrder(0);
        $this->em->persist($category);
        $this->em->flush();
        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (!$tier instanceof PriorityTier) {
            $tier = (new PriorityTier)->setId(1)->setLabel('S')->setName('Senior')->setColor('#FF0000')->setOrToolsWeight(100)->setDefaultMinSessions(2);
            $this->em->persist($tier);
            $this->em->flush();
        }

        $teamIds = [];
        foreach (['U13F', 'U11M1'] as $name) {
            $team = (new Team)->setClubId($clubId)->setSeasonId($seasonId)->setSportCategoryId($category->getId())->setPriorityTierId($tier->getId())->setName($name)->setSessionsPerWeek(2);
            $this->em->persist($team);
            $this->em->flush();
            $teamIds[] = $team->getId();
        }
        $gymIds = [];
        foreach (['Gymnase A', 'Gymnase B'] as $name) {
            $venue = (new Venue)->setClubId($clubId)->setSeasonId($seasonId)->setName($name)->setSource('manual');
            $this->em->persist($venue);
            $this->em->flush();
            $gymIds[] = $venue->getId();
        }
        $coach = (new Coach)->setClubId($clubId)->setSeasonId($seasonId)->setFirstName('Anna')->setLastName('Dupont');
        $this->em->persist($coach);
        $this->em->flush();

        foreach ($teamIds as $teamId) {
            $link = (new TeamCoach)->setClubId($clubId)->setSeasonId($seasonId)->setTeamId($teamId)->setCoachId($coach->getId())->setRole(TeamCoachRole::MAIN);
            $this->em->persist($link);
        }
        $this->em->flush();

        if ($withConflict) {
            foreach ([[$teamIds[0], $gymIds[0]], [$teamIds[1], $gymIds[1]]] as [$teamId, $venueId]) {
                $slot = (new ScheduleSlotTemplate)
                    ->setClubId($clubId)
                    ->setSeasonId($seasonId)
                    ->setScheduleId($scheduleId)
                    ->setTeamId($teamId)
                    ->setVenueId($venueId)
                    ->setDayOfWeek(2)
                    ->setStartTime(new DateTimeImmutable('18:00'))
                    ->setDurationMinutes(90);
                $this->em->persist($slot);
            }
            $this->em->flush();
        }

        return $coach->getId();
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
