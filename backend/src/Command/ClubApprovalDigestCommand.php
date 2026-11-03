<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\ClubCreationRequest;
use App\Entity\User;
use App\Mail\EmailTemplateRenderer;
use App\Repository\ClubCreationRequestRepository;
use App\Repository\ClubRepository;
use App\Service\Basketball\FfbbClubDirectory;
use App\Service\ClubApprovalService;
use App\Service\MailFrom;
use App\Service\ManagerRoleRequestService;
use App\Service\MembershipRequestNotifier;
use App\Service\Registration\ClubWinBackService;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
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
 * P3-4 PR B — relances et expiration des demandes de création de club.
 *
 * Cadence fondateur (2026-08-05) : la demande expire à 7 jours ; le club est
 * relancé à 3 JOURS RESTANTS et LE JOUR J. Passée la date → statut `expired` :
 * le lien public meurt (404), mais la demande RESTE visible et approuvable en
 * console superadmin — « le superadmin peut valider si besoin ».
 *
 * Idempotent au jour près (`remindedAt` : au plus une relance par jour) ; les
 * demandes SANS mail club (introuvable à la FFBB) n'ont rien à relancer — leur
 * seule voie est la console. Quotidien (cron-runner, AdminJobCatalog),
 * `--dry-run` + `--date` pour répéter sans écrire. Table hors RLS (pas de
 * club_id) → aucun GUC.
 *
 * Chantier Membre (PR 3) — trois passes de plus :
 *  - une demande de Membre (reprise ou promotion) qui expire prévient son demandeur ;
 *  - une demande de reprise sans adresse officielle (FFBB muette) sur un club en fermeture est
 *    relue chaque jour ; le mail part dès que l'adresse revient ; celles qui restent sans adresse
 *    sont signalées au support (une liste par passage) ;
 *  - une demande de rôle sans réponse des gestionnaires 14 jours est transmise UNE fois à
 *    l'adresse officielle ({@see ManagerRoleRequestService::escalateDue}).
 */
#[AsCommand(
    name: 'app:club-approvals:digest',
    description: 'Remind clubs of pending creation requests (3 days left + expiry day) and expire overdue ones. Runs daily (cron-runner).',
)]
final class ClubApprovalDigestCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubCreationRequestRepository $requests,
        private readonly ClubApprovalService $approvalService,
        private readonly ManagerRoleRequestService $managerRoleRequests,
        private readonly MembershipRequestNotifier $membershipRequestNotifier,
        private readonly ClubRepository $clubs,
        private readonly ClubWinBackService $clubWinBack,
        private readonly FfbbClubDirectory $ffbbClubDirectory,
        private readonly MailerInterface $mailer,
        private readonly MailFrom $mailFrom,
        private readonly ManagerRegistry $managerRegistry,
        private readonly LoggerInterface $logger,
        private readonly string $frontendBaseUrl = '',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List reminders/expirations without sending or writing.');
        $this->addOption('date', null, InputOption::VALUE_REQUIRED, 'Treat this YYYY-MM-DD as "today" (rehearsal/tests).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $dateOption = $input->getOption('date');
        $today = \is_string($dateOption) && '' !== $dateOption
            ? new DateTimeImmutable($dateOption)
            : new DateTimeImmutable('today');
        $todayKey = $today->format('Y-m-d');

        $reminded = 0;
        $expired = 0;
        /** @var list<array{clubId: string, userId: string, requestedAt: DateTimeImmutable}> $expiredMemberRequests */
        $expiredMemberRequests = [];
        $pending = $this->requests->findBy(['status' => ClubCreationRequest::STATUS_PENDING]);
        foreach ($pending as $request) {
            $expiresKey = $request->getExpiresAt()->format('Y-m-d');

            // Échéance dépassée (le jour J reste OUVERT — sa relance part) :
            // le lien public meurt, la console superadmin prend le relais.
            if ($todayKey > $expiresKey) {
                ++$expired;
                $io->text(\sprintf('expire  %s (%s, échue le %s)', $request->getClubName(), $request->getAra(), $expiresKey));
                if (!$dryRun) {
                    $request->setStatus(ClubCreationRequest::STATUS_EXPIRED);
                    $request->setDecidedAt(null); // pas une décision — un délai
                    $club = $this->clubs->findRealByFfbbCode($request->getAra());
                    if ($club instanceof Club && $this->approvalService->isActiveMemberRequest($request, $club)) {
                        $expiredMemberRequests[] = ['clubId' => $club->getId(), 'userId' => $request->getUserId(), 'requestedAt' => $request->getCreatedAt()];
                    }
                }

                continue;
            }

            if (null === $request->getClubEmail()) {
                continue; // rien à relancer : la voie est la console superadmin
            }

            // Relances aux jours nommés par le fondateur : 3 jours restants + jour J.
            $daysLeft = (int) $today->diff($request->getExpiresAt()->setTime(0, 0))->format('%r%a');
            if (!\in_array($daysLeft, [3, 0], true)) {
                continue;
            }
            if ($request->getRemindedAt()?->format('Y-m-d') === $todayKey) {
                continue; // déjà relancé aujourd'hui (relance quotidienne idempotente)
            }
            $requester = $this->entityManager->getRepository(User::class)->find($request->getUserId());
            if (!$requester instanceof User) {
                continue;
            }

            ++$reminded;
            $io->text(\sprintf('relance %s (%s, J-%d)', $request->getClubName(), $request->getAra(), $daysLeft));
            if (!$dryRun) {
                $this->approvalService->sendApprovalEmail($request, $requester, reminder: true);
                $request->setRemindedAt($today);
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
            // Chantier Membre (PR 3) — après commit : le Membre dont la demande expire l'apprend.
            foreach ($expiredMemberRequests as $item) {
                $this->membershipRequestNotifier->notifyOfficialExpired($item['clubId'], $item['userId'], $item['requestedAt']);
            }
        }

        $lostClubs = $this->retryUnknownAddresses($pending, $dryRun, $io);
        $escalated = $this->managerRoleRequests->escalateDue($dryRun);
        foreach ($escalated as $label) {
            $io->text(\sprintf('transmission %s', $label));
        }
        if (!$dryRun && [] !== $lostClubs) {
            $this->alertSupport($lostClubs);
        }

        $io->success(\sprintf('%s%d relance(s), %d expiration(s), %d transmission(s), %d club(s) sans adresse.', $dryRun ? '[dry-run] ' : '', $reminded, $expired, \count($escalated), \count($lostClubs)));

        return Command::SUCCESS;
    }

    /**
     * Les demandes de reprise encore SANS adresse officielle sur un club en fermeture : l'adresse
     * FFBB est relue (la seule lecture sortante, bornée à un club en fermeture) ; revenue → elle est
     * enregistrée et le mail part ; sinon le club est signalé au support.
     *
     * @param list<ClubCreationRequest> $requests
     *
     * @return list<string> les clubs toujours sans adresse (noms)
     */
    private function retryUnknownAddresses(array $requests, bool $dryRun, SymfonyStyle $io): array
    {
        $lost = [];
        foreach ($requests as $request) {
            if (ClubCreationRequest::STATUS_PENDING !== $request->getStatus() || null !== $request->getClubEmail()) {
                continue;
            }
            $club = $this->clubs->findRealByFfbbCode($request->getAra());
            if (!$club instanceof Club || !$club->getErasureScheduledAt() instanceof DateTimeImmutable || !$this->clubWinBack->isManagerless($club->getId())) {
                continue;
            }
            if ($dryRun) {
                $io->text(\sprintf('adresse  %s (%s)', $club->getName(), $request->getAra()));

                continue;
            }
            $clubEmail = $this->ffbbClubDirectory->lookupClubEmail($request->getAra());
            $requester = $this->entityManager->getRepository(User::class)->find($request->getUserId());
            if (null === $clubEmail || !$requester instanceof User) {
                $lost[$club->getId()] = $club->getName();

                continue;
            }
            $request->setClubEmail($clubEmail);
            $this->entityManager->flush();
            $this->approvalService->sendApprovalEmail($request, $requester);
        }

        return array_values($lost);
    }

    /** @param list<string> $clubNames */
    private function alertSupport(array $clubNames): void
    {
        $connection = $this->managerRegistry->getConnection('admin');
        \assert($connection instanceof Connection);
        $recipients = array_values(array_filter(
            $connection->fetchFirstColumn('SELECT email FROM super_admin WHERE enabled = TRUE ORDER BY email'),
            static fn (mixed $email): bool => \is_string($email) && '' !== $email,
        ));
        if ([] === $recipients) {
            return;
        }
        // Console en URL ABSOLUE, sur sa propre ligne (rendue en bouton par le gabarit HTML).
        $consoleLink = rtrim($this->frontendBaseUrl, '/') . '/admin';
        $email = (new Email)
            ->from($this->mailFrom->address())
            ->subject(\sprintf('Demandes de reprise sans adresse officielle — %d club(s)', \count($clubNames)))
            ->text("Ces clubs sans gestionnaire ont une demande de reprise que personne ne peut valider faute d'adresse officielle connue :\n\n- "
                . implode("\n- ", $clubNames)
                . "\n\nValidez-les depuis la console :\n" . $consoleLink);
        $headers = $email->getHeaders();
        $headers->addTextHeader(EmailTemplateRenderer::CTA_URL_HEADER, $consoleLink);
        $headers->addTextHeader(EmailTemplateRenderer::CTA_LABEL_HEADER, 'Ouvrir la console');
        foreach ($recipients as $recipient) {
            $email->addTo($recipient);
        }
        try {
            $this->mailer->send($email);
        } catch (Throwable $e) {
            $this->logger->warning('Takeover requests support alert failed', ['error' => $e->getMessage()]);
        }
    }
}
