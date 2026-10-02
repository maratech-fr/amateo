<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SeasonResolver;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SA4 / P1-3 — attribution superadmin d'une OFFRE à un club (virement encaissé →
 * offre posée). Cible un plan par son CODE stable (`decouverte`, `essentiel`,
 * `club`, `grand-club`, `sans-limite`, `beta`) : l'UI console liste dynamiquement
 * une entrée figée PAR offre (AdminActionCatalog).
 *
 * Une offre payante n'ouvre le droit qu'avec une saison réglée : `--paid-season`
 * (`current`|`next`) pose l'offre ET marque la saison encaissée dans la MÊME
 * transaction — sans elle, l'offre retombe sur Découverte à la lecture (invariant
 * PlanEntitlements). `--paid-season` est INTERDIT avec `decouverte` (rien à encaisser).
 * Sans l'option, comportement historique : l'offre seule (voie CLI directe, ex.
 * repasser un club en Découverte).
 *
 * Horloge démo (D6, décision fondateur) : le pivot d'encaissement lit `simulated_today`
 * du club cible quand il est de DÉMONSTRATION (même patron que MarkNextSeasonPaidCommand),
 * jamais l'horloge réelle — sinon la démo de bascule ment.
 *
 * Connexion PAR DÉFAUT (même patron que MarkNextSeasonPaidCommand) : `club` n'a pas de
 * colonne club_id, donc pas de policy RLS — l'UPDATE ciblé par id passe, et le rail
 * SA4 gate déjà l'accès.
 */
#[AsCommand(
    name: 'app:clubs:set-plan',
    description: 'Assign a subscription offer (by code) to a club. Support action.',
)]
final class SetClubPlanCommand extends Command
{
    public function __construct(private readonly ManagerRegistry $managerRegistry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('club', null, InputOption::VALUE_REQUIRED, 'Target club id (required).');
        $this->addOption('plan', null, InputOption::VALUE_REQUIRED, 'Offer code (decouverte|essentiel|club|grand-club|sans-limite|beta).');
        $this->addOption('paid-season', null, InputOption::VALUE_REQUIRED, 'Mark a season as paid alongside the offer (current|next). Forbidden with decouverte.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $clubId = $input->getOption('club');
        $planCode = $input->getOption('plan');
        if (!\is_string($clubId) || '' === $clubId) {
            $io->error('--club <id> is required.');

            return Command::FAILURE;
        }
        if (!\is_string($planCode) || '' === $planCode) {
            $io->error('--plan <code> is required.');

            return Command::FAILURE;
        }

        $paidSeason = $input->getOption('paid-season');
        if (null !== $paidSeason && !\in_array($paidSeason, ['current', 'next'], true)) {
            $io->error('--paid-season must be "current" or "next".');

            return Command::FAILURE;
        }
        if (null !== $paidSeason && 'decouverte' === $planCode) {
            $io->error('--paid-season is not allowed with the free "decouverte" offer (nothing to collect).');

            return Command::FAILURE;
        }

        $connection = $this->connection();
        $planId = $connection->fetchOne(
            'SELECT id FROM subscription_plan WHERE code = :code',
            ['code' => $planCode],
        );
        if (false === $planId) {
            $io->error(\sprintf('Unknown offer code "%s".', $planCode));

            return Command::FAILURE;
        }

        // Sans encaissement : l'offre seule (voie CLI directe historique).
        if (null === $paidSeason) {
            $updated = $connection->executeStatement(
                'UPDATE club SET plan_id = :planId WHERE id = :id',
                ['planId' => $planId, 'id' => $clubId],
            );
            if (0 === $updated) {
                $io->error(\sprintf('Club %s not found.', $clubId));

                return Command::FAILURE;
            }

            $io->success(\sprintf('Offer "%s" assigned to club %s.', $planCode, $clubId));

            return Command::SUCCESS;
        }

        // Encaissement : offre + saison réglée dans la MÊME transaction. Le pivot lit
        // simulated_today du club démo (D6), sinon l'horloge réelle. Une valeur non-NULL
        // n'existe QUE pour un club is_demo (DemoClockCommand ne l'écrit que là).
        // transactional() relaie le code de retour de la closure (int) — offre + marqueur
        // dans la MÊME transaction : un échec de l'un annule l'autre.
        return $connection->transactional(function (Connection $tx) use ($io, $clubId, $planId, $planCode, $paidSeason): int {
            $pin = $tx->fetchOne(
                'SELECT CASE WHEN is_demo AND simulated_today IS NOT NULL THEN simulated_today END FROM club WHERE id = :id',
                ['id' => $clubId],
            );
            if (false === $pin) {
                $io->error(\sprintf('Club %s not found.', $clubId));

                return Command::FAILURE;
            }
            $pivot = null === $pin ? new DateTimeImmutable : new DateTimeImmutable((string) $pin);
            $year = SeasonResolver::seasonYear($pivot) + ('next' === $paidSeason ? 1 : 0);

            // GREATEST : le marqueur d'encaissement ne recule jamais (monotone, idempotent).
            $tx->executeStatement(
                'UPDATE club SET plan_id = :planId, paid_season_year = GREATEST(COALESCE(paid_season_year, 0), :year) WHERE id = :id',
                ['planId' => $planId, 'year' => $year, 'id' => $clubId],
            );

            $io->success(\sprintf('Offer "%s" assigned to club %s — season %d-%d marked as paid.', $planCode, $clubId, $year, $year + 1));

            return Command::SUCCESS;
        });
    }

    private function connection(): Connection
    {
        $connection = $this->managerRegistry->getConnection();
        \assert($connection instanceof Connection);

        return $connection;
    }
}
