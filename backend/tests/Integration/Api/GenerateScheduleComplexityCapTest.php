<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\GenerationComplexityGuard;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A10: an over-complex club/season is rejected (422) BEFORE the generation is queued,
 * so a "generation bomb" never dispatches nor holds the club's single generation slot.
 * The guard returns before any status change or message dispatch.
 */
#[Group('phase1')]
#[Group('integration')]
final class GenerateScheduleComplexityCapTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use CreatesPeopleAndPlacesTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    public function testGenerateRejectedWhenVenuesCapExceeded(): void
    {
        [$user, $club, $season] = $this->seed('CAP1');
        // One venue over the cap (cheapest cap to trip: venues need no FK beyond club/season).
        for ($i = 0; $i <= GenerationComplexityGuard::MAX_VENUES; ++$i) {
            $venue = $this->newVenue($club->getId(), $season->getId(), 'Gym ' . $i)->setIsActive(true);
            $this->em->persist($venue);
        }
        $this->em->flush();
        $schedule = $this->createSchedule($season, ScheduleStatus::DRAFT);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/generate");

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('venues', $body['cap']);
        self::assertSame(GenerationComplexityGuard::MAX_VENUES + 1, $body['count']);

        // Bomb never queued: the controller returned before setStatus(PENDING) + the
        // onboarding-completion flag + flush + dispatch (all sequential), so BOTH the
        // schedule status AND the club's onboarding flag are untouched.
        $this->em->clear();
        $reloaded = $this->em->getRepository(Schedule::class)->find($schedule->getId());
        self::assertSame(ScheduleStatus::DRAFT, $reloaded?->getStatus());
        $reloadedClub = $this->em->getRepository(Club::class)->find($club->getId());
        self::assertFalse($reloadedClub?->getOnboardingCompleted(), 'no dispatch: onboarding flag stayed false');
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
    private function seed(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        // false so the reject-path assertion can prove the controller returned BEFORE the
        // setStatus(PENDING) + onboarding-completion + flush + dispatch block (all sequential).
        $club->setOnboardingCompleted(false);
        $club->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('user-' . $tag . '-' . $uid . '@test.com');
        $user->setFirstName('C');
        $user->setLastName('Ap');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $user->setEmailVerifiedAt(new DateTimeImmutable);
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

    private function createSchedule(Season $season, ScheduleStatus $status): Schedule
    {
        $schedule = new Schedule;
        $schedule->setClubId($season->getClubId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Plan');
        $schedule->setStatus($status);
        // Prod links every version at creation ; sans ça, depuis C4 le site « socle ? »
        // du /generate lèverait AVANT d'atteindre la garde de complexité.
        // linkSeededSchedule resolves the plan, sets schedulePlanId, persists and numbers
        // the schedule itself — it MUST receive a not-yet-persisted Schedule (schedule_plan_id
        // is NOT NULL since ADR-0002 lot D, so it cannot be flushed before its plan is set).
        $this->linkSeededSchedule($schedule);

        return $schedule;
    }
}
