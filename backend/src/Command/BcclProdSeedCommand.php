<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\User;
use App\Seed\BcclSeeder;
use App\Seed\BcclSeedIdentities;
use App\Seed\BcclSeedProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Pose le club BCCL RÉEL en PRODUCTION — le club du fondateur, jouable dès le jour J
 * ({@see BcclSeedProfile::prod()} : état terrain complet — planning transcrit, reprises, incident,
 * répartition WE, adversaires amorcés — parité avec sa base locale).
 *
 * Contrairement à {@see BcclSeedCommand} (dev-only, exclue du conteneur de prod), cette commande
 * s'auto-enregistre PARTOUT : elle EST faite pour la prod. Elle ne porte donc (AUD-SEC-29) AUCUN
 * prénom, nom, e-mail ni mot de passe réel : le gestionnaire arrive 100 % par options ou prompt
 * masqué. Compte PRÉ-VÉRIFIÉ (le rail /register est mort sans e-mail sortant en prod), gestionnaire
 * `admin` actif sur ARA0069036. Co-gestionnaires et vrais noms de coachs, s'ils sont voulus,
 * viennent du fichier local gitignoré {@see BcclSeedIdentities} (jamais au dépôt).
 *
 * CREATE-ONLY : si le club ARA0069036 existe déjà, la commande NE FAIT RIEN (no-op, SUCCESS) —
 * jamais de reset, jamais de purge. La base de prod ne se re-seede pas par accident.
 *
 * ANTI-USURPATION : elle REFUSE de seeder si un User existe déjà pour l'e-mail du gestionnaire. Le
 * seeder adopte en silence un compte trouvé par e-mail (en ignorant le mot de passe fourni) — en
 * prod, un tiers pourrait avoir créé ce compte via /api/register avec SON mot de passe, et
 * l'adopter en ferait un gestionnaire du BCCL. Le refus est explicite, rien n'est créé.
 *
 * ⚠ Comme tout seed, le seeder traverse la RLS et exige la connexion ADMIN : lancer sous
 * `DATABASE_URL=$DATABASE_ADMIN_URL` — le garde superuser de {@see BcclSeeder::run()} échoue vite
 * sinon.
 */
#[AsCommand(
    name: 'app:bccl:seed-prod',
    description: 'Seed the real BCCL club in production (create-only, no-op if present). Manager identity via options or masked prompt. Needs the admin connection.',
)]
final class BcclProdSeedCommand extends Command
{
    private const int MIN_PASSWORD_LENGTH = 12;
    private const string BCCL_FFBB_CODE = 'ARA0069036';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BcclSeeder $seeder,
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('email', null, InputOption::VALUE_REQUIRED, 'Manager login (the founder).');
        $this->addOption('first-name', null, InputOption::VALUE_REQUIRED, 'Manager first name (no name is hard-coded — options or local identities file only).');
        $this->addOption('last-name', null, InputOption::VALUE_REQUIRED, 'Manager last name (optional; empty if absent).');
        $this->addOption('password', null, InputOption::VALUE_REQUIRED, \sprintf('Manager password (min %d chars). Prompted (hidden) if absent.', self::MIN_PASSWORD_LENGTH));
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
        if (!\is_string($emailOption) || '' === trim($emailOption)) {
            $io->error('--email is required (the account is pre-verified; the register rail is dead without outbound mail).');

            return Command::FAILURE;
        }
        $email = strtolower(trim($emailOption));

        // Identités réelles (prénom/nom, co-gestionnaires, noms de coachs) : fichier local gitignoré
        // s'il est présent sur l'hôte, sinon rien (les options fournissent prénom/nom).
        $identities = BcclSeedIdentities::loadFromFile(BcclSeedIdentities::defaultPath($this->kernel->getProjectDir()));
        $fileManager = $identities?->manager;

        $firstName = $this->resolveName($input, 'first-name', $fileManager['firstName'] ?? null);
        $lastName = $this->resolveName($input, 'last-name', $fileManager['lastName'] ?? null) ?? '';
        if (null === $firstName) {
            $io->error('--first-name is required (no name is hard-coded; provide it as an option or in the local identities file).');

            return Command::FAILURE;
        }

        // Anti-usurpation : le seeder ADOPTE en silence un User préexistant trouvé par e-mail
        // (find-or-create), en IGNORANT le mot de passe fourni. En prod, un tiers pourrait avoir
        // créé un compte via /api/register public avec cet e-mail ET son mot de passe entre le
        // déploiement et le seed ; l'adopter en ferait un gestionnaire du BCCL. On refuse donc de
        // seeder si un compte existe déjà pour l'e-mail — rien n'est créé.
        if ($this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]) instanceof User) {
            $io->error(\sprintf(
                'An account already exists for %s — the seed would silently adopt it (its own password, not the one given here): a takeover risk. Delete or handle that account by hand before running the seed. Nothing was created.',
                $email,
            ));

            return Command::FAILURE;
        }

        $password = $this->resolvePassword($io, $input, 'password', 'Manager password');
        if (null === $password) {
            return Command::FAILURE;
        }

        $profile = BcclSeedProfile::prod($email, $password, $firstName, $lastName, $identities);
        $club = $this->seeder->run($this->entityManager, $profile);

        $io->success(\sprintf(
            'Seeded the real BCCL club "%s" (id %s) — log in as %s (manager).',
            $club->getName(),
            $club->getId(),
            $email,
        ));

        return Command::SUCCESS;
    }

    /**
     * Un nom depuis l'option si fournie, sinon le fichier local d'identités, sinon null.
     */
    private function resolveName(InputInterface $input, string $optionName, ?string $fallback): ?string
    {
        $value = $input->getOption($optionName);
        if (\is_string($value) && '' !== trim($value)) {
            return trim($value);
        }

        if (null !== $fallback && '' !== trim($fallback)) {
            return trim($fallback);
        }

        return null;
    }

    /**
     * Le mot de passe du gestionnaire : depuis l'option si fournie, sinon un prompt MASQUÉ (jamais
     * en clair dans l'historique du shell). Refuse un mot de passe trop court, et refuse de
     * continuer sans mot de passe en mode NON interactif (pas de prompt possible).
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
