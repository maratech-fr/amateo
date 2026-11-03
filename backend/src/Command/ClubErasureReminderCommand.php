<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Mail\ClubMailMetadata;
use App\Mail\EmailTemplateRenderer;
use App\Repository\ClubUserRepository;
use App\Service\Basketball\FfbbClubDirectory;
use App\Service\MailFrom;
use App\Service\MembershipRequestMailBuilder;
use App\Service\ProductIdentity;
use App\Service\TenantConnectionContext;
use Closure;
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
 * Rappel J-7 avant la suppression définitive d'un club orphelin (RGPD). Prévient le
 * contact officiel que l'échéance approche, pour qu'un gestionnaire reprenne le club
 * à temps (inscription sur le code FFBB → approbation → reprise).
 *
 * Cron quotidien (cron-runner). Fenêtre [échéance-7j, échéance[ : un club dont la
 * suppression est programmée dans 7 jours ou moins, pas encore rappelé. Idempotent
 * via `club.erasure_reminder_sent_at` (un seul rappel par échéance ; une (re)programmation
 * remet le stamp à null). Ne touche RIEN d'autre : c'est un nudge, la purge reste le
 * geste de `app:clubs:purge-erased`. Un club dont un GESTIONNAIRE actif est revenu est SAUTÉ
 * (l'effacement sera annulé par la purge). Le rappel part aux Membres restants D'ABORD, puis au
 * mail institutionnel FFBB (même ancre que l'approbation), repli `Club.contactEmail` ; sans
 * aucun destinataire, rien à envoyer. Le stamp n'est posé qu'après au moins un envoi accepté.
 */
#[AsCommand(
    name: 'app:clubs:erasure-remind',
    description: 'Email the official contact of orphaned clubs whose definitive deletion is 7 days away or less (RGPD nudge; never auto-acts).',
)]
final class ClubErasureReminderCommand extends Command
{
    private const WINDOW_DAYS = 7;

    private bool $hadSendFailure = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly MembershipRequestMailBuilder $membershipMailBuilder,
        private readonly FfbbClubDirectory $ffbbClubDirectory,
        private readonly MailerInterface $mailer,
        private readonly MailFrom $mailFrom,
        private readonly ProductIdentity $productIdentity,
        private readonly ClockInterface $clock,
        // Origine navigateur pour le lien d'inscription (reprendre le club) — bind global
        // `$frontendBaseUrl` (%env(FRONTEND_BASE_URL)%) ; vide → lien omis.
        private readonly string $frontendBaseUrl = '',
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
        $this->hadSendFailure = false;
        $dryRun = (bool) $input->getOption('dry-run');

        $dateOption = $input->getOption('date');
        if (\is_string($dateOption) && '' !== $dateOption) {
            $now = $this->resolveDate($dateOption);
            if (!$now instanceof DateTimeImmutable) {
                $io->error('Invalid --date: expected a real calendar date YYYY-MM-DD.');

                return Command::FAILURE;
            }
        } else {
            $now = DateTimeImmutable::createFromInterface($this->clock->now());
        }
        $windowEnd = $now->modify(\sprintf('+%d days', self::WINDOW_DAYS));

        // Clubs orphelins dont l'échéance tombe dans les 7 jours (ou moins), pas encore
        // rappelés. `club` est une table globale (hors RLS) — lecture directe.
        $due = $this->entityManager->getRepository(Club::class)->createQueryBuilder('c')
            ->where('c.erasureScheduledAt IS NOT NULL')
            ->andWhere('c.erasureReminderSentAt IS NULL')
            ->andWhere('c.erasureScheduledAt > :now')
            ->andWhere('c.erasureScheduledAt <= :windowEnd')
            ->setParameter('now', $now)
            ->setParameter('windowEnd', $windowEnd)
            ->getQuery()
            ->getResult();
        $this->entityManager->clear();

        $sent = 0;
        foreach ($due as $club) {
            \assert($club instanceof Club);
            $clubId = $club->getId();
            try {
                $sent += $this->remindClub($clubId, $now, $dryRun, $io);
            } catch (Throwable $e) {
                $this->hadSendFailure = true;
                $io->warning(\sprintf('Club %s skipped: %s', $clubId, $e->getMessage()));
            } finally {
                $this->entityManager->clear();
                $this->tenantConnectionContext->clear();
            }
        }

        $io->success(\sprintf('%d erasure reminder(s) %s.', $sent, $dryRun ? 'detected (dry-run)' : 'sent'));

        return $this->hadSendFailure ? Command::FAILURE : Command::SUCCESS;
    }

    private function remindClub(string $clubId, DateTimeImmutable $now, bool $dryRun, SymfonyStyle $io): int
    {
        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        if (!$club instanceof Club) {
            return 0;
        }
        $deadline = $club->getErasureScheduledAt();
        // Revalidation : la programmation a pu être annulée/déjà rappelée entre la
        // sélection et ici, ou l'échéance a glissé hors fenêtre.
        if (!$deadline instanceof DateTimeImmutable
            || $club->getErasureReminderSentAt() instanceof DateTimeImmutable
            || $deadline <= $now
            || $deadline > $now->modify(\sprintf('+%d days', self::WINDOW_DAYS))) {
            return 0;
        }
        // Un gestionnaire actif est revenu → l'espace est repris : pas d'alarme (la purge
        // annulera la programmation). Un Membre seul ne retient pas le club : il est prévenu.
        if ($this->clubUserRepository->hasActiveManager($clubId)) {
            return 0;
        }

        // Destinataires : les Membres restants D'ABORD, puis l'adresse officielle — l'absence
        // d'adresse officielle ne prive jamais les Membres de leur rappel.
        $members = $this->clubUserRepository->findActiveMemberRecipients($clubId);
        $ffbbCode = $club->getFfbbClubCode();
        $official = (null !== $ffbbCode ? $this->ffbbClubDirectory->lookupClubEmail($ffbbCode) : null) ?? $club->getContactEmail();
        if ('' === $official) {
            $official = null;
        }
        if ([] === $members && null === $official) {
            return 0; // Aucun destinataire connu : rien à envoyer (le superadmin voit le cas).
        }

        if ($dryRun) {
            $io->writeln(\sprintf('  <comment>would remind</comment> %s (%s) → suppression le %s → %d membre(s)%s', $club->getName(), $clubId, $deadline->format('d/m/Y'), \count($members), null !== $official ? ' + ' . $official : ''));

            return 1;
        }

        $delivered = 0;
        foreach ($members as $member) {
            $delivered += $this->send(
                fn (): Email => $this->membershipMailBuilder->buildClubClosing($member['email'], $club, $member['firstName'], $deadline, true),
                $member['email'],
                $io,
            );
        }
        if (null !== $official) {
            $delivered += $this->send(fn (): Email => $this->officialReminder($official, $club, $deadline), $official, $io);
        }

        // Stamp posé dès qu'au moins UN envoi a été accepté (le bus accepte tout ou rien à
        // l'enfilage) ; zéro réussite → pas de stamp, nouvel essai demain vers tous — et la
        // purge reste retenue tant que ce stamp manque.
        if (0 === $delivered) {
            return 0;
        }
        $club->setErasureReminderSentAt($now);
        $this->entityManager->flush();

        return 1;
    }

    /**
     * @param Closure(): Email $build
     *
     * @return int 1 si le mail est enfilé, 0 sinon (échec tracé)
     */
    private function send(Closure $build, string $to, SymfonyStyle $io): int
    {
        try {
            $this->mailer->send($build());

            return 1;
        } catch (Throwable $e) {
            $this->hadSendFailure = true;
            $io->warning(\sprintf('Email to %s failed: %s', $to, $e->getMessage()));

            return 0;
        }
    }

    /** Le rappel J-7 au contact officiel (texte inchangé). */
    private function officialReminder(string $to, Club $club, DateTimeImmutable $deadline): Email
    {
        $product = $this->productIdentity->name();
        // Lien d'inscription (reprendre le club) sur sa propre ligne, omis sans base front.
        $registerLink = '' !== $this->frontendBaseUrl ? rtrim($this->frontendBaseUrl, '/') . '/register' : null;
        $registerBlock = null !== $registerLink ? "\n\nInscrire un gestionnaire :\n" . $registerLink : '';
        $email = (new Email)
            ->from($this->mailFrom->address())
            ->to($to)
            ->subject(\sprintf('Rappel — l\'espace %s du club %s sera supprimé le %s', $product, $club->getName(), $deadline->format('d/m/Y')))
            ->text(\sprintf(
                "Bonjour,\n\nL'espace {$product} du club %s n'a toujours pas de gestionnaire et sera supprimé DÉFINITIVEMENT avec toutes ses données le %s.\n\nPour le conserver, un gestionnaire du club doit s'inscrire sur {$product} avec le code FFBB du club : sa demande vous sera soumise pour approbation.%s\n\n{$product}",
                $club->getName(),
                $deadline->format('d/m/Y'),
                $registerBlock,
            ));
        // Bouton CTA du HTML (D1) : s'inscrire pour reprendre le club (seulement si lien cliquable).
        if (null !== $registerLink) {
            $headers = $email->getHeaders();
            $headers->addTextHeader(EmailTemplateRenderer::CTA_URL_HEADER, $registerLink);
            $headers->addTextHeader(EmailTemplateRenderer::CTA_LABEL_HEADER, 'Inscrire un gestionnaire');
        }
        // Identité du club (logo + nom court) sur la carte d'e-mail (D1).
        ClubMailMetadata::mark($email, $club);

        return $email;
    }

    /** Strict: a real calendar date (rejects rollovers like 2026-02-30), else null. */
    private function resolveDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (false === $date || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }
}
