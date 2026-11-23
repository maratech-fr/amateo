<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\PlanTeam;
use App\Plans\PlanComposition;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;

/**
 * Les équipes d'un plan (lot 4, PR 3a) vues d'un décor de test : une ligne `plan_team` par (plan, équipe), socle
 * compris, posée par la seule porte {@see PlanComposition::ensureLines}. Un décor qui crée une équipe à la main
 * rejoue ce que ferait un créateur de l'application : il appelle la porte, puis règle la ligne du socle.
 *
 * Les lectures de vérification passent par SQL sur les colonnes du contrat (`participates`, `sessions_per_week`,
 * `max_sessions_per_day`, `source`) : elles ne dépendent pas de l'entité.
 */
trait ComposesPlanTeamsTrait
{
    /** Pose les lignes qui manquent dans chaque plan de la saison, comme le fait chaque créateur. */
    /** @param array<string, array{sessionsPerWeek?: int, maxSessionsPerDay?: int}> $socleValues ce que le créateur a saisi, par équipe */
    private function layTeamLines(string $seasonId, array $socleValues = []): void
    {
        static::getContainer()->get(PlanComposition::class)->ensureLines($seasonId, $socleValues);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    /** La ligne d'une équipe dans un plan ; elle doit exister. */
    private function teamLine(string $planId, string $teamId): PlanTeam
    {
        $lines = static::getContainer()->get(PlanComposition::class)->teamsOf($planId);
        Assert::assertArrayHasKey($teamId, $lines, 'l\'équipe a sa ligne dans le plan');

        return $lines[$teamId];
    }

    /**
     * Les séances et le plafond du socle d'une équipe, comme le gestionnaire les saisit à l'étape Équipes. Une équipe
     * sans lignes les reçoit à ces valeurs, comme d'un créateur : ses lignes de période naissent aux mêmes valeurs.
     * Une équipe qui a déjà ses lignes ne change qu'au socle (ses lignes de période gardent leurs valeurs de naissance,
     * décision du §10).
     */
    private function setSocleTeam(string $seasonId, string $teamId, int $sessionsPerWeek, int $maxSessionsPerDay = 1): void
    {
        $this->layTeamLines($seasonId, [$teamId => ['sessionsPerWeek' => $sessionsPerWeek, 'maxSessionsPerDay' => $maxSessionsPerDay]]);
        $this->teamLine(SeasonPlanOf::id(static::getContainer(), $seasonId), $teamId)
            ->setSessionsPerWeek($sessionsPerWeek)
            ->setMaxSessionsPerDay($maxSessionsPerDay);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    /**
     * La ligne relue en base, ou `null` si elle manque.
     *
     * @return array{participates: bool, sessionsPerWeek: int, maxSessionsPerDay: int, source: ?string}|null
     */
    private function lineRow(string $planId, string $teamId): ?array
    {
        $row = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            'SELECT participates, sessions_per_week, max_sessions_per_day, source FROM plan_team WHERE schedule_plan_id = ? AND team_id = ?',
            [$planId, $teamId],
        );
        if (false === $row) {
            return null;
        }

        return [
            'participates' => (bool) $row['participates'],
            'sessionsPerWeek' => (int) $row['sessions_per_week'],
            'maxSessionsPerDay' => (int) $row['max_sessions_per_day'],
            'source' => null === $row['source'] ? null : (string) $row['source'],
        ];
    }

    /** Le référentiel des rangs tel qu'en production (S, A, B, C, D) : la pré-sélection des vacances le lit. */
    private function ensureProductionPriorityTiers(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            'INSERT INTO priority_tier (id, version, created_at, updated_at, label, name, color, or_tools_weight, default_min_sessions)'
            . ' VALUES (1, 1, now(), now(), \'S\', \'Elite\', \'#FFD700\', 10000, 3), (2, 1, now(), now(), \'A\', \'Régional+\', \'#C0C0C0\', 1000, 2),'
            . ' (3, 1, now(), now(), \'B\', \'Régional\', \'#CD7F32\', 100, 2), (4, 1, now(), now(), \'C\', \'Départemental\', \'#3498DB\', 10, 2),'
            . ' (5, 1, now(), now(), \'D\', \'Loisir\', \'#95A5A6\', 1, 1) ON CONFLICT (id) DO NOTHING',
        );
    }

    /** Le nombre de lignes d'une équipe, tous plans confondus. */
    private function lineCountOf(string $teamId): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM plan_team WHERE team_id = ?',
            [$teamId],
        );
    }
}
