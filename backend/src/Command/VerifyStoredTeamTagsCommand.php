<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\Constraint;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Service\TeamTagResolver;
use App\Service\TeamTagService;
use App\Service\TenantConnectionContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Contrôle, en lecture seule, que les étiquettes ENREGISTRÉES (`team_tag`,
 * `team_tag_assignment`, plus écrites depuis le lot conception 1, PR 3) sont celles que le
 * calcul à la lecture rend — toutes saisons, archivées comprises. Sortie 0 = identique ; 1 =
 * chaque écart listé par identifiants seulement (jamais un nom d'équipe ni de club).
 *
 * Commande jetable : elle part avec les deux tables (première PR du lot 3).
 *
 * Chaque club est lu sous son contexte RLS (`TenantConnectionContext`), relâché en `finally`.
 */
#[AsCommand(
    name: 'app:team-tags:verify-stored',
    description: 'Compare the stored team tags of every club (or the given clubs) with the tags computed at read time. Read-only; exits 1 on any difference.',
)]
final class VerifyStoredTeamTagsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TeamTagService $tagService,
        private readonly TenantConnectionContext $tenantConnectionContext,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('club', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to these club ids (default: all clubs).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $requested */
        $requested = array_values(array_filter((array) $input->getOption('club'), static fn (mixed $id): bool => \is_string($id) && '' !== $id));
        $clubIds = [] === $requested
            ? array_map(static fn (Club $club): string => $club->getId(), $this->entityManager->getRepository(Club::class)->findAll())
            : $requested;

        foreach ($requested as $clubId) {
            // Forme vérifiée AVANT la lecture : un identifiant mal formé ferait lever PostgreSQL
            // (colonne uuid) au lieu d'un refus propre.
            if (!Uuid::isValid($clubId) || !$this->entityManager->getRepository(Club::class)->find($clubId) instanceof Club) {
                $io->error(\sprintf('Club %s not found.', $clubId));

                return Command::FAILURE;
            }
        }
        $this->entityManager->clear();

        $total = 0;
        foreach ($clubIds as $clubId) {
            try {
                $this->tenantConnectionContext->setClubId($clubId);
                $differences = $this->differencesOf($clubId);
            } finally {
                $this->entityManager->clear();
                $this->tenantConnectionContext->clear();
            }
            foreach ($differences as $line) {
                $io->writeln(\sprintf('club %s · %s', $clubId, $line));
            }
            $total += \count($differences);
        }

        if ($total > 0) {
            $io->error(\sprintf('%d difference(s) across %d club(s).', $total, \count($clubIds)));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Stored team tags match the computed ones across %d club(s).', \count($clubIds)));

        return Command::SUCCESS;
    }

    /** @return list<string> */
    private function differencesOf(string $clubId): array
    {
        $connection = $this->entityManager->getConnection();
        $differences = [];

        $colorOf = [];
        $axisOf = [];
        foreach ($this->tagService->catalog() as $entry) {
            $colorOf[$entry['name']] = $entry['color'];
            $axisOf[$entry['name']] = $entry['axis']->value;
        }

        /** @var array<string, string> $nameByTagId */
        $nameByTagId = [];
        foreach ($connection->fetchAllAssociative('SELECT id, name, color, is_system, axis FROM team_tag WHERE club_id = :club ORDER BY id', ['club' => $clubId]) as $tag) {
            $tagId = (string) $tag['id'];
            $name = (string) $tag['name'];
            $nameByTagId[$tagId] = $name;
            if (!$this->tagService->isCatalogName($name)) {
                $differences[] = \sprintf('tag %s: outside the catalogue', $tagId);

                continue;
            }
            if (!(bool) $tag['is_system']) {
                $differences[] = \sprintf('tag %s (%s): not a system tag', $tagId, $name);
            }
            if ($tag['color'] !== $colorOf[$name]) {
                $differences[] = \sprintf('tag %s (%s): colour differs from the catalogue', $tagId, $name);
            }
            if ($tag['axis'] !== $axisOf[$name]) {
                $differences[] = \sprintf('tag %s (%s): axis differs from the catalogue', $tagId, $name);
            }
        }

        /** @var array<string, list<string>> $storedByTeam */
        $storedByTeam = [];
        foreach ($connection->fetchAllAssociative('SELECT id, team_id, tag_id FROM team_tag_assignment WHERE club_id = :club ORDER BY id', ['club' => $clubId]) as $assignment) {
            $tagId = (string) $assignment['tag_id'];
            $name = $nameByTagId[$tagId] ?? null;
            if (null === $name || !$this->tagService->isCatalogName($name)) {
                $differences[] = \sprintf('assignment %s: team %s, tag %s outside the catalogue', (string) $assignment['id'], (string) $assignment['team_id'], $tagId);

                continue;
            }
            $storedByTeam[(string) $assignment['team_id']][] = $name;
        }

        $teamIds = [];
        foreach ($this->entityManager->getRepository(Team::class)->findBy(['clubId' => $clubId], ['id' => 'ASC']) as $team) {
            $teamId = $team->getId();
            $teamIds[$teamId] = true;
            if (!$this->entityManager->getRepository(SportCategory::class)->find($team->getSportCategoryId()) instanceof SportCategory) {
                $differences[] = \sprintf('team %s: its category %s no longer exists', $teamId, $team->getSportCategoryId());
            }
            $computed = array_values(array_unique($this->tagService->tagNamesOf($team)));
            $stored = array_values(array_unique($storedByTeam[$teamId] ?? []));
            $extra = array_diff($stored, $computed);
            $missing = array_diff($computed, $stored);
            sort($extra);
            sort($missing);
            if ([] !== $extra || [] !== $missing) {
                $differences[] = \sprintf('team %s: stored only [%s], computed only [%s]', $teamId, implode(', ', $extra), implode(', ', $missing));
            }
        }

        foreach (array_keys($storedByTeam) as $teamId) {
            if (!isset($teamIds[$teamId])) {
                $differences[] = \sprintf('team %s: assignments for a team that no longer exists', $teamId);
            }
        }

        foreach ($this->entityManager->getRepository(Constraint::class)->findBy(['clubId' => $clubId], ['id' => 'ASC']) as $rule) {
            $config = $rule->getConfig();
            foreach ([...TeamTagResolver::targetTagNames($config), ...TeamTagResolver::excludeTagNames($config)] as $name) {
                if (!$this->tagService->isCatalogName($name)) {
                    $differences[] = \sprintf('rule %s: targets a name outside the catalogue', $rule->getId());

                    break;
                }
            }
        }

        return $differences;
    }
}
