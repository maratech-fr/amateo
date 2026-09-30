<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260930100000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * NR — la migration P4-271 (deux semaines A/B + réglage club) fait DEUX choses dans un
 * ORDRE qui ne doit pas bouger :
 *   (b) elle ALLUME `club.weekend_alternates` pour tout club qui possédait DÉJÀ un créneau
 *       idéal tagué A ou B (trace de l'alternance) — AVANT
 *   (c) de faire retomber les créneaux restés `ALL` sur la semaine A.
 * Si (c) passait avant (b), les `ALL` devenus `A` feraient croire que TOUS les clubs
 * utilisaient l'alternance : le club « sans alternance » ressortirait à tort à TRUE.
 *
 * On exécute le VRAI SQL de la migration (`getSql()`, jamais une copie) sous le PROPRIÉTAIRE
 * (amateo_owner, comme en production, qui bypasse la RLS). La migration ayant déjà tourné sur
 * la base de test, on remet l'état PRÉ-migration au sein d'une TRANSACTION admin explicite, on
 * rejoue, on assert, puis on ROLLBACK — le DDL Postgres est transactionnel.
 */
#[Group('integration')]
final class MigrationWeekendAlternatesTest extends KernelTestCase
{
    private const string SEASON = 'cccccccc-0000-4000-8000-000000000001';
    private const string TEAM_ALT = 'cccccccc-0000-4000-8000-000000000010';
    private const string TEAM_EXPLICIT_A = 'cccccccc-0000-4000-8000-000000000011';
    private const string TEAM_ALL = 'cccccccc-0000-4000-8000-000000000012';

    private Connection $admin;

    public function testAllHabitsCollapseToWeekAAndOnlyAlternatingClubsAreFlagged(): void
    {
        $this->admin->beginTransaction();
        try {
            $this->restorePreMigrationSchema();

            // Club qui utilisait l'alternance : un créneau tagué B.
            $clubAlt = $this->insertClub('alt');
            $this->insertHabit($clubAlt, self::TEAM_ALT, 6, '15:30', 'B');

            // Club qui utilisait l'alternance via un A explicite.
            $clubExplicitA = $this->insertClub('explicit-a');
            $this->insertHabit($clubExplicitA, self::TEAM_EXPLICIT_A, 6, '17:00', 'A');

            // Club SANS alternance : uniquement des créneaux « ALL ».
            $clubAll = $this->insertClub('all');
            $this->insertHabit($clubAll, self::TEAM_ALL, 7, '11:00', 'ALL');

            $this->runMigration();

            // (b) le réglage suit l'usage passé de l'alternance.
            self::assertTrue($this->weekendAlternatesOf($clubAlt), 'un club avec un créneau B est marqué comme alternant');
            self::assertTrue($this->weekendAlternatesOf($clubExplicitA), 'un club avec un créneau A explicite est marqué comme alternant');
            // (c) après (b) : le club « ALL » n\'alterne PAS — la preuve que (b) a bien
            //     tourné AVANT la conversion ALL → A.
            self::assertFalse($this->weekendAlternatesOf($clubAll), 'un club sans alternance (que des ALL) reste à false');

            // (c) les créneaux « ALL » retombent en A ; les A/B explicites sont intacts.
            self::assertSame('A', $this->weekOf(self::TEAM_ALL), 'un créneau ALL retombe sur la semaine A');
            self::assertSame('B', $this->weekOf(self::TEAM_ALT), 'un créneau B est conservé');
            self::assertSame('A', $this->weekOf(self::TEAM_EXPLICIT_A), 'un créneau A est conservé');

            // (d) le défaut EFFECTIF de la colonne est A : un insert sans `week` donne A.
            $teamDefault = Uuid::v4()->toRfc4122();
            $this->admin->executeStatement(
                'INSERT INTO team_match_habit (id, version, created_at, updated_at, club_id, season_id, team_id, day_of_week, kickoff_time) '
                . 'VALUES (?, 1, NOW(), NOW(), ?, ?, ?, ?, ?)',
                [Uuid::v4()->toRfc4122(), $clubAll, self::SEASON, $teamDefault, 5, '18:00'],
            );
            self::assertSame('A', $this->weekOf($teamDefault), 'le défaut de la colonne week est désormais A');
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
     * Restaure l'état PRÉ-migration DANS la transaction : colonne `weekend_alternates`
     * retirée, défaut de `week` remis à `ALL`. `IF EXISTS` pour tolérer une base non
     * encore migrée (exécution locale avant `db-init`).
     */
    private function restorePreMigrationSchema(): void
    {
        $this->admin->executeStatement('ALTER TABLE club DROP COLUMN IF EXISTS weekend_alternates');
        $this->admin->executeStatement('ALTER TABLE team_match_habit ALTER COLUMN week SET DEFAULT \'ALL\'');
    }

    private function runMigration(): void
    {
        $migration = new Version20260930100000($this->admin, new NullLogger);
        $migration->up(new Schema);
        foreach ($migration->getSql() as $query) {
            $this->admin->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function insertClub(string $suffix): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->admin->executeStatement(
            'INSERT INTO club (id, created_at, updated_at, name, slug, generation_count_season, timezone, locale, onboarding_completed) '
            . 'VALUES (?, NOW(), NOW(), ?, ?, 0, \'Europe/Paris\', \'fr\', FALSE)',
            [$id, 'club-' . $suffix, 'club-' . $suffix . '-' . substr($id, 0, 8)],
        );

        return $id;
    }

    private function insertHabit(string $clubId, string $teamId, int $day, string $kickoff, string $week): void
    {
        $this->admin->executeStatement(
            'INSERT INTO team_match_habit (id, version, created_at, updated_at, club_id, season_id, team_id, day_of_week, kickoff_time, week) '
            . 'VALUES (?, 1, NOW(), NOW(), ?, ?, ?, ?, ?, ?)',
            [Uuid::v4()->toRfc4122(), $clubId, self::SEASON, $teamId, $day, $kickoff, $week],
        );
    }

    private function weekendAlternatesOf(string $clubId): bool
    {
        return (bool) $this->admin->fetchOne('SELECT weekend_alternates FROM club WHERE id = ?', [$clubId]);
    }

    private function weekOf(string $teamId): string
    {
        return (string) $this->admin->fetchOne('SELECT week FROM team_match_habit WHERE team_id = ?', [$teamId]);
    }
}
