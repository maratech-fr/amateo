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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pose le club BCCL RÉEL en PRODUCTION — le club du fondateur, jouable dès le jour J
 * ({@see BcclSeedProfile::prod()} : identités réelles, planning transcrit, reprises, incident,
 * répartition WE, adversaires amorcés — parité avec sa base locale).
 *
 * Contrairement à {@see BcclSeedCommand} (dev-only, exclue du conteneur de prod), cette commande
 * s'auto-enregistre PARTOUT : elle EST faite pour la prod. Elle ne porte donc AUCUN mot de passe
 * ni adresse réels — les gestionnaires (le fondateur + Nicolas Barilleau) arrivent 100 % par
 * options ou par prompt masqué. Comptes PRÉ-VÉRIFIÉS (le rail /register est mort sans e-mail
 * sortant en prod), gestionnaires `admin` actifs sur ARA0069036.
 *
 * CREATE-ONLY : si le club ARA0069036 existe déjà, la commande NE FAIT RIEN (no-op, SUCCESS) —
 * jamais de reset, jamais de purge. La base de prod ne se re-seede pas par accident.
 *
 * ⚠ Comme tout seed, le seeder traverse la RLS et exige la connexion ADMIN : lancer sous
 * `DATABASE_URL=$DATABASE_ADMIN_URL` — le garde superuser de {@see BcclSeeder::run()} échoue vite
 * sinon.
 */
#[AsCommand(
    name: 'app:bccl:seed-prod',
    description: 'Seed the real BCCL club in production (create-only, no-op if present). Manager credentials via options or masked prompt. Needs the admin connection.',
)]
final class BcclProdSeedCommand extends Command
{
    private const int MIN_PASSWORD_LENGTH = 12;
    private const string BCCL_FFBB_CODE = 'ARA0069036';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BcclSeeder $seeder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('email', null, InputOption::VALUE_REQUIRED, 'Main manager login (the founder).');
        $this->addOption('co-email', null, InputOption::VALUE_REQUIRED, 'Co-manager login (Nicolas Barilleau).');
        $this->addOption('password', null, InputOption::VALUE_REQUIRED, \sprintf('Main manager password (min %d chars). Prompted (hidden) if absent.', self::MIN_PASSWORD_LENGTH));
        $this->addOption('co-password', null, InputOption::VALUE_REQUIRED, \sprintf('Co-manager password (min %d chars). Prompted (hidden) if absent.', self::MIN_PASSWORD_LENGTH));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Create-only : si le club existe déjà, on ne touche à RIEN (jamais de reset en prod).
        $existing = $this->entityManager->getRepository(Club::class)->findOneBy(['ffbbClubCode' => self::BCCL_FFBB_CODE]);
        if ($existing instanceof Club) {
            $io->success('The BCCL club is already present — nothing touched (create-only, never resets in production).');

            return Command::SUCCESS;
        }

        $emailOption = $input->getOption('email');
        $coEmailOption = $input->getOption('co-email');
        if (!\is_string($emailOption) || '' === trim($emailOption) || !\is_string($coEmailOption) || '' === trim($coEmailOption)) {
            $io->error('--email and --co-email are both required (the accounts are pre-verified; the register rail is dead without outbound mail).');

            return Command::FAILURE;
        }
        $email = strtolower(trim($emailOption));
        $coEmail = strtolower(trim($coEmailOption));

        $password = $this->resolvePassword($io, $input, 'password', 'Main manager password');
        if (null === $password) {
            return Command::FAILURE;
        }
        $coPassword = $this->resolvePassword($io, $input, 'co-password', 'Co-manager password');
        if (null === $coPassword) {
            return Command::FAILURE;
        }

        $profile = BcclSeedProfile::prod($email, $password, $coEmail, $coPassword);
        $club = $this->seeder->run($this->entityManager, $profile);

        $io->success(\sprintf(
            'Seeded the real BCCL club "%s" (id %s) — log in as %s (manager) or %s (co-manager).',
            $club->getName(),
            $club->getId(),
            $email,
            $coEmail,
        ));

        return Command::SUCCESS;
    }

    /**
     * Le mot de passe d'un gestionnaire : depuis l'option si fournie, sinon un prompt MASQUÉ
     * (jamais en clair dans l'historique du shell). Refuse un mot de passe trop court, et refuse
     * de continuer sans mot de passe en mode NON interactif (pas de prompt possible).
     */
    private function resolvePassword(SymfonyStyle $io, InputInterface $input, string $optionName, string $label): ?string
    {
        $value = $input->getOption($optionName);
        if (\is_string($value) && '' !== $value) {
            return $this->assertLongEnough($io, $value, $label);
        }

        if (!$input->isInteractive()) {
            $io->error(\sprintf('--%s is required in non-interactive mode (no hidden prompt available).', $optionName));

            return null;
        }

        $prompted = $io->askHidden(\sprintf('%s (min %d chars, hidden)', $label, self::MIN_PASSWORD_LENGTH));

        return $this->assertLongEnough($io, \is_string($prompted) ? $prompted : '', $label);
    }

    private function assertLongEnough(SymfonyStyle $io, string $password, string $label): ?string
    {
        if (\strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $io->error(\sprintf('%s must be at least %d characters long.', $label, self::MIN_PASSWORD_LENGTH));

            return null;
        }

        return $password;
    }
}
