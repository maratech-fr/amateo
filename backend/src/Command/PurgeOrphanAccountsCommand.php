<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Enum\AuditAction;
use App\Service\AccountErasureService;
use App\Service\AuditTrail;
use App\Service\OrphanAccountMailBuilder;
use App\Service\OrphanAccountNotifier;
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
 * P4-301 — un compte qui a perdu son dernier club est supprimé au bout de 30 jours.
 *
 * Deux étages (patron PurgeInactiveUsersCommand) :
 *  - étage 1 : tout compte ORPHELIN sans stamp → mail de préavis + stamp
 *    (couvre le STOCK au déploiement : mail PUIS 30 j, JAMAIS supprimé sans mail) ;
 *  - étage 2 : stamp vieux d'au moins 30 j → REVALIDE isOrphan sur un fetch frais →
 *    anonymisation via AccountErasureService (même routine que DELETE /api/me) +
 *    ligne d'audit (raison `orphaned`).
 *
 * Orphelin = plus aucune adhésion active, pending ni demande de création VIVANTE
 * (prédicat unique OrphanAccountNotifier::isOrphan). Les comptes de démonstration
 * et anonymisés sont écartés par le prédicat. PAS de rappel J-7.
 *
 * Tourne au cron-runner (quotidien). Horloge applicative (SimulatedClock en dev).
 */
#[AsCommand(
    name: 'app:users:purge-orphaned',
    description: 'Warn accounts that lost their last club, then anonymize them 30 days after a successful notice.',
)]
final class PurgeOrphanAccountsCommand extends Command
{
    // Miroir de OrphanAccountNotifier::GRACE_PERIOD (+30 j) côté purge : un stamp vieux
    // d'au moins 30 j est échu. L'échéance annoncée dans le mail = stamp + 30 j.
    private const ERASE_AFTER = '-30 days';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private readonly OrphanAccountNotifier $orphanAccountNotifier,
        private readonly OrphanAccountMailBuilder $mailBuilder,
        private readonly AccountErasureService $accountErasureService,
        private readonly MailerInterface $mailer,
        private readonly ClockInterface $clock,
        private readonly ManagerRegistry $managerRegistry,
        private readonly AuditTrail $auditTrail,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List notices/erasures without sending or deleting anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $now = DateTimeImmutable::createFromInterface($this->clock->now());

        [$warned, $warnFailed] = $this->warn($io, $now, $dryRun);
        [$erased, $eraseFailed] = $this->erase($io, $now, $dryRun);

        $io->success(\sprintf(
            '%d notice(s), %d anonymization(s)%s.',
            $warned,
            $erased,
            $dryRun ? ' (dry-run)' : '',
        ));

        return ($warnFailed || $eraseFailed) ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return array{int, bool} [processed, hadFailure] */
    private function warn(SymfonyStyle $io, DateTimeImmutable $now, bool $dryRun): array
    {
        // Pré-filtre (borne le balayage) : comptes non anonymisés, sans stamp, non démo
        // (SEC-28 : drapeau is_demo, plus la comparaison d'adresse), et SANS adhésion
        // active. Le prédicat complet isOrphan (pending + demande vivante) est revérifié
        // en PHP ci-dessous sur un fetch frais.
        $users = $this->entityManager->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.anonymizedAt IS NULL')
            ->andWhere('u.orphanNoticeSentAt IS NULL')
            ->andWhere('u.isDemo = false')
            ->andWhere('NOT EXISTS (SELECT cu.id FROM App\\Entity\\ClubUser cu WHERE cu.userId = u.id AND cu.isActive = true)')
            ->getQuery()
            ->getResult();
        // Ids d'abord : après un resetManager (échec d'un flush), les entités déjà
        // hydratées seraient détachées — chaque itération refetch frais.
        $userIds = array_map(static fn (User $u): string => $u->getId(), $users);
        $this->entityManager->clear();

        $count = 0;
        $failed = false;
        foreach ($userIds as $userId) {
            $user = $this->entityManager->find(User::class, $userId);
            if (!$user instanceof User || !$this->orphanAccountNotifier->isOrphan($user)) {
                continue;
            }
            $io->writeln(\sprintf('  %s notify %s', $dryRun ? '<comment>would</comment>' : '<info>✉</info>', $user->getEmail()));
            if ($dryRun) {
                ++$count;
                continue;
            }
            try {
                $deadline = $now->modify(OrphanAccountNotifier::GRACE_PERIOD);
                $this->mailer->send($this->mailBuilder->buildAccessRemoved($user->getEmail(), $user->getFirstName(), $deadline));
                $user->setOrphanNoticeSentAt($now);
                $this->entityManager->flush();
                // Compté seulement si le préavis est réellement émis ET persisté.
                ++$count;
            } catch (Throwable $e) {
                // Échec d'envoi → PAS de stamp (sinon la suppression partirait sans mail).
                $failed = true;
                $io->warning(\sprintf('Notice to %s failed: %s', $user->getEmail(), $e->getMessage()));
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
            ->andWhere('u.orphanNoticeSentAt IS NOT NULL')
            ->andWhere('u.orphanNoticeSentAt <= :deadline')
            ->setParameter('deadline', $now->modify(self::ERASE_AFTER))
            ->getQuery()
            ->getResult();
        $userIds = array_map(static fn (User $u): string => $u->getId(), $users);
        $this->entityManager->clear();

        $count = 0;
        $failed = false;
        foreach ($userIds as $userId) {
            $user = $this->entityManager->find(User::class, $userId);
            if (!$user instanceof User) {
                continue;
            }
            // REVALIDATION à l'échéance sur un fetch frais : un compte qui a regagné un
            // accès a vu son stamp remis à null (cancelFor) — défense en profondeur, on
            // re-vérifie et on nettoie un stamp résiduel plutôt que de supprimer à tort.
            if (!$this->orphanAccountNotifier->isOrphan($user)) {
                $io->writeln(\sprintf('  <info>↺</info> %s is no longer orphaned — erasure skipped', $user->getEmail()));
                if (!$dryRun) {
                    $user->setOrphanNoticeSentAt(null);
                    $this->entityManager->flush();
                }
                continue;
            }
            $io->writeln(\sprintf('  %s anonymize %s (notified %s)', $dryRun ? '<comment>would</comment>' : '<info>✓</info>', $user->getEmail(), $user->getOrphanNoticeSentAt()?->format('Y-m-d') ?? '?'));
            if (!$dryRun) {
                try {
                    $this->accountErasureService->erase($user);
                    $this->auditTrail->record(AuditAction::ACCOUNT_ERASED, null, null, 'User', $userId, ['reason' => 'orphaned']);
                } catch (Throwable $e) {
                    $failed = true;
                    $io->warning(\sprintf('Erasure of %s failed: %s', $userId, $e->getMessage()));
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
