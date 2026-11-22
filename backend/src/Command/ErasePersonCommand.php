<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TenantConnectionContext;
use App\Structure\PersonEraser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Action de support : effacer une personne du club (RGPD, art. 17), désignée par l'identifiant de
 * l'UN de ses rôles coach, quelle qu'en soit la saison. Aucune route, aucun écran.
 *
 * La recherche du rôle se fait sous le contexte du club désigné (RLS) ET avec le club en clause :
 * un coach d'un autre club, ou inconnu, est « introuvable » (code 1) et rien ne bouge. La sortie
 * ne parle qu'en identifiants et en comptes, jamais en nom ni en adresse ; la trace d'audit est
 * posée par {@see PersonEraser}.
 */
#[AsCommand(
    name: 'app:people:erase',
    description: 'Erase a person of a club (right to erasure), designated by one of their coach roles: every season, every trace. Support action.',
)]
final class ErasePersonCommand extends Command
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly PersonEraser $personEraser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('club', null, InputOption::VALUE_REQUIRED, 'Club id (required).');
        $this->addOption('coach', null, InputOption::VALUE_REQUIRED, 'Id of any coach role of the person, in any season (required).');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be erased without erasing anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $clubId = $input->getOption('club');
        $coachId = $input->getOption('coach');
        if (!\is_string($clubId) || 1 !== preg_match(self::UUID, $clubId) || !\is_string($coachId) || 1 !== preg_match(self::UUID, $coachId)) {
            $io->error('--club <uuid> and --coach <uuid> are required.');

            return Command::FAILURE;
        }

        try {
            $this->tenantConnectionContext->setClubId($clubId);
            $personId = $this->entityManager->getConnection()->fetchOne(
                'SELECT person_id FROM coach WHERE id = ? AND club_id = ?',
                [$coachId, $clubId],
            );
            if (!\is_string($personId)) {
                $io->error(\sprintf('Coach %s not found in club %s.', $coachId, $clubId));

                return Command::FAILURE;
            }

            if (true === $input->getOption('dry-run')) {
                $roles = $this->personEraser->coachIdsOf($clubId, $personId);
                $io->success(\sprintf('[dry-run] Person %s of club %s would be erased: %d coach role(s) (%s).', $personId, $clubId, \count($roles), implode(', ', $roles)));

                return Command::SUCCESS;
            }

            $result = $this->personEraser->erase($clubId, $personId);
            $io->success(\sprintf(
                'Person %s of club %s erased: %d coach role(s) removed (%s), %d wish comment(s) cleared, %d version(s), %d feedback(s) and %d mailbox message(s) rewritten.',
                $personId,
                $clubId,
                \count($result['removedCoachIds']),
                implode(', ', $result['removedCoachIds']),
                $result['blankedWishes'],
                $result['rewrittenSchedules'],
                $result['rewrittenFeedbacks'],
                $result['rewrittenMailboxMessages'],
            ));

            return Command::SUCCESS;
        } finally {
            $this->tenantConnectionContext->clear();
            $this->entityManager->clear();
        }
    }
}
