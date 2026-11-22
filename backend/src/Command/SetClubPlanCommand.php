<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\SubscriptionOrigin;
use App\Repository\SubscriptionPlanRepository;
use App\Service\ClubSubscriptionWriter;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * SA4 / P1-3 — le geste « Offre » du support : écrit la ligne d'abonnement de UNE saison du club
 * (offre par son CODE stable, `decouverte`, `essentiel`, `club`, `grand-club`, `beta`), par le seul
 * écrivain ({@see ClubSubscriptionWriter}, connexion propriétaire). Aucune autre saison ne bouge.
 *
 * Une offre payante (Bêta comprise) exige `--paid-season` (`current`|`next`) et se règle à l'instant
 * pour cette saison. Découverte refuse `--paid-season` : elle s'écrit sur la saison en cours, non
 * réglée. Un refus n'écrit rien.
 *
 * Les deux années sont celles que lisent l'écran et la bascule (règle de l'écrivain) : « en cours »
 * = l'année de la saison courante du club, ou l'année d'horloge s'il n'a pas de saison ; « suivante »
 * = l'année que la bascule créera. L'horloge est celle du club : un club de démonstration épinglé
 * (`simulated_today`) pivote sur cette date, jamais sur le temps réel (D6).
 */
#[AsCommand(
    name: 'app:clubs:set-plan',
    description: 'Assign a subscription offer (by code) to a club for one season. Support action.',
)]
final class SetClubPlanCommand extends Command
{
    private const string DECOUVERTE_CODE = 'decouverte';

    public function __construct(
        private readonly ClubSubscriptionWriter $subscriptionWriter,
        private readonly SubscriptionPlanRepository $plans,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('club', null, InputOption::VALUE_REQUIRED, 'Target club id (required).');
        $this->addOption('plan', null, InputOption::VALUE_REQUIRED, 'Offer code (decouverte|essentiel|club|grand-club|beta).');
        $this->addOption('paid-season', null, InputOption::VALUE_REQUIRED, 'Season the offer is paid for (current|next). Required for a paid offer, forbidden with decouverte.');
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
        if (!Uuid::isValid($clubId)) {
            $io->error(\sprintf('--club must be a club id (UUID), got "%s".', $clubId));

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
        if (null !== $paidSeason && self::DECOUVERTE_CODE === $planCode) {
            $io->error('--paid-season is not allowed with the free "decouverte" offer (nothing to collect).');

            return Command::FAILURE;
        }
        if (null === $this->plans->findOneBy(['code' => $planCode])) {
            $io->error(\sprintf('Unknown offer code "%s".', $planCode));

            return Command::FAILURE;
        }
        if (null === $paidSeason && self::DECOUVERTE_CODE !== $planCode) {
            $io->error(\sprintf('--paid-season is required with the paid offer "%s" (current|next).', $planCode));

            return Command::FAILURE;
        }

        $today = $this->subscriptionWriter->today($clubId);
        if (!$today instanceof DateTimeImmutable) {
            $io->error(\sprintf('Club %s not found.', $clubId));

            return Command::FAILURE;
        }
        $years = $this->subscriptionWriter->seasonYears($clubId, $today);
        $year = 'next' === $paidSeason ? $years['next'] : $years['current'];

        $this->subscriptionWriter->record($clubId, $year, $planCode, SubscriptionOrigin::SUPPORT);

        $io->success(null === $paidSeason
            ? \sprintf('Offer "%s" assigned to club %s for season %d-%d.', $planCode, $clubId, $year, $year + 1)
            : \sprintf('Offer "%s" assigned to club %s — season %d-%d marked as paid.', $planCode, $clubId, $year, $year + 1));

        return Command::SUCCESS;
    }
}
