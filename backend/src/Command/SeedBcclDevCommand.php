<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Seed\BcclSeeder;
use App\Seed\BcclSeedProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Pose le club dev BCCL RÉEL (identités réelles, sans flag démo) — le raccourci
 * qu'appelle `make play` pour amener la base de jeu du fondateur à un état
 * exploitable.
 *
 * CREATE-ONLY, jamais reset. La base de jeu du fondateur EST son travail : cette
 * commande ne seede QUE si le club est absent, et refuse tout net sinon —
 * contrairement à `app:demo:seed-bccl` (créer OU RESET, qui purge le workspace).
 * Le RESET délibéré passe par `make fixtures` sur une base jetable.
 *
 * Dev/test seulement, sur DEUX couches : cette classe est exclue de l'auto-
 * enregistrement (`services.yaml`) et n'est déclarée que dans `services_dev.yaml`
 * et `services_test.yaml` (jamais dans le conteneur de prod, ni dans
 * `bin/console list` là-bas), ET un garde runtime refuse tout autre environnement.
 * Le club porte des identités RÉELLES (nom, coachs, gestionnaire `mara.mb@bccl.fr`) :
 * invisible en prod par construction, il n'expose donc aucune donnée personnelle.
 *
 * ⚠ Comme `make fixtures` et `app:demo:seed-bccl`, le seeder traverse la RLS et
 * exige la connexion ADMIN : lancer sous `DATABASE_URL=$DATABASE_ADMIN_URL` — le
 * garde superuser du seeder échoue vite sinon.
 */
#[AsCommand(
    name: 'app:seed:bccl-dev',
    description: 'Seed the real BCCL dev club (create-only, never resets). Needs the admin connection, like make fixtures.',
)]
final class SeedBcclDevCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BcclSeeder $seeder,
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $environment = $this->kernel->getEnvironment();
        if ('dev' !== $environment && 'test' !== $environment) {
            $io->error(\sprintf('This command is dev-only (the real BCCL dev club) — refusing to run in "%s".', $environment));

            return Command::FAILURE;
        }

        $profile = BcclSeedProfile::dev();

        $existing = $this->entityManager->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $profile->ffbbCode]);
        if ($existing instanceof Club) {
            $io->error('The BCCL dev club already exists — nothing touched. This command only creates, it never resets; use make fixtures on a disposable database.');

            return Command::FAILURE;
        }

        $club = $this->seeder->run($this->entityManager, $profile);

        $io->success(\sprintf(
            'Seeded "%s" (id %s) — log in as %s.',
            $club->getName(),
            $club->getId(),
            $profile->managerEmail,
        ));

        return Command::SUCCESS;
    }
}
