<?php

declare(strict_types=1);

namespace App\Command;

use App\Clock\ClubClock;
use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\PeriodReminderLog;
use App\Entity\SchoolHolidayPeriod;
use App\Entity\Season;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\HolidayCloseReason;
use App\Enum\SeasonPlanState;
use App\Repository\CalendarEntryRepository;
use App\Repository\ClubUserRepository;
use App\Repository\HolidayReminderLogRepository;
use App\Repository\PeriodReminderLogRepository;
use App\Repository\SchoolHolidayPeriodRepository;
use App\Service\ClubDay;
use App\Service\HolidayReminderStateResolver;
use App\Service\PeriodReminderMailBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Throwable;

/**
 * Daily reminder cron (cockpit palier C, v3 §8.2). Emails a club's managers when
 * a period (closure/holiday/…) is approaching and still has NO overlay plan, at
 * exactly J-14, J-7 and J-3 before it starts. It NEVER acts on its own — the
 * radar surfaces the same to-dos in-app; this is the out-of-app nudge.
 *
 * School holidays (same cron): a holiday of the club's school zone, contained WHOLE
 * in one of its seasons, that is neither ignored, nor covered by cutoffs/closures on
 * every workweek, nor planned ({@see HolidayReminderStateResolver}), is reminded at
 * J-30, J-14 and J-7 (red) — never summer (it always overflows the season), never a
 * demo club without a simulated clock. While such a holiday is open (or covered),
 * the HOLIDAY entries it owns get no per-entry reminder: one mail per milestone.
 * Entries are reminded first, then holidays.
 *
 * Walks clubs on the runtime (RLS) connection like ReconcileStuckSchedulesCommand
 * (club has no RLS policy → readable with empty GUC; each club's data read under
 * its own GUC). A failure on one club never blocks the others.
 */
#[AsCommand(
    name: 'app:periods:remind',
    description: 'Email club managers about upcoming periods still lacking an overlay plan (J-14/J-7/J-3) and school holidays of their zone not prepared yet (J-30/J-14/J-7); never auto-acts.',
)]
final class PeriodReminderCommand extends Command
{
    /** Milestone buckets (days-before-start). The furthest is also the horizon. J-3 is "red". */
    private const HORIZON = 14;

    /** School-holiday milestones: 30, 14, 7 days before the holiday. J-7 is "red". */
    private const HOLIDAY_HORIZON = 30;

    private bool $hadSendFailure = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly MailerInterface $mailer,
        private readonly CalendarEntryRepository $calendarEntryRepository,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly SeasonResolver $seasonResolver,
        private readonly PeriodReminderLogRepository $reminderLogRepository,
        private readonly PeriodReminderMailBuilder $mailBuilder,
        private readonly ClubDay $clubDay,
        private readonly SchoolHolidayPeriodRepository $schoolHolidayRepository,
        private readonly HolidayReminderLogRepository $holidayReminderLogRepository,
        private readonly HolidayReminderStateResolver $holidayStateResolver,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly ClubClock $clubClock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List reminders without sending any email.');
        $this->addOption('date', null, InputOption::VALUE_REQUIRED, 'Treat this YYYY-MM-DD as "today" (rehearsal/tests).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $dateOption = $input->getOption('date');
        $forcedToday = $this->resolveToday($dateOption);
        if (\is_string($dateOption) && '' !== $dateOption && !$forcedToday instanceof DateTimeImmutable) {
            $io->error('Invalid --date: expected a real calendar date YYYY-MM-DD.');

            return Command::FAILURE;
        }

        // Detached after clear(), but only id/name/timezone getters are read —
        // no per-club re-SELECT inside the loop.
        $clubs = $this->entityManager->getRepository(Club::class)->findAll();
        $this->entityManager->clear();

        $sent = 0;
        foreach ($clubs as $club) {
            try {
                $sent += $this->remindClub($club, $forcedToday, $dryRun, $io);
            } catch (Throwable $e) {
                // One bad club must not block reminders for the others.
                $io->warning(\sprintf('Club %s skipped: %s', $club->getId(), $e->getMessage()));
            } finally {
                $this->entityManager->clear();
                $this->tenantConnectionContext->clear();
            }
        }

        $io->success(\sprintf('%d reminder email(s) %s.', $sent, $dryRun ? 'detected (dry-run)' : 'sent'));

        // Exit non-zero if any send failed so a cron monitor sees the outage
        // (a total mailer failure must NOT look like a healthy run).
        return $this->hadSendFailure ? Command::FAILURE : Command::SUCCESS;
    }

    private function remindClub(Club $club, ?DateTimeImmutable $forcedToday, bool $dryRun, SymfonyStyle $io): int
    {
        $clubId = $club->getId();
        $this->tenantConnectionContext->setClubId($clubId);

        // "Today" is the CLUB's calendar day, not the server's (a UTC cron near
        // midnight would otherwise shift every bucket by one day for a Paris club).
        // An explicit --date (rehearsal/tests) overrides for all clubs.
        $today = $forcedToday ?? $this->clubDay->todayFor($club);

        // Remind across ALL the club's seasons: the date horizon does the real
        // bounding. Keying on the current season only would go silent for a
        // period of season N still upcoming right after the July-15 pivot
        // (N+1 current, N period 4 days away → no e-mail).
        $seasons = $this->seasonResolver->seasonsForClub($clubId);
        if ([] === $seasons) {
            return 0;
        }

        $periods = [];
        foreach ($seasons as $season) {
            $periods = array_merge(
                $periods,
                $this->calendarEntryRepository->findUpcomingPeriodsWithoutOverlay($clubId, $season->getId(), $today, self::HORIZON),
            );
        }

        // School holidays are computed BEFORE the "nothing to remind" exit: a club with
        // no entry in the period horizon still gets its holiday reminders.
        $zone = $this->holidayZoneOf($club);
        $closeReasons = [];
        $dueHolidays = null === $zone ? [] : $this->dueHolidays($clubId, $zone, $seasons, $today, $closeReasons);

        $dueEntries = [];
        foreach ($periods as $entry) {
            if (null !== $zone && $this->isOwnedByAHoliday($entry, $clubId, $zone, $seasons, $closeReasons)) {
                continue; // the holiday branch reminds it (or it is covered): no duplicate.
            }
            $days = (int) $today->diff($entry->getStartDate())->format('%r%a');
            $threshold = $this->bucket($days);
            if (null === $threshold || $this->reminderLogRepository->alreadySent($entry->getId(), $threshold)) {
                continue; // outside a bucket, or this milestone already emailed.
            }
            $dueEntries[] = [$entry, $days, $threshold];
        }
        if ([] === $dueEntries && [] === $dueHolidays) {
            return 0; // Nothing due → skip the manager lookup.
        }

        $emails = $this->clubUserRepository->findManagementEmails($clubId);

        $sent = 0;
        foreach ($dueEntries as [$entry, $days, $threshold]) {
            if ($dryRun) {
                $io->writeln(\sprintf('  <comment>would remind</comment> J-%d (bucket %d) · %s (club %s) → %d manager(s)', $days, $threshold, $entry->getTitle(), $clubId, \count($emails)));
                $sent += \count($emails);

                continue;
            }

            $sentForEntry = 0;
            foreach ($emails as $to) {
                try {
                    $this->mailer->send($this->mailBuilder->build($to, $club->getName(), $entry, $days, $club));
                    ++$sentForEntry;
                } catch (Throwable $e) {
                    $this->hadSendFailure = true;
                    $io->warning(\sprintf('Email to %s failed: %s', $to, $e->getMessage()));
                }
            }
            $sent += $sentForEntry;

            // Mark the milestone sent only if it actually reached someone, so a
            // failed run retries it next time instead of losing it.
            if ($sentForEntry > 0) {
                $this->markSent($entry, $threshold);
            }
        }

        foreach ($dueHolidays as [$holiday, $season, $days, $threshold]) {
            if ($dryRun) {
                $io->writeln(\sprintf('  <comment>would remind</comment> J-%d (bucket %d) · %s (club %s) → %d manager(s)', $days, $threshold, $holiday->getLabel(), $clubId, \count($emails)));
                $sent += \count($emails);

                continue;
            }

            $seasonPlan = SeasonPlanState::fromPayload($this->schedulePlanProvisioner->seasonPlanPayload($season->getId()));
            $sentForHoliday = 0;
            foreach ($emails as $to) {
                try {
                    $this->mailer->send($this->mailBuilder->buildForHoliday($to, $club->getName(), $holiday, $days, $seasonPlan, $club));
                    ++$sentForHoliday;
                } catch (Throwable $e) {
                    $this->hadSendFailure = true;
                    $io->warning(\sprintf('Email to %s failed: %s', $to, $e->getMessage()));
                }
            }
            $sent += $sentForHoliday;

            // Same rule as entries: a milestone that reached nobody is retried next run.
            if ($sentForHoliday > 0) {
                $this->holidayReminderLogRepository->markSent($season->getId(), $holiday->getId(), $threshold);
            }
        }

        return $sent;
    }

    /**
     * The club's school zone when the holiday branch applies to it, else null: a club
     * without a zone, or a demo club living at real time (no simulated clock), gets no
     * holiday reminder — and keeps the per-entry reminders of its HOLIDAY entries.
     */
    private function holidayZoneOf(Club $club): ?string
    {
        $zone = $club->getSchoolZone();
        if (null === $zone || '' === $zone) {
            return null;
        }
        if ($club->isDemo() && !$this->clubClock->simulatedTodayFor($club) instanceof DateTimeImmutable) {
            return null;
        }

        return $zone;
    }

    /**
     * Holidays of the zone starting within the holiday horizon, contained in a season
     * of the club, in a milestone not sent yet, and still open — chronological.
     *
     * @param list<Season>                           $seasons
     * @param array<string, HolidayCloseReason|null> $closeReasons memo, by holiday id
     *
     * @return list<array{0: SchoolHolidayPeriod, 1: Season, 2: int, 3: int}>
     */
    private function dueHolidays(string $clubId, string $zone, array $seasons, DateTimeImmutable $today, array &$closeReasons): array
    {
        $due = [];
        foreach ($this->schoolHolidayRepository->findStartingInWindow($zone, $today, self::HOLIDAY_HORIZON) as $holiday) {
            $season = $this->containingSeason($holiday, $seasons);
            if (!$season instanceof Season) {
                continue; // overflows every season of the club (always the case for summer).
            }
            $days = (int) $today->diff($holiday->getStartDate())->format('%r%a');
            $threshold = $this->holidayBucket($days);
            if (null === $threshold || $this->holidayReminderLogRepository->alreadySent($season->getId(), $holiday->getId(), $threshold)) {
                continue;
            }
            if ($this->closeReason($clubId, $holiday, $season, $closeReasons) instanceof HolidayCloseReason) {
                continue;
            }
            $due[] = [$holiday, $season, $days, $threshold];
        }

        return $due;
    }

    /**
     * Does a treated holiday own this entry's reminder? Only HOLIDAY entries: their root
     * (the entry itself, or its mother) overlapping a treated holiday that is OPEN (the
     * holiday branch reminds it), or COVERED when the entry lies entirely within it (the
     * covering cutoff silences the adapted mother and its weeks). A PLANNED or IGNORED
     * holiday gives its entries back their own reminders.
     *
     * @param list<Season>                           $seasons
     * @param array<string, HolidayCloseReason|null> $closeReasons
     */
    private function isOwnedByAHoliday(CalendarEntry $entry, string $clubId, string $zone, array $seasons, array &$closeReasons): bool
    {
        if (CalendarEntryPeriodType::HOLIDAY !== $entry->getPeriodType()) {
            return false;
        }
        $root = $entry;
        $parentId = $entry->getParentEntryId();
        if (null !== $parentId) {
            $root = $this->entityManager->find(CalendarEntry::class, $parentId) ?? $entry;
        }

        foreach ($this->schoolHolidayRepository->findByZoneAndWindow($zone, $root->getStartDate(), $root->getEndDate()) as $holiday) {
            $season = $this->containingSeason($holiday, $seasons);
            if (!$season instanceof Season) {
                continue;
            }
            $reason = $this->closeReason($clubId, $holiday, $season, $closeReasons);
            if (!$reason instanceof HolidayCloseReason) {
                return true;
            }
            if (HolidayCloseReason::COVERED === $reason
                && $entry->getStartDate()->format('Y-m-d') >= $holiday->getStartDate()->format('Y-m-d')
                && $entry->getEndDate()->format('Y-m-d') <= $holiday->getEndDate()->format('Y-m-d')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, HolidayCloseReason|null> $closeReasons */
    private function closeReason(string $clubId, SchoolHolidayPeriod $holiday, Season $season, array &$closeReasons): ?HolidayCloseReason
    {
        $holidayId = $holiday->getId();
        if (!\array_key_exists($holidayId, $closeReasons)) {
            $closeReasons[$holidayId] = $this->holidayStateResolver->isClosed(
                $clubId,
                $holidayId,
                $holiday->getStartDate()->format('Y-m-d'),
                $holiday->getEndDate()->format('Y-m-d'),
                $season->getStartDate()->format('Y-m-d'),
                $season->getEndDate()->format('Y-m-d'),
            );
        }

        return $closeReasons[$holidayId];
    }

    /**
     * The club season containing the WHOLE holiday, else null.
     *
     * @param list<Season> $seasons
     */
    private function containingSeason(SchoolHolidayPeriod $holiday, array $seasons): ?Season
    {
        $start = $holiday->getStartDate()->format('Y-m-d');
        $end = $holiday->getEndDate()->format('Y-m-d');
        foreach ($seasons as $season) {
            if ($season->getStartDate()->format('Y-m-d') <= $start && $season->getEndDate()->format('Y-m-d') >= $end) {
                return $season;
            }
        }

        return null;
    }

    private function markSent(CalendarEntry $entry, int $threshold): void
    {
        $this->entityManager->persist(
            (new PeriodReminderLog)->setCalendarEntryId($entry->getId())->setThreshold($threshold),
        );
        $this->entityManager->flush();
    }

    /** The milestone bucket a period is in: 14 (>7..14d), 7 (>3..7d), 3 (0..3d), null otherwise. */
    private function bucket(int $days): ?int
    {
        if ($days < 0 || $days > self::HORIZON) {
            return null;
        }
        if ($days <= 3) {
            return 3;
        }

        return $days <= 7 ? 7 : 14;
    }

    /** The school-holiday milestone bucket: 30 (>14..30d), 14 (>7..14d), 7 (0..7d), null otherwise. */
    private function holidayBucket(int $days): ?int
    {
        if ($days < 0 || $days > self::HOLIDAY_HORIZON) {
            return null;
        }
        if ($days <= 7) {
            return 7;
        }

        return $days <= 14 ? 14 : 30;
    }

    /** Strict: a real calendar date, else null (rejects rollovers like 2026-02-30). No --date → null (per-club today). */
    private function resolveToday(mixed $dateOption): ?DateTimeImmutable
    {
        if (!\is_string($dateOption) || '' === $dateOption) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateOption);
        $errors = DateTimeImmutable::getLastErrors();
        if (false === $date || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }
}
