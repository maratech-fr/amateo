<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Service\DemoClubMaterializer;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tâche NOCTURNE (cron-runner, catalogue AdminJobCatalog) : détruit le club de
 * démonstration PROSPECT du LENDEMAIN.
 *
 * Décision fondateur : un club démo prospect vit la journée de son rendez-vous puis
 * disparaît la nuit — sa suppression LIBÈRE le code FFBB du prospect, et le même jour
 * une réactivation réutilise le club existant. Sont détruits les clubs `is_demo` dont
 * l'UNIQUE membre est le compte animateur (`app.demo_animator_email`) ET créés AVANT
 * aujourd'hui (Europe/Paris). La démo BCCL permanente (ARA9999999 / compte `demo-bccl@`)
 * n'est JAMAIS concernée : elle n'est pas une adhésion de l'animateur prospect, et le
 * chemin sûr {@see DemoClubMaterializer::teardownStaleDemos()} saute de toute façon tout
 * club partagé ou non-démo.
 */
#[AsCommand(
    name: 'app:demo:purge-stale',
    description: 'Destroy the prospect demo clubs of the demo animator created before today (frees the FFBB code). Runs nightly.',
)]
final class DemoPurgeStaleCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DemoClubMaterializer $materializer,
        private readonly ClockInterface $clock,
        #[Autowire(param: 'app.demo_animator_email')]
        private readonly string $demoAnimatorEmail,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $animator = $this->entityManager->getRepository(User::class)->findOneBy(['email' => strtolower($this->demoAnimatorEmail)]);
        if (!$animator instanceof User) {
            $io->writeln('No demo animator account — nothing to purge.');

            return Command::SUCCESS;
        }

        // Cutoff = début du jour COURANT en Europe/Paris : un club démo créé aujourd'hui
        // est gardé (réactiver la fenêtre le même jour réutilise ce club), la veille part.
        $cutoff = DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new DateTimeZone('Europe/Paris'))
            ->setTime(0, 0);

        $freed = $this->materializer->teardownStaleDemos($animator, $cutoff);
        foreach ($freed as $clubId) {
            $io->writeln(\sprintf('  <info>✓</info> demo club %s destroyed — FFBB code freed', $clubId));
        }

        $io->success(\sprintf('%d stale prospect demo club(s) purged.', \count($freed)));

        return Command::SUCCESS;
    }
}
