<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use DoctrineMigrations\Version20260929160000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * NR — la migration P4-271 CONVERTIT les créneaux de match partagés (rotations) en habitudes
 * taguées AVANT de supprimer les tables : membre position 0 → semaine A, 1 → semaine B ; le tag
 * est posé sur l'habitude MÊME-JOUR existante, sinon une habitude est créée depuis le créneau de
 * la rotation. Elle dédup pour la nouvelle unicité (club, saison, équipe) en gardant la ligne
 * `updated_at` la plus récente, et ABORTE sur une rotation à ≥ 3 membres (condition de retour
 * fondateur).
 *
 * On exécute le VRAI SQL de la migration (`getSql()`, jamais une copie) sous le PROPRIÉTAIRE
 * (amateo_owner, comme en production). La migration ayant DÉJÀ tourné sur la base de test (colonne
 * `week` ajoutée, tables de rotation supprimées, index basculé), on remet l'état PRÉ-migration au
 * sein d'une TRANSACTION admin explicite, on rejoue, on assert, puis on ROLLBACK — le DDL Postgres
 * est transactionnel, donc même un échec laisse la base de test intacte pour les autres tests.
 */
#[Group('integration')]
final class MigrationSlotRotationToHabitTest extends KernelTestCase
{
    private const string CLUB = 'bbbbbbbb-0000-4000-8000-000000000001';
    private const string SEASON = 'bbbbbbbb-0000-4000-8000-000000000002';
    private const string VENUE = 'bbbbbbbb-0000-4000-8000-000000000003';

    private Connection $admin;

    public function testRotationMembersBecomeWeekTaggedHabits(): void
    {
        $this->admin->beginTransaction();
        try {
            $this->restorePreMigrationSchema();

            $t1 = Uuid::v4()->toRfc4122();
            $t2 = Uuid::v4()->toRfc4122();
            // t1 a DÉJÀ une habitude le samedi (jour 6) → sera TAGUÉE A (position 0).
            $this->insertHabit($t1, 6, '15:30', self::VENUE);
            // t2 n'a AUCUNE habitude → une sera CRÉÉE depuis le créneau, TAGUÉE B (position 1).
            $rotationId = $this->insertRotation(6, '15:30', self::VENUE);
            $this->insertRotationMember($rotationId, $t1, 0);
            $this->insertRotationMember($rotationId, $t2, 1);

            $this->runMigration();

            $h1 = $this->habitOf($t1);
            self::assertNotNull($h1, 'l\'habitude même-jour de t1 survit');
            self::assertSame('A', $h1['week'], 'le membre position 0 est tagué semaine A');
            self::assertSame(6, (int) $h1['day_of_week']);

            $h2 = $this->habitOf($t2);
            self::assertNotNull($h2, 'une habitude est CRÉÉE pour t2 (aucune même-jour)');
            self::assertSame('B', $h2['week'], 'le membre position 1 est tagué semaine B');
            self::assertSame(6, (int) $h2['day_of_week']);
            self::assertSame('15:30:00', $h2['kickoff'], 'l\'heure vient du créneau de la rotation');
            self::assertSame(self::VENUE, (string) $h2['venue_id'], 'le gymnase vient du créneau de la rotation');

            // Les tables de rotation ont disparu.
            self::assertNull($this->admin->fetchOne('SELECT to_regclass(\'public.match_slot_rotation\')'));
            self::assertNull($this->admin->fetchOne('SELECT to_regclass(\'public.match_slot_rotation_team\')'));
        } finally {
            $this->admin->rollBack();
        }
    }

    public function testDedupKeepsTheMostRecentlyUpdatedHabitPerTeam(): void
    {
        $this->admin->beginTransaction();
        try {
            $this->restorePreMigrationSchema();

            // Une équipe avec DEUX habitudes (jours différents, permis par l'ancienne unicité) :
            // la dédup pour (club, saison, équipe) garde la plus récemment mise à jour.
            $team = Uuid::v4()->toRfc4122();
            $this->insertHabit($team, 6, '15:30', self::VENUE, '2026-01-01 10:00:00+00');
            $this->insertHabit($team, 7, '11:00', self::VENUE, '2026-02-01 10:00:00+00');

            $this->runMigration();

            $rows = $this->admin->fetchAllAssociative(
                'SELECT day_of_week, to_char(kickoff_time, \'HH24:MI\') AS k FROM team_match_habit WHERE team_id = ?',
                [$team],
            );
            self::assertCount(1, $rows, 'une seule habitude survit par équipe (nouvelle unicité)');
            self::assertSame(7, (int) $rows[0]['day_of_week'], 'la plus récemment mise à jour (dimanche) est gardée');
            self::assertSame('11:00', (string) $rows[0]['k']);
        } finally {
            $this->admin->rollBack();
        }
    }

    public function testAbortsOnARotationWithThreeOrMoreMembers(): void
    {
        $this->admin->beginTransaction();
        try {
            $this->restorePreMigrationSchema();

            $rotationId = $this->insertRotation(6, '20:30', self::VENUE);
            $this->insertRotationMember($rotationId, Uuid::v4()->toRfc4122(), 0);
            $this->insertRotationMember($rotationId, Uuid::v4()->toRfc4122(), 1);
            $this->insertRotationMember($rotationId, Uuid::v4()->toRfc4122(), 2);

            $migration = new Version20260929160000($this->admin, new NullLogger);
            $this->expectException(AbortMigration::class);
            $migration->up(new Schema);
        } finally {
            $this->admin->rollBack();
        }
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->admin = self::getContainer()->get('doctrine')->getConnection('admin');
    }

    /**
     * Restaure le schéma PRÉ-migration DANS la transaction : colonne `week` retirée, index unique
     * au grain jour, tables de rotation recréées (schéma minimal, le propriétaire bypasse la RLS).
     */
    private function restorePreMigrationSchema(): void
    {
        $this->admin->executeStatement('ALTER TABLE team_match_habit DROP COLUMN week');
        $this->admin->executeStatement('DROP INDEX uniq_team_match_habit_team');
        $this->admin->executeStatement('CREATE UNIQUE INDEX uniq_team_match_habit_day ON team_match_habit (club_id, season_id, team_id, day_of_week)');
        $this->admin->executeStatement('CREATE TABLE match_slot_rotation (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, venue_id UUID NOT NULL, day_of_week SMALLINT NOT NULL, kickoff_time TIME(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->admin->executeStatement('CREATE TABLE match_slot_rotation_team (id UUID NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, rotation_id UUID NOT NULL, team_id UUID NOT NULL, position INT NOT NULL, PRIMARY KEY (id))');
    }

    private function runMigration(): void
    {
        $migration = new Version20260929160000($this->admin, new NullLogger);
        $migration->up(new Schema);
        foreach ($migration->getSql() as $query) {
            $this->admin->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function insertHabit(string $teamId, int $day, string $kickoff, string $venueId, ?string $updatedAt = null): void
    {
        $this->admin->executeStatement(
            'INSERT INTO team_match_habit (id, version, created_at, updated_at, club_id, season_id, team_id, day_of_week, kickoff_time, venue_id) '
            . 'VALUES (?, 1, NOW(), ?, ?, ?, ?, ?, ?, ?)',
            [Uuid::v4()->toRfc4122(), $updatedAt ?? '2026-01-01 10:00:00+00', self::CLUB, self::SEASON, $teamId, $day, $kickoff, $venueId],
        );
    }

    private function insertRotation(int $day, string $kickoff, string $venueId): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->admin->executeStatement(
            'INSERT INTO match_slot_rotation (id, version, created_at, updated_at, club_id, season_id, venue_id, day_of_week, kickoff_time) '
            . 'VALUES (?, 1, NOW(), NOW(), ?, ?, ?, ?, ?)',
            [$id, self::CLUB, self::SEASON, $venueId, $day, $kickoff],
        );

        return $id;
    }

    private function insertRotationMember(string $rotationId, string $teamId, int $position): void
    {
        $this->admin->executeStatement(
            'INSERT INTO match_slot_rotation_team (id, club_id, season_id, rotation_id, team_id, position) VALUES (?, ?, ?, ?, ?, ?)',
            [Uuid::v4()->toRfc4122(), self::CLUB, self::SEASON, $rotationId, $teamId, $position],
        );
    }

    /**
     * @return array{week: string, day_of_week: int, kickoff: string, venue_id: string|null}|null
     */
    private function habitOf(string $teamId): ?array
    {
        $row = $this->admin->fetchAssociative(
            'SELECT week, day_of_week, to_char(kickoff_time, \'HH24:MI:SS\') AS kickoff, venue_id FROM team_match_habit WHERE team_id = ?',
            [$teamId],
        );

        return false === $row ? null : [
            'week' => (string) $row['week'],
            'day_of_week' => (int) $row['day_of_week'],
            'kickoff' => (string) $row['kickoff'],
            'venue_id' => null === $row['venue_id'] ? null : (string) $row['venue_id'],
        ];
    }
}
