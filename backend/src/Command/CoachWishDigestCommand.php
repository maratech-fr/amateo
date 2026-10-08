<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\CoachWish;
use App\Entity\CoachWishCampaign;
use App\Repository\ClubUserRepository;
use App\Repository\CoachWishTokenRepository;
use App\Service\ClubDay;
use App\Service\CoachWishMailBuilder;
use App\Service\CoachWishPerimeter;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Digest quotidien de la collecte des doléances (feature #10, lot C3) — 7h Europe/Paris
 * via l'AdminJobCatalog. Patron PeriodReminderCommand : parcours des clubs sous RLS, un
 * club en échec ne bloque jamais les autres.
 *
 * Par campagne du club :
 *  - deadline PAS dépassée (incluse, D6) : s'il existe au moins une réponse POSTÉRIEURE au
 *    dernier digest (`token.respondedAt > lastDigestAt`) → email aux gestionnaires (tous
 *    les owner/admin actifs) : nouveaux répondants + état complet. Sinon RIEN — « silence
 *    total, pas de nouvel email » (décision fondateur).
 *  - deadline dépassée et récap PAS envoyé : récap final UNE fois, quel que soit l'état
 *    (même 0/8 — décision D5 : c'est la clôture, le signal d'agir autrement).
 */
#[AsCommand(
    name: 'app:coach-wishes:digest',
    description: 'Daily coach-wish digest to club managers (new responses only) + one final recap after the deadline.',
)]
final class CoachWishDigestCommand extends Command
{
    private bool $hadSendFailure = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly MailerInterface $mailer,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly CoachWishTokenRepository $tokenRepository,
        private readonly CoachWishMailBuilder $mailBuilder,
        private readonly CoachWishPerimeter $perimeter,
        private readonly ClockInterface $clock,
        private readonly ClubDay $clubDay,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List digests without sending any email.');
        $this->addOption('date', null, InputOption::VALUE_REQUIRED, 'Treat this YYYY-MM-DD as "today" (rehearsal/tests).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $dateOption = $input->getOption('date');
        $forcedToday = null;
        if (\is_string($dateOption) && '' !== $dateOption) {
            $forcedToday = DateTimeImmutable::createFromFormat('!Y-m-d', $dateOption) ?: null;
            if (null === $forcedToday) {
                $io->error('Invalid --date: expected a real calendar date YYYY-MM-DD.');

                return Command::FAILURE;
            }
        }

        $clubs = $this->entityManager->getRepository(Club::class)->findAll();
        $this->entityManager->clear();

        $sent = 0;
        foreach ($clubs as $club) {
            try {
                $sent += $this->digestClub($club, $forcedToday, $dryRun, $io);
            } catch (Throwable $e) {
                // One bad club must not block the digests of the others.
                $io->warning(\sprintf('Club %s skipped: %s', $club->getId(), $e->getMessage()));
            } finally {
                $this->entityManager->clear();
                $this->tenantConnectionContext->clear();
            }
        }

        $io->success(\sprintf('%d digest/recap email(s) %s.', $sent, $dryRun ? 'detected (dry-run)' : 'sent'));

        return $this->hadSendFailure ? Command::FAILURE : Command::SUCCESS;
    }

    private function digestClub(Club $club, ?DateTimeImmutable $forcedToday, bool $dryRun, SymfonyStyle $io): int
    {
        $clubId = $club->getId();
        $this->tenantConnectionContext->setClubId($clubId);

        /** @var list<CoachWishCampaign> $campaigns */
        $campaigns = $this->entityManager->getRepository(CoachWishCampaign::class)->findBy(['clubId' => $clubId]);
        if ([] === $campaigns) {
            return 0;
        }

        // « Aujourd'hui » = le jour CALENDAIRE du club (comme PeriodReminderCommand).
        // P4-46 — « quel jour est-on pour ce club ? » vit dans ClubDay, plus jamais inline.
        $todayYmd = ($forcedToday ?? $this->clubDay->todayFor($club))->format('Y-m-d');

        $emails = null; // gestionnaires — résolus au premier besoin seulement
        $sent = 0;
        foreach ($campaigns as $campaign) {
            $deadlinePassed = $campaign->getDeadline()->format('Y-m-d') < $todayYmd; // deadline INCLUSE (D6)

            if (!$deadlinePassed) {
                $sent += $this->dailyDigest($campaign, $club, $dryRun, $io, $emails);
            } elseif (null === $campaign->getFinalRecapSentAt()) {
                $sent += $this->finalRecap($campaign, $club, $dryRun, $io, $emails);
            }
            // Flush PAR campagne : un stamp d'idempotence gagné (envoi réussi) est persisté
            // avant de passer à la suivante — l'échec de flush d'une campagne ne ré-émet pas
            // les emails déjà partis d'une autre.
            if (!$dryRun) {
                $this->entityManager->flush();
            }
        }

        return $sent;
    }

    /**
     * @param list<string>|null $emails
     *
     * @param-out list<string>|null $emails
     */
    private function dailyDigest(CoachWishCampaign $campaign, Club $club, bool $dryRun, SymfonyStyle $io, ?array &$emails): int
    {
        // Horodatage du stamp capturé AVANT la lecture des tokens : une réponse qui tombe
        // pendant l'envoi (après la lecture) reste > lastDigestAt au run suivant, jamais
        // perdue. Stamper avec l'heure POST-envoi la ferait disparaître.
        $stampAt = $this->clock->now();
        [$newNames, $respondedNames, $silentNames] = $this->splitCoaches($campaign);
        if ([] === $newNames) {
            return 0; // Silence depuis le dernier digest → aucun email (décision fondateur).
        }

        $emails ??= $this->clubUserRepository->findManagementEmails((string) $campaign->getClubId());
        if ([] === $emails) {
            return 0;
        }

        $periodTitle = $this->periodTitle($campaign);
        if ($dryRun) {
            $io->writeln(\sprintf('  <comment>would digest</comment> « %s » (club %s) : %d nouvelle(s) réponse(s) → %d gestionnaire(s)', $periodTitle, $club->getId(), \count($newNames), \count($emails)));

            return \count($emails);
        }

        $sent = 0;
        foreach ($emails as $to) {
            $sent += $this->send($this->mailBuilder->buildDigest($to, $club->getName(), $periodTitle, $newNames, $respondedNames, $silentNames, $club), $io);
        }
        // On n'avance lastDigestAt QUE si au moins un gestionnaire a été atteint — sinon
        // (SMTP mort) le digest de cette réponse serait perdu à jamais (jamais retenté).
        if ($sent > 0) {
            $campaign->markDigestSent($stampAt);
        }

        return $sent;
    }

    /**
     * @param list<string>|null $emails
     *
     * @param-out list<string> $emails
     */
    private function finalRecap(CoachWishCampaign $campaign, Club $club, bool $dryRun, SymfonyStyle $io, ?array &$emails): int
    {
        $stampAt = $this->clock->now();
        [, $respondedNames, $silentNames] = $this->splitCoaches($campaign);

        $emails ??= $this->clubUserRepository->findManagementEmails((string) $campaign->getClubId());
        if ([] === $emails) {
            // Récap marqué envoyé quand même : sans AUCUN gestionnaire à prévenir, re-tenter
            // chaque matin ne produirait jamais rien de plus (à distinguer d'un SMTP mort).
            $campaign->markFinalRecapSent($stampAt);

            return 0;
        }

        $openWishCount = [] === $campaign->getTeamIds() ? 0 : (int) $this->entityManager->getRepository(CoachWish::class)
            ->count(['calendarEntryId' => $campaign->getCalendarEntryId(), 'teamId' => $campaign->getTeamIds(), 'done' => false]);

        $periodTitle = $this->periodTitle($campaign);
        if ($dryRun) {
            $io->writeln(\sprintf('  <comment>would recap</comment> « %s » (club %s) : %d/%d → %d gestionnaire(s)', $periodTitle, $club->getId(), \count($respondedNames), \count($respondedNames) + \count($silentNames), \count($emails)));

            return \count($emails);
        }

        $sent = 0;
        foreach ($emails as $to) {
            $sent += $this->send($this->mailBuilder->buildFinalRecap($to, $club->getName(), $periodTitle, $respondedNames, $silentNames, $openWishCount, $club), $io);
        }
        // Récap une seule fois — mais seulement s'il est PARTI : un échec total ne grille pas
        // la clôture (elle sera retentée le lendemain).
        if ($sent > 0) {
            $campaign->markFinalRecapSent($stampAt);
        }

        return $sent;
    }

    /**
     * Répartit les coachs du PÉRIMÈTRE COURANT (équipes retenues ∩ TeamCoach) porteurs d'un
     * token : [nouveaux répondants depuis le dernier digest, tous les répondants, silencieux].
     * Même périmètre que le presenter du cockpit — les noms de l'email et de l'écran coïncident.
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    private function splitCoaches(CoachWishCampaign $campaign): array
    {
        $perimeter = $this->perimeter->coachIdSet($campaign);
        $lastDigestAt = $campaign->getLastDigestAt();
        $new = $responded = $silent = [];
        foreach ($this->tokenRepository->findByCampaign($campaign->getId()) as $token) {
            if (!isset($perimeter[$token->getCoachId()])) {
                continue; // coach sorti du périmètre courant — invisible au cockpit, donc ici aussi.
            }
            $coach = $this->entityManager->getRepository(Coach::class)->find($token->getCoachId());
            if (!$coach instanceof Coach) {
                continue;
            }
            $name = trim($coach->getFirstName() . ' ' . $coach->getLastName());
            $respondedAt = $token->getRespondedAt();
            if (null === $respondedAt) {
                $silent[] = $name;
                continue;
            }
            $responded[] = $name;
            if (!$lastDigestAt instanceof DateTimeImmutable || $respondedAt > $lastDigestAt) {
                $new[] = $name;
            }
        }

        return [$new, $responded, $silent];
    }

    private function periodTitle(CoachWishCampaign $campaign): string
    {
        return $this->entityManager->getRepository(CalendarEntry::class)->find($campaign->getCalendarEntryId())?->getTitle() ?? 'la période';
    }

    private function send(Email $email, SymfonyStyle $io): int
    {
        try {
            $this->mailer->send($email);

            return 1;
        } catch (Throwable $e) {
            $this->hadSendFailure = true;
            $io->warning(\sprintf('Send failed: %s', $e->getMessage()));

            return 0;
        }
    }
}
