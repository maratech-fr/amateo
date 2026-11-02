<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Schedule;
use App\Entity\ScheduleStructureSnapshot;
use App\Entity\Team;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Une version peut-elle être chargée sans casser le club ? Maison unique du motif : le refus de
 * {@see StructureRestorer} et la capacité `regenerateFromBlockedReason` disent le même texte.
 *
 * Une version non chargeable reste consultable, jamais chargée à moitié.
 */
final class RestoreFeasibility
{
    public const string NO_PHOTO_MESSAGE = 'Cette version n\'a pas de photo de structure (générée avant l\'historique) — impossible de restaurer ses conditions.';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TeamEngagementGuard $teamEngagementGuard,
    ) {}

    /**
     * Le motif de chaque version du lot qui ne peut pas être chargée. Une version absente de la carte
     * se charge. Une seule lecture des équipes par saison, et les photos ne sont lues que si une
     * équipe joue déjà : la collection des versions ne paie rien de plus dans le cas courant.
     *
     * @param list<Schedule>      $schedules
     * @param array<string, true> $withPhoto ids des versions portant une photo
     *
     * @return array<string, string> scheduleId => motif
     */
    public function blockedReasons(array $schedules, array $withPhoto): array
    {
        $reasons = [];
        $withPhotoBySeason = [];
        foreach ($schedules as $schedule) {
            if (!isset($withPhoto[$schedule->getId()])) {
                $reasons[$schedule->getId()] = self::NO_PHOTO_MESSAGE;
                continue;
            }
            $withPhotoBySeason[$schedule->getClubId() . '|' . $schedule->getSeasonId()][] = $schedule;
        }

        foreach ($withPhotoBySeason as $versions) {
            $first = $versions[0];
            $engaged = $this->engagedTeams($first->getClubId(), $first->getSeasonId());
            if ([] === $engaged) {
                continue;
            }
            $snapshots = $this->entityManager->getRepository(ScheduleStructureSnapshot::class)
                ->findBy(['scheduleId' => array_map(static fn (Schedule $s): string => $s->getId(), $versions)]);
            foreach ($snapshots as $snapshot) {
                $reason = $this->conflictWithEngagedTeams($engaged, $snapshot->getData()['Team'] ?? []);
                if (null !== $reason) {
                    $reasons[$snapshot->getScheduleId()] = $reason;
                }
            }
        }

        return $reasons;
    }

    /**
     * Le motif qui interdit de restaurer cette photo. `Team` : la famille « équipes » de la photo.
     *
     * Présente ne suffit pas : `level` est un champ mappé, donc la photo le porte et la restauration le
     * réinsère tel quel. Une photo antérieure à la correction du niveau le RÉÉCRIRAIT en silence — la
     * divergence même que le gel du niveau existe pour rendre impossible, atteinte par l'autre bout.
     *
     * @param list<array<string, mixed>> $photoTeams
     */
    public function restoreConflict(string $clubId, string $seasonId, array $photoTeams): ?string
    {
        return $this->conflictWithEngagedTeams($this->engagedTeams($clubId, $seasonId), $photoTeams);
    }

    /** @return list<Team> les équipes de la saison qui jouent déjà en compétition */
    private function engagedTeams(string $clubId, string $seasonId): array
    {
        /** @var list<Team> $teams */
        $teams = $this->entityManager->getRepository(Team::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]);
        if ([] === $teams) {
            return [];
        }
        $engaged = $this->teamEngagementGuard->engagedTeamIds(array_map(static fn (Team $t): string => $t->getId(), $teams));

        return array_values(array_filter($teams, static fn (Team $t): bool => isset($engaged[$t->getId()])));
    }

    /**
     * @param list<Team>                 $engagedTeams
     * @param list<array<string, mixed>> $photoTeams
     */
    private function conflictWithEngagedTeams(array $engagedTeams, array $photoTeams): ?string
    {
        $inPhoto = [];
        foreach ($photoTeams as $row) {
            if (isset($row['id']) && \is_string($row['id'])) {
                $inPhoto[$row['id']] = $row;
            }
        }

        $lost = [];
        $releveled = [];
        foreach ($engagedTeams as $team) {
            $row = $inPhoto[$team->getId()] ?? null;
            if (null === $row) {
                $lost[] = $team->getName();
                continue;
            }
            $photoLevel = $row['level'] ?? null;
            if ($team->getLevel()?->value !== (\is_string($photoLevel) ? $photoLevel : null)) {
                $releveled[] = $team->getName();
            }
        }

        if ([] !== $lost) {
            return \sprintf('Cette version est antérieure à %s, qui joue%s déjà en compétition : la charger %s supprimerait et laisserait %s matchs sans équipe.', implode(', ', $lost), 1 === \count($lost) ? '' : 'nt', 1 === \count($lost) ? 'la' : 'les', 1 === \count($lost) ? 'ses' : 'leurs');
        }

        if ([] !== $releveled) {
            return \sprintf('Cette version a été générée quand %s %s un autre niveau de jeu. %s joue%s déjà en compétition : la charger rétablirait un niveau qui ne correspond plus à %s engagement.', implode(', ', $releveled), 1 === \count($releveled) ? 'avait' : 'avaient', 1 === \count($releveled) ? 'Elle' : 'Elles', 1 === \count($releveled) ? '' : 'nt', 1 === \count($releveled) ? 'son' : 'leur');
        }

        return null;
    }
}
