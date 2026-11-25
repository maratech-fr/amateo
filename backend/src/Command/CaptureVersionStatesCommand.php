<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Plans\PlanStates;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * La conversion du lot 5 : donne à chaque version GARDÉE (validée — `chosen_schedule_id` d'un plan — ou chargée —
 * `lock_source_schedule_id`) née AVANT la migration qui crée `schedule_state` une copie du froid de son plan, prise
 * sur l'état vivant du jour : après la conversion, aucun plan n'est « en attente ». Jouée UNE fois par la procédure
 * manuelle du lot, site fermé ; rien d'automatique ne la lance. Rejouer n'écrit rien de plus (une version qui a sa
 * copie est sautée). Une version née APRÈS la migration et sans copie n'est jamais réparée : sa copie devait naître
 * avec elle.
 *
 * `--check` n'écrit rien et échoue dès qu'une version gardée, née quand elle veut, n'a pas sa copie : la procédure
 * s'arrête, le site ne rouvre pas.
 *
 * « Née avant » : `schedule.created_at` antérieur à `doctrine_migration_versions.executed_at` de la migration, lu sur
 * la connexion d'administration (le rôle applicatif ne lit pas cette table).
 */
#[AsCommand(
    name: 'app:plans:capture-version-states',
    description: 'Give every validated or loaded schedule version born before the schedule_state migration a copy of its plan state. Run once by the release procedure; --check only verifies.',
)]
final class CaptureVersionStatesCommand extends Command
{
    private const string MIGRATION = 'DoctrineMigrations\Version20261201120000';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly PlanStates $planStates,
        #[Autowire(service: 'doctrine.dbal.admin_connection')]
        private readonly Connection $adminConnection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('check', null, InputOption::VALUE_NONE, 'Write nothing; fail when a validated or loaded version has no copy.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $check = (bool) $input->getOption('check');

        $executedAt = null;
        if (!$check) {
            $raw = $this->adminConnection->fetchOne('SELECT executed_at FROM doctrine_migration_versions WHERE version = ?', [self::MIGRATION]);
            if (!\is_string($raw)) {
                $io->error('The schedule_state migration has not run: nothing to capture.');

                return Command::FAILURE;
            }
            $executedAt = new DateTimeImmutable($raw)->format(\DATE_ATOM);
        }

        $clubIds = array_map(
            static fn (Club $club): string => $club->getId(),
            $this->entityManager->getRepository(Club::class)->findAll(),
        );
        $this->entityManager->clear();

        $captured = 0;
        $missing = [];
        foreach ($clubIds as $clubId) {
            $this->tenantConnectionContext->setClubId($clubId);
            try {
                foreach ($this->keptVersionsWithoutCopy($clubId, $executedAt) as $version) {
                    if ($check || !$version['bornBefore']) {
                        $missing[] = $version['id'];

                        continue;
                    }
                    $this->capture($version['id'], $version['planId']);
                    ++$captured;
                }
            } finally {
                $this->entityManager->clear();
                $this->tenantConnectionContext->clear();
            }
        }

        if ($check) {
            if ([] !== $missing) {
                $io->error(\sprintf("%d validated or loaded version(s) without a copy:\n  - %s", \count($missing), implode("\n  - ", $missing)));

                return Command::FAILURE;
            }
            $io->success('Every validated or loaded version has its copy.');

            return Command::SUCCESS;
        }

        if ([] !== $missing) {
            $io->warning(\sprintf("%d version(s) born after the migration have no copy and are never repaired:\n  - %s", \count($missing), implode("\n  - ", $missing)));
        }
        $io->success(\sprintf('%d version(s) captured.', $captured));

        return Command::SUCCESS;
    }

    /**
     * Les versions gardées du club (validées ou chargées) sans copie, et si chacune est née avant la migration
     * (jamais, sans heure de migration : `--check`).
     *
     * @return list<array{id: string, planId: string, bornBefore: bool}>
     */
    private function keptVersionsWithoutCopy(string $clubId, ?string $executedAt): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT DISTINCT s.id, s.schedule_plan_id, s.created_at < CAST(:executedAt AS timestamptz) AS born_before
             FROM schedule s
             JOIN schedule_plan p ON s.id = p.chosen_schedule_id OR s.id = p.lock_source_schedule_id
             WHERE s.club_id = :clubId
               AND NOT EXISTS (SELECT 1 FROM schedule_state st WHERE st.schedule_id = s.id)
             ORDER BY s.id',
            ['clubId' => $clubId, 'executedAt' => $executedAt],
        );

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'planId' => (string) $row['schedule_plan_id'],
            'bornBefore' => true === $row['born_before'],
        ], $rows);
    }

    private function capture(string $versionId, string $planId): void
    {
        $version = $this->entityManager->getRepository(Schedule::class)->find($versionId);
        if (!$version instanceof Schedule) {
            return;
        }
        $this->planStates->store($version, $this->planStates->capture($planId));
    }
}
