<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Repository\ClubUserRepository;
use App\Service\ErasedClubPurger;
use App\Service\Registration\ClubWinBackService;
use App\Service\TenantConnectionContext;
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
use Throwable;

/**
 * RGPD — exécute la purge des workspaces dont le délai de grâce est échu
 * (Club.erasureScheduledAt <= maintenant, posé par AccountErasureService quand
 * le dernier gestionnaire s'est effacé). Retenue tant que le rappel J-7 n'est pas
 * parti, revalidée sous le verrou d'approbation du club. Tourne au cron-runner (quotidien) ;
 * chaque club sous son propre GUC, un échec n'en bloque pas un autre (pattern
 * PurgeSeasonsCommand). L'identité publique FFBB survit (ErasedClubPurger).
 */
#[AsCommand(
    name: 'app:clubs:purge-erased',
    description: 'Purge the workspace of clubs whose erasure grace period has elapsed (RGPD). FFBB identity survives.',
)]
final class PurgeErasedClubsCommand extends Command
{
    /** Report d'une purge retenue faute de rappel J-7 : le rappel repart sur la nouvelle échéance. */
    private const string HOLD_DELAY = '+7 days';

    private bool $hadFailure = false;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly ErasedClubPurger $erasedClubPurger,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly ClubWinBackService $clubWinBack,
        private readonly ClockInterface $clock,
        private readonly ManagerRegistry $managerRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be purged without deleting anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->hadFailure = false;
        $dryRun = (bool) $input->getOption('dry-run');
        $now = DateTimeImmutable::createFromInterface($this->clock->now());

        $due = $this->entityManager->getRepository(Club::class)->createQueryBuilder('c')
            ->where('c.erasureScheduledAt IS NOT NULL')
            ->andWhere('c.erasureScheduledAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
        $this->entityManager->clear();

        $purged = 0;
        $held = 0;
        foreach ($due as $club) {
            \assert($club instanceof Club);
            $clubId = $club->getId();
            try {
                if ($dryRun) {
                    $io->writeln(\sprintf('  <comment>would</comment> purge workspace of club %s (%s, scheduled %s)', $club->getName(), $clubId, $club->getErasureScheduledAt()?->format('Y-m-d') ?? '?'));
                    ++$purged;
                    continue;
                }

                $this->tenantConnectionContext->setClubId($clubId);
                $result = $this->entityManager->wrapInTransaction(fn (): string => $this->purgeUnderApprovalLock($clubId, $now, $io));
                if ('purged' === $result) {
                    ++$purged;
                } elseif ('held' === $result) {
                    ++$held;
                }
            } catch (Throwable $e) {
                $this->hadFailure = true;
                $io->warning(\sprintf('Club %s skipped: %s', $clubId, $e->getMessage()));
            } finally {
                // Un flush raté FERME l'EntityManager : sans reset, tous les
                // clubs suivants échoueraient en « EntityManager is closed ».
                if (!$this->entityManager->isOpen()) {
                    $this->managerRegistry->resetManager();
                    $manager = $this->managerRegistry->getManager();
                    \assert($manager instanceof EntityManagerInterface);
                    $this->entityManager = $manager;
                }
                $this->entityManager->clear();
                $this->tenantConnectionContext->clear();
            }
        }

        $io->success(\sprintf('%d club workspace(s) %s, %d held (J-7 reminder never sent).', $purged, $dryRun ? 'would be purged (dry-run)' : 'purged', $held));

        return $this->hadFailure ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Sous le verrou d'approbation du club (`club-approval:<code>`, celui que prend une reprise en
     * cours) : relit le club, puis — dans cet ordre — saute une programmation annulée/glissée,
     * ANNULE si un gestionnaire actif est revenu, RETIENT la purge si le rappel J-7 n'est jamais
     * parti (échéance repoussée à +7 j, rappel remis à zéro : le rappel repart, la purge suivra —
     * jamais retenue pour toujours), sinon purge.
     *
     * @return 'purged'|'held'|'cancelled'|'skipped'
     */
    private function purgeUnderApprovalLock(string $clubId, DateTimeImmutable $now, SymfonyStyle $io): string
    {
        $freshClub = $this->entityManager->getRepository(Club::class)->find($clubId);
        if (!$freshClub instanceof Club) {
            return 'skipped';
        }
        $ffbbCode = $freshClub->getFfbbClubCode();
        if (null !== $ffbbCode) {
            $this->entityManager->getConnection()->executeStatement(
                'SELECT pg_advisory_xact_lock(hashtext(:key))',
                ['key' => 'club-approval:' . $ffbbCode],
            );
            // Relu APRÈS le verrou : une reprise qui le tenait a pu rendre un gestionnaire au club.
            $this->entityManager->refresh($freshClub);
        }

        // REVALIDATION à l'échéance (revue sécurité PR-1) : la liste due a pu vieillir — la
        // programmation a pu être annulée entre temps, ou un gestionnaire actif est revenu.
        $scheduledAt = $freshClub->getErasureScheduledAt();
        if (!$scheduledAt instanceof DateTimeImmutable || $scheduledAt > $now) {
            return 'skipped';
        }
        if ($this->clubUserRepository->hasActiveManager($clubId)) {
            $io->writeln(\sprintf('  <info>↺</info> club %s has an active manager again — erasure cancelled', $clubId));
            $this->clubWinBack->cancelClosure($freshClub, null, 'purge_revalidation');

            return 'cancelled';
        }
        // Jamais de destruction sans préavis : sans rappel J-7 réellement parti, la purge est
        // RETENUE et l'échéance repoussée de 7 jours (le rappel annoncera la nouvelle date).
        if (!$freshClub->getErasureReminderSentAt() instanceof DateTimeImmutable) {
            $freshClub->setErasureScheduledAt($now->modify(self::HOLD_DELAY));
            $freshClub->setErasureReminderSentAt(null);
            $io->warning(\sprintf('Club %s purge held: the J-7 reminder was never sent — rescheduled to %s', $clubId, $now->modify(self::HOLD_DELAY)->format('Y-m-d')));

            return 'held';
        }

        $io->writeln(\sprintf('  <info>✓</info> purge workspace of club %s (%s, scheduled %s)', $freshClub->getName(), $clubId, $scheduledAt->format('Y-m-d')));
        $this->erasedClubPurger->purge($freshClub);

        return 'purged';
    }
}
