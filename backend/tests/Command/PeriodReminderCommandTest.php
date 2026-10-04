<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PeriodReminderCommand;
use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Repository\CalendarEntryRepository;
use App\Repository\ClubUserRepository;
use App\Repository\PeriodReminderLogRepository;
use App\Service\ClubDay;
use App\Service\PeriodReminderMailBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use App\Service\TenantConnectionContext;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * The reminder cron emails a club's managers about periods without an overlay
 * plan, once per milestone (14/7/3-day bucket) via a sent-log — catch-up-safe,
 * tenant-isolated, and red (exit non-zero) when the mailer is down.
 */
#[Group('phase1')]
#[Group('integration')]
final class PeriodReminderCommandTest extends KernelTestCase
{
    use TenantGucTrait;

    private const TODAY = '2026-01-15';

    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $sentTo = [];

    /** @var list<string> */
    private array $sentSubjects = [];

    private string $throwForRecipient = '';

    public function testRemindsManagersInEachBucket(): void
    {
        foreach ([14, 7, 3] as $days) {
            $this->reset();
            [, $season, $admin] = $this->seedClub('T' . $days);
            $this->period($season, $this->plus($days));
            $this->em->flush();

            $this->runCommand();

            self::assertContains($admin, $this->sentTo, "a period at J-{$days} must email the manager");
        }
    }

    public function testJ3SubjectIsRed(): void
    {
        [, $season] = $this->seedClub('RED');
        $this->period($season, $this->plus(2));
        $this->em->flush();

        $this->runCommand();

        self::assertNotEmpty($this->sentSubjects);
        self::assertStringStartsWith('🔴', $this->sentSubjects[0]);
    }

    public function testExcludedEntriesNeverRemind(): void
    {
        [, $season, $admin] = $this->seedClub('EXC');
        $this->period($season, $this->plus(14), overlay: true); // has plan
        $this->period($season, $this->plus(7), status: CalendarEntryStatus::IGNORED); // ignored
        $this->period($season, $this->plus(-1)); // already started
        $this->period($season, $this->plus(20)); // beyond horizon
        $this->em->flush();

        $this->runCommand();

        self::assertNotContains($admin, $this->sentTo);
        self::assertSame([], $this->sentTo);
    }

    public function testDryRunSendsNothing(): void
    {
        [, $season] = $this->seedClub('DRY');
        $this->period($season, $this->plus(14));
        $this->em->flush();

        $tester = $this->runCommand(dryRun: true);

        self::assertSame([], $this->sentTo);
        self::assertStringContainsString('would remind', $tester->getDisplay());
        $this->em->clear();
    }

    public function testTenantIsolationBetweenClubs(): void
    {
        [, $seasonA, $adminA] = $this->seedClub('ISOA');
        [, $seasonB, $adminB] = $this->seedClub('ISOB');
        $this->seedClub('ISOC'); // no period → gets nothing
        $this->period($seasonA, $this->plus(14));
        $this->period($seasonB, $this->plus(7));
        $this->em->flush();

        $this->runCommand();

        self::assertContains($adminA, $this->sentTo);
        self::assertContains($adminB, $this->sentTo);
        self::assertCount(2, $this->sentTo);
    }

    public function testResilientToAFailingClubAndReturnsFailure(): void
    {
        [, $seasonA, $adminA] = $this->seedClub('FAILA');
        [, $seasonB, $adminB] = $this->seedClub('FAILB');
        $this->period($seasonA, $this->plus(14));
        $this->period($seasonB, $this->plus(14));
        $this->em->flush();

        $this->throwForRecipient = $adminA;
        $tester = $this->runCommand(expectSuccess: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), 'a send failure must exit non-zero for cron monitors');
        self::assertNotContains($adminA, $this->sentTo);
        self::assertContains($adminB, $this->sentTo);
    }

    public function testMailerOutageExitsFailure(): void
    {
        [, $season, $admin] = $this->seedClub('OUT');
        $this->period($season, $this->plus(14));
        $this->em->flush();

        $this->throwForRecipient = $admin;
        $tester = $this->runCommand(expectSuccess: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame([], $this->sentTo);
    }

    public function testOnlyManagementRolesAreEmailed(): void
    {
        [$club, $season, $admin] = $this->seedClub('ROLE');
        $this->addMember($club, 'editor@club.fr', 'editor', true);
        $this->addMember($club, 'viewer@club.fr', 'viewer', true);
        $this->addMember($club, 'inactive@club.fr', 'admin', false);
        $this->period($season, $this->plus(14));
        $this->em->flush();

        $this->runCommand();

        self::assertSame([$admin], $this->sentTo);
    }

    public function testIdempotentViaLog(): void
    {
        [, $season, $admin] = $this->seedClub('IDEM');
        $this->period($season, $this->plus(14));
        $this->em->flush();

        $this->runCommand(); // logs bucket 14
        self::assertSame([$admin], $this->sentTo);

        $this->reset();
        $this->runCommand(); // same bucket already logged → nothing
        self::assertSame([], $this->sentTo);
    }

    public function testCatchUpAfterAMissedRun(): void
    {
        [, $season, $admin] = $this->seedClub('CATCH');
        // Period starts in 10 days; the J-14 run never happened. A run today must
        // still emit the (unsent) bucket-14 reminder — no reminder is lost.
        $this->period($season, $this->plus(10));
        $this->em->flush();

        $this->runCommand();

        self::assertContains($admin, $this->sentTo);
    }

    public function testInvalidDateExitsFailureWithoutSending(): void
    {
        [, $season] = $this->seedClub('BADDATE');
        $this->period($season, $this->plus(14));
        $this->em->flush();

        $tester = $this->runCommand(date: '2026-02-30', expectSuccess: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame([], $this->sentTo);
    }

    /**
     * P4-16 — chaque club suit SON horloge dans une MÊME exécution, SANS `--date`.
     *
     * Club A à horloge simulée au 15 janvier, club B à l'heure réelle. Le cron calcule J-N
     * club par club via ClubDay : A est rappelé pour sa période du 29 janvier (J-14 sous SA
     * date) et jamais pour une période calée sur le jour RÉEL (hors horizon sous janvier) ;
     * B est rappelé pour sa période à J-14 sous le jour réel. La preuve que la date simulée
     * d'un club n'écrase pas celle d'un autre, et que l'absence d'horloge reste le jour réel.
     */
    public function testEachClubFollowsItsOwnClockInASingleRunWithoutForcedDate(): void
    {
        $simulatedToday = new DateTimeImmutable('2026-01-15');
        $realToday = $this->clubDayToday(); // le jour réel tel que ClubDay le voit (fuseau club)

        [$clubA, $seasonA, $adminA] = $this->seedClub('CLKA');
        // SEC-30 — la date simulée n'est honorée (et persistable) que pour un club démo.
        $clubA->setIsDemo(true);
        $clubA->setSimulatedToday($simulatedToday);
        $this->em->flush();
        // A n'a QU'UNE période, calée à J-14 SOUS son horloge simulée (29 janvier). Sous le
        // jour RÉEL (des mois plus tard), cette même date est dans le passé → aucun rappel :
        // le rappel de A PROUVE qu'il lit sa date simulée, pas le jour réel.
        $this->period($seasonA, $simulatedToday->modify('+14 days'));

        [, $seasonB, $adminB] = $this->seedClub('CLKB'); // pas d'horloge → jour réel
        $this->period($seasonB, $realToday->modify('+14 days'));
        $this->em->flush();

        $this->runCommandLive();

        self::assertContains($adminA, $this->sentTo, 'A suit SA date simulée : la période du 29 janvier tombe à J-14 (red si ClubDay ignore simulated_today)');
        self::assertContains($adminB, $this->sentTo, 'B sans horloge suit le jour réel dans la MÊME exécution : sa période à J-14 est rappelée');

        // Idempotence à date figée : un second passage à la même horloge ne renvoie RIEN
        // (le ledger PeriodReminderLog est clé par entrée + palier, indépendant de l'horloge).
        $this->reset();
        $this->runCommandLive();
        self::assertSame([], $this->sentTo, 'pas de double envoi : le palier déjà journalisé est tenu quelle que soit l\'horloge');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** Le jour réel vu par ClubDay pour un club de fuseau Europe/Paris (aucune horloge). */
    private function clubDayToday(): DateTimeImmutable
    {
        $probe = (new Club)->setTimezone('Europe/Paris');

        return self::getContainer()->get(ClubDay::class)->todayFor($probe);
    }

    private function runCommandLive(): CommandTester
    {
        $container = self::getContainer();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (RawMessage $message): void {
            if ($message instanceof Email) {
                $this->sentTo[] = $message->getTo()[0]->getAddress();
                $this->sentSubjects[] = (string) $message->getSubject();
            }
        });

        $command = new PeriodReminderCommand(
            $this->em,
            $container->get(TenantConnectionContext::class),
            $mailer,
            $container->get(CalendarEntryRepository::class),
            $container->get(ClubUserRepository::class),
            $container->get(SeasonResolver::class),
            $container->get(PeriodReminderLogRepository::class),
            new PeriodReminderMailBuilder('http://localhost:5173'),
            $container->get(ClubDay::class),
        );

        $tester = new CommandTester($command);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    private function reset(): void
    {
        $this->sentTo = [];
        $this->sentSubjects = [];
    }

    private function plus(int $days): DateTimeImmutable
    {
        return new DateTimeImmutable(self::TODAY)->modify(\sprintf('%+d days', $days));
    }

    private function runCommand(string $date = self::TODAY, bool $expectSuccess = true, bool $dryRun = false): CommandTester
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

        $command = new PeriodReminderCommand(
            $this->em,
            $container->get(TenantConnectionContext::class),
            $mailer,
            $container->get(CalendarEntryRepository::class),
            $container->get(ClubUserRepository::class),
            $container->get(SeasonResolver::class),
            $container->get(PeriodReminderLogRepository::class),
            new PeriodReminderMailBuilder('http://localhost:5173'),
            $container->get(ClubDay::class),
        );

        $tester = new CommandTester($command);
        $tester->execute($dryRun ? ['--date' => $date, '--dry-run' => true] : ['--date' => $date]);
        if ($expectSuccess) {
            $tester->assertCommandIsSuccessful();
        }

        return $tester;
    }

    private function period(Season $season, DateTimeImmutable $start, bool $overlay = false, CalendarEntryStatus $status = CalendarEntryStatus::ACTIVE): void
    {
        $this->scopeGucToClub($season->getClubId());
        $entry = new CalendarEntry;
        $entry->setClubId($season->getClubId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setStatus($status);
        $entry->setTitle('Gym fermé');
        $entry->setStartDate($start);
        $entry->setEndDate($start->modify('+6 days'));
        $this->em->persist($entry);
        $this->em->flush();

        if ($overlay) {
            // lot D-b : « a un overlay » = le plan de la période porte ≥ 1 version (générée),
            // plus un pointeur sur l'entrée — le rappel s'arrête dès la 1re génération.
            $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
            $planId = $provisioner->provisionPeriodPlan($entry->getId());
            self::assertIsString($planId);
            $schedule = (new Schedule)
                ->setClubId($season->getClubId())
                ->setSeasonId($season->getId())
                ->setName('Overlay')
                ->setStatus(ScheduleStatus::COMPLETED)
                ->setSchedulePlanId($planId);
            $this->em->persist($schedule);
            $provisioner->linkSchedule($schedule);
            $this->em->flush();
        }
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
     * @return array{0: Club, 1: Season, 2: string}
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
        $this->em->flush();

        $adminEmail = 'admin-' . $tag . '-' . $uid . '@club.fr';
        $this->addMember($club, $adminEmail, 'admin', true);

        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season, $adminEmail];
    }
}
