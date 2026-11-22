<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\Season;
use App\Enum\AuditAction;
use App\Service\AuditTrail;
use App\Service\SeasonDataPurger;
use App\Service\SeasonResolver;
use App\Service\TenantConnectionContext;
use App\Structure\UnusedIdentitySweeper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SA4 — action support : vide la SAISON COURANTE d'un club (structure, calendrier,
 * plannings) en GARDANT la ligne Season et le club — il repart au wizard. Miroir
 * CLI de ResetSeasonController (la référence sémantique du « reset club ») : même
 * SeasonDataPurger, deleteSeasonRow: false, audit SEASON_RESET (acteur null = CLI/
 * superadmin — l'acteur console est tracé par l'audit SA0 et admin_job_run).
 * GUC posé via TenantConnectionContext (pattern PurgeSeasonsCommand) : la purge
 * tourne sous RLS, jamais en bypass.
 */
#[AsCommand(
    name: 'app:clubs:reset-season',
    description: 'Wipe a club\'s CURRENT season data (Season row and club survive — back to the wizard). Support action.',
)]
final class ResetClubSeasonCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly SeasonResolver $seasonResolver,
        private readonly SeasonDataPurger $seasonDataPurger,
        private readonly UnusedIdentitySweeper $unusedIdentitySweeper,
        private readonly AuditTrail $auditTrail,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('club', null, InputOption::VALUE_REQUIRED, 'Target club id (required).');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Announce the target season without deleting anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $clubId = $input->getOption('club');
        if (!\is_string($clubId) || '' === $clubId) {
            $io->error('--club <id> is required.');

            return Command::FAILURE;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            // Lookup via l'EM runtime (pattern PurgeSeasonsCommand) : la table club n'a
            // pas de RLS, et une seule porte (runtime) pour toute la commande garde le
            // monde cohérent — y compris sous le wrapper transactionnel des tests.
            $club = $this->entityManager->getRepository(Club::class)->find($clubId);
            if (!$club instanceof Club) {
                $io->error(\sprintf('Club %s not found.', $clubId));

                return Command::FAILURE;
            }

            $this->tenantConnectionContext->setClubId($clubId);
            // Saison courante = la MÊME résolution que toutes les surfaces de l'app :
            // currentSeason() est CLOCK-AWARE (simulateur de date compris) — la version
            // statique prendrait l'horloge murale et pourrait viser une autre saison que
            // celle que la console affiche (revue SA4, finding 0).
            $current = $this->seasonResolver->currentSeason($clubId);
            if (!$current instanceof Season) {
                $io->error(\sprintf('Club %s has no current season — nothing to reset.', $clubId));

                return Command::FAILURE;
            }

            if ($dryRun) {
                $io->success(\sprintf('[dry-run] Would wipe season %s (%s) of club %s — Season row kept.', $current->getName(), $current->getId(), $clubId));

                return Command::SUCCESS;
            }

            $deleted = $this->seasonDataPurger->purge($clubId, $current->getId(), deleteSeasonRow: false);
            // Les fiches de personne que plus aucun rôle ne désigne partent avec la saison remise à zéro.
            $this->unusedIdentitySweeper->sweep($clubId);
            $this->auditTrail->record(AuditAction::SEASON_RESET, null, $clubId, 'Season', $current->getId(), ['rowsDeleted' => $deleted, 'source' => 'cli']);
            $io->success(\sprintf('Season %s of club %s wiped (%d rows) — Season row kept, the club restarts at the wizard.', $current->getName(), $clubId, $deleted));

            return Command::SUCCESS;
        } finally {
            // GUC d'abord : si em->clear() levait, le GUC resterait posé pour la suite
            // de la requête admin (exécution in-process, exception SA0 documentée dans
            // console-superadmin.md — le controller a une ceinture en plus).
            $this->tenantConnectionContext->clear();
            $this->entityManager->clear();
        }
    }
}
