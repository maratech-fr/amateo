<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureReviewState;
use App\Service\FbiFixtureImporter;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Rattrape l'état de traitement des rencontres restées « à traiter » d'un dépôt
 * antérieur à la naissance-traitée : une rencontre extérieure, passée ou tombant
 * dans la semaine en cours n'appelle aucune action du club et doit être « traitée »
 * — mais seule la création l'a longtemps posé. Cette commande applique le même
 * critère aux rencontres existantes.
 *
 * PAS de mise à jour SQL directe : la borne « passée/semaine en cours » dépend du
 * fuseau du club (et de l'horloge simulée d'une démonstration), qu'un UPDATE lu à
 * l'heure du serveur de base ignorerait. On marche donc les clubs et on calcule la
 * fenêtre club par club, exactement comme le foyer de l'import.
 *
 * Dry-run par défaut (compte, n'écrit rien) ; {@see catchUpReview} du foyer
 * partagé fait foi. Marche les clubs sur la connexion applicative (RLS) comme
 * {@see PeriodReminderCommand} : la table club n'a pas de politique RLS (lisible
 * GUC vide), la donnée de chaque club est lue/écrite sous SON propre GUC. Un club
 * en échec ne bloque jamais les autres.
 */
#[AsCommand(
    name: 'app:fixtures:catch-up-review',
    description: 'Marque « traitées » les rencontres extérieures/passées restées « à traiter » (dry-run sauf --force).',
)]
final class CatchUpFixtureReviewCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly FbiFixtureImporter $importer,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Écrit les changements (défaut : dry-run).');
        $this->addOption('club', null, InputOption::VALUE_REQUIRED, 'Ne traiter qu\'un seul club (son identifiant).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        $clubOption = $input->getOption('club');
        $clubRepository = $this->entityManager->getRepository(Club::class);
        if (\is_string($clubOption) && '' !== $clubOption) {
            $club = $clubRepository->find($clubOption);
            if (!$club instanceof Club) {
                $io->error(\sprintf('Club introuvable : %s', $clubOption));

                return Command::FAILURE;
            }
            $clubs = [$club];
        } else {
            $clubs = $clubRepository->findAll();
        }
        // Détachés après clear() : seuls id/nom/fuseau sont relus (pas de re-SELECT).
        $this->entityManager->clear();

        $total = 0;
        foreach ($clubs as $club) {
            try {
                $total += $this->catchUpClub($club, $force, $io);
            } catch (Throwable $e) {
                // Un club en échec ne doit pas bloquer le rattrapage des autres.
                $io->warning(\sprintf('Club %s ignoré : %s', $club->getId(), $e->getMessage()));
            } finally {
                $this->entityManager->clear();
                $this->tenantConnectionContext->clear();
            }
        }

        if ($force) {
            $io->success(\sprintf('%d rencontre(s) rattrapée(s) et enregistrée(s).', $total));
        } else {
            $io->note(\sprintf('Dry-run : %d rencontre(s) seraient rattrapée(s). Relancer avec --force pour écrire.', $total));
        }

        return Command::SUCCESS;
    }

    private function catchUpClub(Club $club, bool $force, SymfonyStyle $io): int
    {
        $clubId = $club->getId();
        $this->tenantConnectionContext->setClubId($clubId);

        // La fenêtre « traitée » vit dans le fuseau du club (foyer de l'import) ;
        // l'horodatage du traitement suit la même horloge.
        $weekEnd = $this->importer->currentIsoWeekEnd($club);
        $now = DateTimeImmutable::createFromInterface($this->clock->now());

        /** @var list<Fixture> $fixtures */
        $fixtures = $this->entityManager->getRepository(Fixture::class)->findBy([
            'clubId' => $clubId,
            'reviewState' => FixtureReviewState::NEW,
        ]);

        $away = 0;
        $dueThisWeek = 0;
        foreach ($fixtures as $fixture) {
            if (!$this->importer->catchUpReview($fixture, $now, $weekEnd)) {
                continue;
            }
            if (FixtureHomeAway::AWAY === $fixture->getHomeAway()) {
                ++$away;
            } else {
                ++$dueThisWeek;
            }
        }

        $caught = $away + $dueThisWeek;
        if ($caught > 0) {
            $io->writeln(\sprintf(
                '  %s (%s) : %d extérieur(s) + %d passée(s)/semaine en cours → %d à traiter',
                $club->getName(),
                $clubId,
                $away,
                $dueThisWeek,
                $caught,
            ));
        }

        // Dry-run : les rencontres marquées en mémoire ne sont jamais flushées, le
        // clear() du finally les jette (patron BackfillSchoolZoneCommand).
        if ($force) {
            $this->entityManager->flush();
        }

        return $caught;
    }
}
