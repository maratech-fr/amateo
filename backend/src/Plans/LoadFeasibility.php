<?php

declare(strict_types=1);

namespace App\Plans;

use App\Entity\Schedule;
use App\Service\TeamEngagementGuard;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Une version terminée peut-elle être chargée ? Maison unique du motif : le refus de `POST /api/schedules/{id}/load`
 * et la capacité `loadBlockedReason` disent le même texte, mot pour mot. Une version qui ne se charge pas reste
 * consultable.
 *
 * Deux motifs : la version n'a pas de copie de son état (née avant le lot, ni validée ni chargée) ; ou sa copie
 * rendrait à une équipe engagée en compétition un autre niveau que celui sous lequel elle est inscrite.
 */
final class LoadFeasibility
{
    public const string NO_COPY_MESSAGE = 'Cette version a été générée avant que les versions gardent leurs réglages : elle ne peut pas être chargée.';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanStates $planStates,
        private readonly TeamEngagementGuard $teamEngagementGuard,
    ) {}

    public function blockedReason(Schedule $version): ?string
    {
        return $this->blockedReasons([$version], $this->planStates->has($version->getId()) ? [$version->getId() => true] : [])[$version->getId()] ?? null;
    }

    /**
     * Le motif de chaque version du lot qui ne se charge pas ; une version absente de la carte se charge. Les équipes
     * engagées sont lues une fois par saison, et les copies seulement si une équipe joue déjà.
     *
     * @param list<Schedule>      $versions
     * @param array<string, true> $withCopy ids des versions qui portent une copie
     *
     * @return array<string, string> id de version => motif
     */
    public function blockedReasons(array $versions, array $withCopy): array
    {
        $reasons = [];
        $engagedBySeason = [];
        foreach ($versions as $version) {
            if (!isset($withCopy[$version->getId()])) {
                $reasons[$version->getId()] = self::NO_COPY_MESSAGE;

                continue;
            }
            $season = $version->getClubId() . '|' . $version->getSeasonId();
            $engagedBySeason[$season] ??= $this->engagedTeams($version->getClubId(), $version->getSeasonId());
            if ([] === $engagedBySeason[$season]) {
                continue;
            }
            $reason = $this->levelConflict($engagedBySeason[$season], $this->planStates->of($version->getId())->toArray()['Team'] ?? []);
            if (null !== $reason) {
                $reasons[$version->getId()] = $reason;
            }
        }

        return $reasons;
    }

    /** @return array<string, array{name: string, level: string|null}> les équipes de la saison qui jouent déjà en compétition */
    private function engagedTeams(string $clubId, string $seasonId): array
    {
        $teams = [];
        foreach ($this->entityManager->getConnection()->fetchAllAssociative('SELECT id, name, level FROM team WHERE club_id = ? AND season_id = ? ORDER BY name, id', [$clubId, $seasonId]) as $row) {
            $teams[(string) $row['id']] = ['name' => (string) $row['name'], 'level' => null === $row['level'] ? null : (string) $row['level']];
        }
        $engaged = $this->teamEngagementGuard->engagedTeamIds(array_map(strval(...), array_keys($teams)));

        return array_intersect_key($teams, $engaged);
    }

    /**
     * Le motif si la copie rendait à une équipe engagée un autre niveau ; une équipe absente de la copie (créée depuis)
     * garde le sien.
     *
     * @param array<string, array{name: string, level: string|null}> $engaged
     * @param array<string, array<string, mixed>>                    $copiedTeams
     */
    private function levelConflict(array $engaged, array $copiedTeams): ?string
    {
        $releveled = [];
        foreach ($engaged as $teamId => $team) {
            if (!\array_key_exists((string) $teamId, $copiedTeams)) {
                continue;
            }
            $copied = $copiedTeams[(string) $teamId]['level'] ?? null;
            if ($team['level'] !== (\is_string($copied) ? $copied : null)) {
                $releveled[] = $team['name'];
            }
        }
        if ([] === $releveled) {
            return null;
        }

        return 1 === \count($releveled)
            ? \sprintf('Cette version donne à %s un autre niveau, et l\'équipe est engagée en compétition : elle ne peut pas être chargée.', $releveled[0])
            : \sprintf('Cette version donne à %s un autre niveau, et ces équipes sont engagées en compétition : elle ne peut pas être chargée.', implode(', ', $releveled));
    }
}
