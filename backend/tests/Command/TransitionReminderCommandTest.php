<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\TransitionReminderCommand;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Repository\ClubUserRepository;
use App\Repository\TransitionReminderLogRepository;
use App\Service\ClubDay;
use App\Service\SeasonResolver;
use App\Service\TenantConnectionContext;
use App\Service\TransitionReminderMailBuilder;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * The transition reminder cron emails a club's managers when the July-15 pivot
 * approaches and no N+1 season exists, once per milestone (61/30/14-day bucket)
 * via a sent-log — window-bounded, successor-aware, tenant-isolated, and red
 * (exit non-zero) when the mailer is down.
 */
#[Group('phase1')]
#[Group('integration')]
final class TransitionReminderCommandTest extends KernelTestCase
{
    use TenantGucTrait;
    private const IN_BUCKET_61 = '2026-05-20';
    private const IN_BUCKET_30 = '2026-06-20';
    private const IN_BUCKET_14 = '2026-07-05';

    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $sentTo = [];

    /** @var list<string> */
    private array $sentSubjects = [];

    private string $throwForRecipient = '';

    public function testRemindsManagersInEachBucket(): void
    {
        foreach ([self::IN_BUCKET_61, self::IN_BUCKET_30, self::IN_BUCKET_14] as $date) {
            $this->reset();
            [, , $admin] = $this->seedClub('B' . substr(md5($date), 0, 4));

            $this->runCommand($date);

            self::assertContains($admin, $this->sentTo, "a run at {$date} must email the manager");
        }
    }

    public function testLastBucketSubjectIsRed(): void
    {
        $this->seedClub('RED');

        $this->runCommand(self::IN_BUCKET_14);

        self::assertNotEmpty($this->sentSubjects);
        self::assertStringStartsWith('🔴', $this->sentSubjects[0]);
    }

    public function testOutsideTheWindowNothingIsSent(): void
    {
        [, , $admin] = $this->seedClub('OUT');

        // Before May 15 and ON the pivot itself (excluded) → silence.
        $this->runCommand('2026-04-30');
        $this->runCommand('2026-07-15');

        self::assertNotContains($admin, $this->sentTo);
        self::assertSame([], $this->sentTo);
    }

    public function testExistingSuccessorSilencesTheReminder(): void
    {
        [$club, , $admin] = $this->seedClub('SUCC');
        // N+1 prepared → no nudge.
        $this->season($club, new DateTimeImmutable('2026-08-01'));

        $this->runCommand(self::IN_BUCKET_30);

        self::assertNotContains($admin, $this->sentTo);
    }

    public function testDryRunSendsNothing(): void
    {
        $this->seedClub('DRY');

        $tester = $this->runCommand(self::IN_BUCKET_61, dryRun: true);

        self::assertSame([], $this->sentTo);
        self::assertStringContainsString('would remind', $tester->getDisplay());
    }

    public function testIdempotentPerBucketButResendsTheNextOne(): void
    {
        [, , $admin] = $this->seedClub('IDEM');

        $this->runCommand(self::IN_BUCKET_61); // logs bucket 61
        self::assertSame([$admin], $this->sentTo);

        $this->reset();
        $this->runCommand(self::IN_BUCKET_61); // same bucket → silence
        self::assertSame([], $this->sentTo);

        $this->reset();
        $this->runCommand(self::IN_BUCKET_30); // next milestone → reminds again
        self::assertSame([$admin], $this->sentTo);

        $this->reset();
        $this->runCommand(self::IN_BUCKET_14); // last milestone
        self::assertSame([$admin], $this->sentTo);
    }

    public function testOnlyManagementRolesAreEmailed(): void
    {
        [$club, , $admin] = $this->seedClub('ROLE');
        $this->addMember($club, 'editor-trans@club.fr', 'member', true);
        $this->addMember($club, 'inactive-trans@club.fr', 'admin', false);

        $this->runCommand(self::IN_BUCKET_61);

        self::assertSame([$admin], $this->sentTo);
    }

    public function testTenantIsolationBetweenClubs(): void
    {
        [, , $adminA] = $this->seedClub('ISOA');
        [$clubB, , $adminB] = $this->seedClub('ISOB');
        // Club B already prepared N+1 → only A is nudged.
        $this->season($clubB, new DateTimeImmutable('2026-08-01'));

        $this->runCommand(self::IN_BUCKET_61);

        self::assertContains($adminA, $this->sentTo);
        self::assertNotContains($adminB, $this->sentTo);
    }

    public function testMailerOutageExitsFailureAndRetriesNextRun(): void
    {
        [, , $admin] = $this->seedClub('FAIL');

        $this->throwForRecipient = $admin;
        $tester = $this->runCommand(self::IN_BUCKET_61, expectSuccess: false);
        self::assertSame(Command::FAILURE, $tester->getStatusCode(), 'a send failure must exit non-zero for cron monitors');
        self::assertSame([], $this->sentTo);

        // Nothing was marked sent → the next healthy run retries the milestone.
        $this->reset();
        $this->throwForRecipient = '';
        $this->runCommand(self::IN_BUCKET_61);
        self::assertSame([$admin], $this->sentTo);
    }

    public function testDormantClubIsStillRemindedYearsLater(): void
    {
        // Current season 2025-2026 never transitioned; in May 2027 the pivot
        // must anchor on TODAY (next pivot 2027-07-15), not on the stale season
        // (2026-07-15, in the past → silence forever).
        [, , $admin] = $this->seedClub('DORM');

        $this->runCommand('2027-05-20');

        self::assertContains($admin, $this->sentTo, 'a dormant club must be nudged before EVERY upcoming pivot');
    }

    public function testPartialMailerFailureRetriesTheWholeMilestone(): void
    {
        [$club, , $admin] = $this->seedClub('PART');
        $this->addMember($club, 'second-mgr@club.fr', 'admin', true);

        // One of the two managers fails → milestone NOT marked; next run
        // retries both (at-least-once, no manager silently dropped).
        $this->throwForRecipient = $admin;
        $tester = $this->runCommand(self::IN_BUCKET_61, expectSuccess: false);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(['second-mgr@club.fr'], $this->sentTo);

        $this->reset();
        $this->throwForRecipient = '';
        $this->runCommand(self::IN_BUCKET_61);
        self::assertContains($admin, $this->sentTo, 'the failed manager must be retried');
    }

    public function testInvalidDateExitsFailureWithoutSending(): void
    {
        $this->seedClub('BADDATE');

        $tester = $this->runCommand('2026-02-30', expectSuccess: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame([], $this->sentTo);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function reset(): void
    {
        $this->sentTo = [];
        $this->sentSubjects = [];
    }

    private function runCommand(string $date, bool $expectSuccess = true, bool $dryRun = false): CommandTester
    {
        $container = self::getContainer();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (RawMessage $message): void {
            if ($message instanceof Email) {
                $to = $message->getTo()[0]->getAddress();
                if ('' !== $this->throwForRecipient && $to === $this->throwForRecipient) {
                    throw new RuntimeException('smtp down');
                }
                $this->sentTo[] = $to;
                $this->sentSubjects[] = (string) $message->getSubject();
            }
        });

        $command = new TransitionReminderCommand(
            $this->em,
            $container->get(TenantConnectionContext::class),
            $mailer,
            $container->get(ClubUserRepository::class),
            $container->get(SeasonResolver::class),
            $container->get(TransitionReminderLogRepository::class),
            new TransitionReminderMailBuilder('http://localhost:5173'),
            $container->get(ClockInterface::class),
            $container->get(ClubDay::class),
        );

        $tester = new CommandTester($command);
        $tester->execute($dryRun ? ['--date' => $date, '--dry-run' => true] : ['--date' => $date]);
        if ($expectSuccess) {
            $tester->assertCommandIsSuccessful();
        }

        return $tester;
    }

    private function season(Club $club, DateTimeImmutable $start): Season
    {
        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName($start->format('Y'));
        $season->setStartDate($start);
        $season->setEndDate($start->modify('+10 months'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    private function addMember(Club $club, string $email, string $role, bool $active): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User;
        $user->setEmail($email);
        $user->setFirstName('X');
        $user->setLastName('Y');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole($role);
        $cu->setIsActive($active);
        $this->em->persist($cu);
        $this->em->flush();
    }

    /**
     * A club whose current season is 2025-2026 (pivot → 2026-07-15), no successor.
     *
     * @return array{0: Club, 1: Season, 2: string}
     */
    private function seedClub(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-tr-' . strtolower($tag) . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);
        $this->em->flush();

        $adminEmail = 'admin-tr-' . strtolower($tag) . '-' . $uid . '@club.fr';
        $this->addMember($club, $adminEmail, 'admin', true);

        $season = $this->season($club, new DateTimeImmutable('2025-09-01'));

        return [$club, $season, $adminEmail];
    }
}
