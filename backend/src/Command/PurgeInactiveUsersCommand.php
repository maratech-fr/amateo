<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Enum\AuditAction;
use App\Service\AccountErasureService;
use App\Service\AuditTrail;
use App\Service\InactivityMailBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Throwable;

/**
 * RGPD — rétention des comptes (politique : inactifs 2 ans).
 *
 * Inactivité = COALESCE(lastLoginAt, createdAt). Deux étages :
 * - 23 mois → email de PRÉAVIS (inactivityWarnedAt posé, annulé par un login) ;
 * - 24 mois ET préavis envoyé depuis ≥ 1 MOIS (la promesse de l'email) →
 *   ANONYMISATION via
 *   AccountErasureService (même routine que DELETE /api/me : memberships
 *   désactivés, club orphelin programmé à +30 j).
 * Le garde-fou « préavis ≥ 1 mois » garantit qu'un compte n'est JAMAIS effacé
 * sans avoir été prévenu, même si le cron est resté down des semaines.
 *
 * Tourne au cron-runner (quotidien). Horloge applicative (SimulatedClock en dev).
 */
#[AsCommand(
    name: 'app:users:purge-inactive',
    description: 'RGPD retention: warn accounts inactive 23 months, anonymize at 24 months (warning ≥1 month old required).',
)]
final class PurgeInactiveUsersCommand extends Command
{
    private const WARN_AFTER = '-23 months';
    private const ERASE_AFTER = '-24 months';
    // « anonymisé dans un mois » (email de préavis) : le garde DOIT matcher la
    // promesse faite à la personne concernée — revue PR-3.
    private const MIN_WARNING_AGE = '-1 month';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private readonly AccountErasureService $accountErasureService,
        private readonly InactivityMailBuilder $mailBuilder,
        private readonly MailerInterface $mailer,
        private readonly ClockInterface $clock,
        private readonly ManagerRegistry $managerRegistry,
        private readonly AuditTrail $auditTrail,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List warnings/erasures without sending or deleting anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $now = DateTimeImmutable::createFromInterface($this->clock->now());

        [$warned, $warnFailed] = $this->warn($io, $now, $dryRun);
        [$erased, $eraseFailed] = $this->erase($io, $now, $dryRun);

        $io->success(\sprintf(
            '%d warning(s), %d anonymization(s)%s.',
            $warned,
            $erased,
            $dryRun ? ' (dry-run)' : '',
        ));

        return ($warnFailed || $eraseFailed) ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return array{int, bool} [processed, hadFailure] */
    private function warn(SymfonyStyle $io, DateTimeImmutable $now, bool $dryRun): array
    {
        $users = $this->entityManager->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.anonymizedAt IS NULL')
            ->andWhere('u.inactivityWarnedAt IS NULL')
            // P4-304 — les comptes de DÉMONSTRATION sont hors rétention RGPD : ils vivent
            // à une horloge simulée (souvent une date passée) qui les ferait paraître
            // inactifs depuis « 25 mois » dès leur création. Modèle PurgeOrphanAccountsCommand.
            ->andWhere('u.isDemo = false')
            ->andWhere('COALESCE(u.lastLoginAt, u.createdAt) < :threshold')
            ->setParameter('threshold', $now->modify(self::WARN_AFTER))
            ->getQuery()
            ->getResult();
        // Ids d'abord : après un resetManager (échec d'un flush), les entités
        // déjà hydratées seraient détachées — chaque itération refetch frais.
        $userIds = array_map(static fn (User $u): string => $u->getId(), $users);
        $this->entityManager->clear();

        $count = 0;
        $failed = false;
        foreach ($userIds as $userId) {
            $user = $this->entityManager->find(User::class, $userId);
            if (!$user instanceof User) {
                continue;
            }
            $io->writeln(\sprintf('  %s warn %s (inactive since %s)', $dryRun ? '<comment>would</comment>' : '<info>✉</info>', $user->getEmail(), ($user->getLastLoginAt() ?? $user->getCreatedAt())->format('Y-m-d')));
            if ($dryRun) {
                ++$count;
                continue;
            }
            try {
                $this->mailer->send($this->mailBuilder->build($user->getEmail(), $user->getFirstName()));
                $user->setInactivityWarnedAt($now);
                $this->entityManager->flush();
                // Compté seulement si le préavis est réellement émis ET persisté
                // (un échec compté « warned » masquerait une panne SMTP au log).
                ++$count;
            } catch (Throwable $e) {
                // Échec d'envoi → PAS de warnedAt (sinon l'anonymisation
                // partirait sans que le préavis ait réellement été émis).
                $failed = true;
                $io->warning(\sprintf('Warning to %s failed: %s', $user->getEmail(), $e->getMessage()));
                // Un flush raté FERME l'EntityManager : sans reset, tous les
                // users suivants recevraient l'email SANS persistance du
                // préavis → doublons à chaque heure (revue PR-3).
                $this->resetEntityManagerIfClosed();
            }
        }

        return [$count, $failed];
    }

    /** @return array{int, bool} [processed, hadFailure] */
    private function erase(SymfonyStyle $io, DateTimeImmutable $now, bool $dryRun): array
    {
        $users = $this->entityManager->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.anonymizedAt IS NULL')
            // P4-304 — défense en profondeur jumelle de warn() : un compte démo (horloge
            // simulée) ne doit jamais être anonymisé pour « inactivité ». Modèle
            // PurgeOrphanAccountsCommand.
            ->andWhere('u.isDemo = false')
            ->andWhere('COALESCE(u.lastLoginAt, u.createdAt) < :threshold')
            ->andWhere('u.inactivityWarnedAt IS NOT NULL')
            ->andWhere('u.inactivityWarnedAt < :minWarningAge')
            ->setParameter('threshold', $now->modify(self::ERASE_AFTER))
            ->setParameter('minWarningAge', $now->modify(self::MIN_WARNING_AGE))
            ->getQuery()
            ->getResult();
        // Ids d'abord : après un resetManager (échec précédent), les entités
        // déjà hydratées seraient détachées — chaque itération refetch frais.
        $userIds = array_map(static fn (User $u): string => $u->getId(), $users);
        $this->entityManager->clear();

        $count = 0;
        $failed = false;
        foreach ($userIds as $userId) {
            $user = $this->entityManager->find(User::class, $userId);
            if (!$user instanceof User) {
                continue;
            }
            $io->writeln(\sprintf('  %s anonymize %s (warned %s)', $dryRun ? '<comment>would</comment>' : '<info>✓</info>', $user->getEmail(), $user->getInactivityWarnedAt()?->format('Y-m-d') ?? '?'));
            if (!$dryRun) {
                try {
                    $this->accountErasureService->erase($user);
                    $this->auditTrail->record(AuditAction::ACCOUNT_ERASED, null, null, 'User', $userId, ['reason' => 'inactivity']);
                } catch (Throwable $e) {
                    $failed = true;
                    $io->warning(\sprintf('Erasure of %s failed: %s', $userId, $e->getMessage()));
                    // Un flush raté FERME l'EntityManager (leçon PR-1) : sans
                    // reset, tous les users suivants échoueraient en cascade.
                    $this->resetEntityManagerIfClosed();
                    continue;
                }
            }
            ++$count;
        }

        return [$count, $failed];
    }

    private function resetEntityManagerIfClosed(): void
    {
        if ($this->entityManager->isOpen()) {
            return;
        }
        $this->managerRegistry->resetManager();
        $manager = $this->managerRegistry->getManager();
        \assert($manager instanceof EntityManagerInterface);
        $this->entityManager = $manager;
    }
}
