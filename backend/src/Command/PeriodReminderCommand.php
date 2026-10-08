<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\PeriodReminderLog;
use App\Repository\CalendarEntryRepository;
use App\Repository\ClubUserRepository;
use App\Repository\PeriodReminderLogRepository;
use App\Service\ClubDay;
use App\Service\PeriodReminderMailBuilder;
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
 * Walks clubs on the runtime (RLS) connection like ReconcileStuckSchedulesCommand
 * (club has no RLS policy → readable with empty GUC; each club's data read under
 * its own GUC). A failure on one club never blocks the others.
 */
#[AsCommand(
    name: 'app:periods:remind',
    description: 'Email club managers about upcoming periods still lacking an overlay plan (J-14/J-7/J-3; never auto-acts).',
)]
final class PeriodReminderCommand extends Command
{
    /** Milestone buckets (days-before-start). The furthest is also the horizon. J-3 is "red". */
    private const HORIZON = 14;

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
        if ([] === $periods) {
            return 0; // No period in the horizon → skip the manager lookup.
        }

        $emails = $this->clubUserRepository->findManagementEmails($clubId);

        $sent = 0;
        foreach ($periods as $entry) {
            $days = (int) $today->diff($entry->getStartDate())->format('%r%a');
            $threshold = $this->bucket($days);
            if (null === $threshold || $this->reminderLogRepository->alreadySent($entry->getId(), $threshold)) {
                continue; // outside a bucket, or this milestone already emailed.
            }

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

        return $sent;
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
