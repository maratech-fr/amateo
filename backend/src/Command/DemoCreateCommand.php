<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\User;
use App\Service\DemoClubMaterializer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * P2-4 — crée un CLUB DE DÉMONSTRATION avec le code FFBB du prospect, et y
 * repointe le compte « animateur démo ».
 *
 * L'effet waouw en rendez-vous (fondateur, 2026-08-06) : « je mets le code FFBB
 * et boum ça marche en direct » — fiche club, logo, comité/ligue remplis par la
 * FFBB (populate synchrone, best-effort comme au register), salles de la commune
 * en autocomplétion à l'étape Gymnases. Le club naît NON onboardé : le parcours
 * guidé du wizard EST la démo.
 *
 * UN SEUL login pour toutes les démos : l'animateur (créé au premier passage,
 * --animator-password requis ce jour-là) est DÉPLACÉ de démo en démo — ses
 * adhésions précédentes sont supprimées, jamais accumulées (le backend résout le
 * club depuis l'adhésion active du JWT : deux actives = ambigu).
 *
 * Ce chemin est le SEUL qui pose is_demo (avec le raccourci démo du register) :
 * aucun parcours utilisateur ordinaire ne peut fabriquer un club démo (horloge
 * simulable, hors métriques, futur bridage exempté). Le cœur du geste vit dans
 * {@see DemoClubMaterializer}, partagé avec DevDemoRegisterController.
 */
#[AsCommand(
    name: 'app:demo:create',
    description: 'Create a DEMO club from a FFBB code and point the demo-animator account at it. Support action.',
)]
final class DemoCreateCommand extends Command
{
    /**
     * MAISON UNIQUE de l'adresse de l'animateur démo — référencée aussi par le bind
     * `$demoAnimatorEmail` (services.yaml) que consomme DevDemoRegisterController :
     * le raccourci démo du register et cette commande DOIVENT parler du même compte.
     */
    public const string DEFAULT_ANIMATOR_EMAIL = 'demo@amateo.fr';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly DemoClubMaterializer $materializer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('ffbb', null, InputOption::VALUE_REQUIRED, 'FFBB club code of the prospect (e.g. ARA0069036).');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Club display name (shown until the FFBB populate overwrites it).');
        $this->addOption('animator-email', null, InputOption::VALUE_REQUIRED, 'Demo animator login.', self::DEFAULT_ANIMATOR_EMAIL);
        $this->addOption('animator-password', null, InputOption::VALUE_REQUIRED, 'Required the FIRST time only (the animator account is created once).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ffbb = $input->getOption('ffbb');
        $name = $input->getOption('name');
        if (!\is_string($ffbb) || 1 !== preg_match('/^[A-Z]{3}\d{7}$/', $ffbb)) {
            $io->error('--ffbb must be a FFBB club code (3 letters + 7 digits, e.g. ARA0069036).');

            return Command::FAILURE;
        }
        if (!\is_string($name) || '' === trim($name)) {
            $io->error('--name is required.');

            return Command::FAILURE;
        }

        $email = strtolower((string) $input->getOption('animator-email'));
        $animator = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$animator instanceof User) {
            $password = $input->getOption('animator-password');
            if (!\is_string($password) || \strlen($password) < 12) {
                $io->error(\sprintf('Animator %s does not exist yet: --animator-password (min 12 chars) is required on first use.', $email));

                return Command::FAILURE;
            }
            $animator = new User;
            $animator->setEmail($email);
            $animator->setFirstName('Démo');
            $animator->setLastName('Amateo');
            $animator->setPasswordHash($this->passwordHasher->hashPassword($animator, $password));
            // Compte INTERNE : jamais passé par register, on matérialise ce que le
            // flux d'inscription aurait posé (login refusé sans email vérifié).
            $animator->setEmailVerifiedAt(new DateTimeImmutable);
            $animator->setTermsAcceptedAt(new DateTimeImmutable);
            $this->entityManager->persist($animator);
            $this->entityManager->flush();
            $io->note(\sprintf('Animator account %s created.', $email));
        }

        // Un ARA ne peut pas désigner deux clubs (contrainte du register) : si le
        // prospect a DÉJÀ un vrai club dans l'outil, on refuse — une démo ne doit
        // jamais squatter le code d'un client. La commande garde ce refus ; le
        // raccourci démo du register a le sien (409 + teardown du club démo).
        $existing = $this->entityManager->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $ffbb]);
        if ($existing instanceof Club) {
            $io->error(\sprintf('Club "%s" already exists for %s%s — delete it first (or demo on it directly if it IS a demo).', $existing->getName(), $ffbb, $existing->isDemo() ? ' (demo)' : ''));

            return Command::FAILURE;
        }

        $club = $this->materializer->materialize(
            $ffbb,
            $name,
            $animator,
            static fn (string $note) => $io->note($note),
            static fn (string $warning) => $io->warning($warning),
        );

        $io->success(\sprintf('Demo club "%s" (%s) created — id %s. Log in as %s. Simulated clock: app:club:clock --club=%s --date=YYYY-MM-DD.', $club->getName(), $ffbb, $club->getId(), $email, $club->getId()));

        return Command::SUCCESS;
    }
}
