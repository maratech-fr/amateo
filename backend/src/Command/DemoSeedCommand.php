<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Seed\BcclSeeder;
use App\Seed\BcclSeedProfile;
use App\Service\ErasedClubPurger;
use App\Service\TenantConnectionContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * P2-4 PR 2bis — (re)crée le club de DÉMONSTRATION permanent « Démo Basket
 * Club » : la structure réelle du BCCL (équipes, gymnases, créneaux,
 * contraintes, réservations — l'état terrain) sous identités FICTIVES (club,
 * gestionnaire, coachs — RGPD : l'écran part en rendez-vous).
 *
 * RESET = le même geste : le workspace est PURGÉ (ErasedClubPurger — la fiche
 * club survit) puis re-seedé à l'identique. « Quand je reset il repart dans
 * l'état de base » (fondateur, 2026-08-07) : tout ce qui a été bidouillé en
 * démo disparaît, y compris les plannings générés.
 *
 * `--if-absent` neutralise ce RESET : si le club de démo existe déjà, la commande
 * NE FAIT RIEN (no-op, SUCCESS). C'est le chemin qu'emprunte `make play` pour
 * poser la démo sans jamais détruire un workspace existant.
 *
 * ⚠ Exige la CONNEXION ADMIN (la purge et le seed traversent la RLS) :
 * `DATABASE_URL=$DATABASE_ADMIN_URL php bin/console app:demo:seed …` — le garde du
 * seeder refuse sinon, fail-fast. `make seed-demo` injecte l'URL admin.
 */
#[AsCommand(
    name: 'app:demo:seed',
    description: 'Create or RESET the permanent anonymised BCCL demo club (--if-absent: no-op if it exists). Needs the admin connection.',
)]
final class DemoSeedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BcclSeeder $seeder,
        private readonly ErasedClubPurger $erasedClubPurger,
        private readonly TenantConnectionContext $tenantConnectionContext,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password of the demo manager account (min 12 chars). Required at first creation, optional on reset.');
        $this->addOption('email', null, InputOption::VALUE_REQUIRED, 'Demo manager login.', 'demo-bccl@amateo.fr');
        $this->addOption('if-absent', null, InputOption::VALUE_NONE, 'Only create when absent: if the demo club already exists, do nothing (no reset).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = strtolower((string) $input->getOption('email'));
        $password = $input->getOption('password');

        // Reset : purge du workspace AVANT le re-seed — la fiche club survit
        // (même routine que l'effacement RGPD), le seed retrouve son club par
        // code FFBB et repart d'une base vide. Sans club existant : création.
        $profile = BcclSeedProfile::demo(\is_string($password) ? $password : 'unused-on-reset', $email);
        $existing = $this->entityManager->getRepository(Club::class)->findOneBy(['ffbbClubCode' => 'ARA9999999']);

        // --if-absent : le club existe déjà → on ne touche à RIEN (no-op). C'est le
        // chemin non destructeur de `make play`, qui rappelle le seed à chaque bascule.
        if ($existing instanceof Club && $input->getOption('if-absent')) {
            $io->success('The demo club is already present — nothing touched (--if-absent).');

            return Command::SUCCESS;
        }

        if ($existing instanceof Club) {
            if (!$existing->isDemo()) {
                $io->error('The club holding ARA9999999 is NOT a demo club — refusing to purge it.');

                return Command::FAILURE;
            }
            $this->tenantConnectionContext->setClubId($existing->getId());

            try {
                $deleted = $this->erasedClubPurger->purge($existing);
            } finally {
                $this->tenantConnectionContext->clear();
            }
            $this->entityManager->clear();
            $io->note(\sprintf('Demo workspace purged (%d rows) — reseeding from scratch.', $deleted));
        } elseif (!\is_string($password) || \strlen($password) < 12) {
            // Première création : le compte gestionnaire naît ici, son mot de
            // passe aussi. Au reset le compte existe déjà (le purge ne touche pas
            // les User) : --password devient optionnel.
            $io->error('--password (min 12 chars) is required at first creation.');

            return Command::FAILURE;
        }

        $club = $this->seeder->run($this->entityManager, $profile);

        $io->success(\sprintf('Demo club "%s" ready — id %s. Log in as %s. Simulated clock: app:demo:clock --club=%s --date=YYYY-MM-DD.', $club->getName(), $club->getId(), $email, $club->getId()));

        return Command::SUCCESS;
    }
}
