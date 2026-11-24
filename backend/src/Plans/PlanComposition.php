<?php

declare(strict_types=1);

namespace App\Plans;

use App\Entity\PlanTeam;
use App\Entity\PriorityTier;
use App\Entity\Team;
use App\Enum\PlanTeamDefault;
use App\Enum\SchedulePlanType;
use App\Service\PlanTeamSelectionSeeder;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

/**
 * Les équipes d'un plan (lot 4, PR 3a) : toute équipe a sa ligne dans chaque plan de sa saison, socle compris
 * ({@see PlanTeam}). Cette classe est la seule porte qui pose les lignes ({@see ensureLines}) et la seule qui dit
 * qu'une ligne de période « diffère du socle ».
 *
 * Lire un plan où une ligne manque lève : jamais un défaut silencieux.
 *
 * SQL brut pour les lectures de structure : le plan, les équipes et les lignes d'une saison se lisent hors du
 * `season_filter` (la saison visée n'est pas forcément l'active de la requête) ; la RLS borne le club.
 */
final class PlanComposition
{
    /**
     * DQL : la ligne `e` (d'un plan de période) diffère de la ligne du socle de son équipe — elle ne participe pas,
     * ou ses séances ou son plafond ne sont pas ceux du socle du moment. Maison unique de la règle, lue aussi par la
     * cascade de suppression d'une équipe (le compte de la fenêtre).
     */
    public const string DIFFERS_FROM_SOCLE_DQL = 'EXISTS (SELECT 1 FROM App\Entity\PlanTeam socleLine, App\Entity\SchedulePlan soclePlan'
        . ' WHERE soclePlan.id = socleLine.schedulePlanId AND soclePlan.type = \'SEASON\' AND socleLine.teamId = e.teamId'
        . ' AND socleLine.schedulePlanId <> e.schedulePlanId'
        . ' AND (e.participates = false OR e.sessionsPerWeek <> socleLine.sessionsPerWeek OR e.maxSessionsPerDay <> socleLine.maxSessionsPerDay))';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Pose, dans chaque plan de la saison, les lignes qui manquent ; ne touche jamais une ligne existante. Écrit ce
     * qui attend d'être écrit avant de lire, puis écrit les lignes posées.
     *
     * - Ligne du socle : participe, aux valeurs données par le créateur pour cette équipe, sinon aux valeurs par
     *   défaut d'une équipe ({@see PlanTeam::DEFAULT_SESSIONS_PER_WEEK}).
     * - Ligne de période : les séances et le plafond du socle du moment ; elle participe, sauf à la naissance du plan
     *   (aucune ligne encore) où la nature dit qui est coché ({@see SchedulePlanType::defaultTeamSelection}).
     *
     * @param array<string, array{sessionsPerWeek?: int, maxSessionsPerDay?: int}> $socleValues par équipe : ce que le créateur a saisi
     */
    public function ensureLines(string $seasonId, array $socleValues = []): void
    {
        $this->entityManager->flush();
        $connection = $this->entityManager->getConnection();

        /** @var list<array{id: string, type: string, club_id: string}> $plans */
        $plans = $connection->fetchAllAssociative(
            'SELECT id, type, club_id FROM schedule_plan WHERE season_id = :s ORDER BY (type = :season) DESC, id',
            ['s' => $seasonId, 'season' => SchedulePlanType::SEASON->value],
        );
        /** @var list<array{id: string, club_id: string, priority_tier_id: int|string}> $teams */
        $teams = $connection->fetchAllAssociative('SELECT id, club_id, priority_tier_id FROM team WHERE season_id = :s ORDER BY id', ['s' => $seasonId]);
        if ([] === $plans || [] === $teams) {
            return;
        }

        /** @var array<string, array<string, array{sessions: int, max: int}>> $lines plan => équipe => valeurs */
        $lines = [];
        foreach ($connection->fetchAllAssociative('SELECT schedule_plan_id, team_id, sessions_per_week, max_sessions_per_day FROM plan_team WHERE season_id = :s', ['s' => $seasonId]) as $row) {
            $lines[(string) $row['schedule_plan_id']][(string) $row['team_id']] = ['sessions' => (int) $row['sessions_per_week'], 'max' => (int) $row['max_sessions_per_day']];
        }

        $socle = null;
        $wrote = false;
        foreach ($plans as $plan) {
            $planId = (string) $plan['id'];
            $type = SchedulePlanType::from((string) $plan['type']);
            $planLines = $lines[$planId] ?? [];
            if (SchedulePlanType::SEASON === $type) {
                $socle = $planId;
                foreach ($teams as $team) {
                    $teamId = (string) $team['id'];
                    if (isset($planLines[$teamId])) {
                        continue;
                    }
                    $values = [
                        'sessions' => $socleValues[$teamId]['sessionsPerWeek'] ?? PlanTeam::DEFAULT_SESSIONS_PER_WEEK,
                        'max' => $socleValues[$teamId]['maxSessionsPerDay'] ?? PlanTeam::DEFAULT_MAX_SESSIONS_PER_DAY,
                    ];
                    $this->persistLine((string) $team['club_id'], $seasonId, $planId, $teamId, true, $values['sessions'], $values['max']);
                    $lines[$planId][$teamId] = $values;
                    $wrote = true;
                }

                continue;
            }

            if (null === $socle) {
                // Cas impossible (CLAUDE.md §6) : une saison naît avec son plan de saison.
                throw new LogicException(\sprintf('La saison %s n\'a pas de planning de saison : ses plannings de période n\'ont pas de socle à suivre.', $seasonId));
            }
            $kept = [] === $planLines ? $this->keptAtBirth($type->defaultTeamSelection(), $teams) : null;
            foreach ($teams as $team) {
                $teamId = (string) $team['id'];
                if (isset($planLines[$teamId])) {
                    continue;
                }
                $socleLine = $lines[$socle][$teamId];
                $this->persistLine((string) $team['club_id'], $seasonId, $planId, $teamId, null === $kept || isset($kept[$teamId]), $socleLine['sessions'], $socleLine['max']);
                $wrote = true;
            }
        }

        if ($wrote) {
            $this->entityManager->flush();
        }
    }

    /**
     * Les lignes d'un plan, par équipe ; lève si une équipe de la saison du plan n'a pas la sienne.
     *
     * @return array<string, PlanTeam>
     */
    public function teamsOf(string $planId): array
    {
        $seasonId = $this->entityManager->getConnection()->fetchOne('SELECT season_id FROM schedule_plan WHERE id = :p', ['p' => $planId]);
        if (!\is_string($seasonId)) {
            throw new LogicException(\sprintf('Le plan %s n\'existe pas.', $planId));
        }

        $lines = [];
        foreach ($this->entityManager->getRepository(PlanTeam::class)->findBy(['schedulePlanId' => $planId]) as $line) {
            $lines[$line->getTeamId()] = $line;
        }
        foreach ($this->entityManager->getConnection()->fetchFirstColumn('SELECT id FROM team WHERE season_id = :s', ['s' => $seasonId]) as $teamId) {
            if (!isset($lines[(string) $teamId])) {
                throw new LogicException(\sprintf('L\'équipe %s n\'a pas de ligne dans le plan %s.', (string) $teamId, $planId));
            }
        }

        return $lines;
    }

    /** La ligne d'une équipe dans un plan ; lève si elle manque. */
    public function lineOf(string $planId, string $teamId): PlanTeam
    {
        $line = $this->entityManager->getRepository(PlanTeam::class)->findOneBy(['schedulePlanId' => $planId, 'teamId' => $teamId]);

        return $line instanceof PlanTeam ? $line : throw new LogicException(\sprintf('L\'équipe %s n\'a pas de ligne dans le plan %s.', $teamId, $planId));
    }

    /**
     * Les séances et le plafond du socle de chaque équipe d'une saison, lus hors du `season_filter` (la saison visée
     * n'est pas forcément l'active de la requête).
     *
     * @return array<string, array{sessionsPerWeek: int, maxSessionsPerDay: int}>
     */
    public function socleValuesOfSeason(string $seasonId): array
    {
        $values = [];
        foreach ($this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT l.team_id, l.sessions_per_week, l.max_sessions_per_day FROM plan_team l JOIN schedule_plan p ON p.id = l.schedule_plan_id WHERE p.season_id = :s AND p.type = :season',
            ['s' => $seasonId, 'season' => SchedulePlanType::SEASON->value],
        ) as $row) {
            $values[(string) $row['team_id']] = ['sessionsPerWeek' => (int) $row['sessions_per_week'], 'maxSessionsPerDay' => (int) $row['max_sessions_per_day']];
        }

        return $values;
    }

    /**
     * Les lignes du socle (plan de saison) de ces équipes, par équipe ; lève si une équipe n'a pas la sienne.
     *
     * @param list<string> $teamIds
     *
     * @return array<string, PlanTeam>
     */
    public function socleLinesOf(array $teamIds): array
    {
        if ([] === $teamIds) {
            return [];
        }
        /** @var list<PlanTeam> $found */
        $found = $this->entityManager->createQuery('SELECT e FROM App\Entity\PlanTeam e, App\Entity\SchedulePlan sp WHERE sp.id = e.schedulePlanId AND sp.type = :season AND e.teamId IN (:ids)')
            ->setParameter('season', SchedulePlanType::SEASON)
            ->setParameter('ids', $teamIds)
            ->getResult();
        $lines = [];
        foreach ($found as $line) {
            $lines[$line->getTeamId()] = $line;
        }
        foreach ($teamIds as $teamId) {
            if (!isset($lines[$teamId])) {
                throw new LogicException(\sprintf('L\'équipe %s n\'a pas de ligne au socle.', $teamId));
            }
        }

        return $lines;
    }

    /**
     * Les lignes d'un plan de période qui diffèrent du socle (le réglage propre de la période), par équipe. Vide pour
     * le plan de saison.
     *
     * @return array<string, PlanTeam>
     */
    public function teamLinesDifferingFromSocle(string $planId): array
    {
        /** @var list<PlanTeam> $differing */
        $differing = $this->entityManager->createQuery('SELECT e FROM App\Entity\PlanTeam e WHERE e.schedulePlanId = :p AND ' . self::DIFFERS_FROM_SOCLE_DQL)
            ->setParameter('p', $planId)
            ->getResult();
        $lines = [];
        foreach ($differing as $line) {
            $lines[$line->getTeamId()] = $line;
        }

        return $lines;
    }

    private function persistLine(string $clubId, string $seasonId, string $planId, string $teamId, bool $participates, int $sessionsPerWeek, int $maxSessionsPerDay): void
    {
        $this->entityManager->persist((new PlanTeam)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setSchedulePlanId($planId)
            ->setTeamId($teamId)
            ->setParticipating($participates)
            ->setSessionsPerWeek($sessionsPerWeek)
            ->setMaxSessionsPerDay($maxSessionsPerDay));
    }

    /**
     * Les équipes cochées à la naissance d'un plan, selon sa nature. La règle des rangs vit dans
     * {@see PlanTeamSelectionSeeder::keptTeamIds}, lue sur le référentiel des rangs.
     *
     * @param list<array{id: string, club_id: string, priority_tier_id: int|string}> $teams
     *
     * @return array<string, true>|null null = toutes
     */
    private function keptAtBirth(PlanTeamDefault $default, array $teams): ?array
    {
        if (PlanTeamDefault::ALL === $default) {
            return null;
        }

        $tiers = array_map(
            static fn (mixed $id): PriorityTier => (new PriorityTier)->setId((int) $id),
            $this->entityManager->getConnection()->fetchFirstColumn('SELECT id FROM priority_tier ORDER BY id'),
        );

        return PlanTeamSelectionSeeder::keptTeamIds(
            array_map(static fn (array $team): Team => (new Team)->setId((string) $team['id'])->setPriorityTierId((int) $team['priority_tier_id']), $teams),
            $tiers,
            false,
        );
    }
}
