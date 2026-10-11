<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Persistence\ManagerRegistry;
use DoctrineMigrations\Version20261011130000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR — lot A-2 (axe *planning lifecycle*) : la migration pose l'index UNIQUE
 * `schedule_slot_template(schedule_id, team_id, venue_id, day_of_week, start_time)` — une équipe ne
 * siège jamais deux fois à la même case — APRÈS avoir PURGÉ les doublons hérités. Le point
 * LOAD-BEARING est la purge : par groupe de case, elle GARDE la ligne la plus verrouillée
 * (HARD > SOFT > NONE), puis la plus ancienne (`created_at`), puis le plus petit `id`, et supprime
 * le reste ; sinon la création de l'index échouerait sur une base portant déjà des doublons.
 *
 * On exerce le VRAI SQL de la migration (via `getSql()`, jamais une copie) : `down()` retire l'index
 * (la base de test le porte déjà, la migration étant appliquée), on sème alors des doublons que
 * l'index interdisait, puis `up()` purge et recrée l'index. Deux groupes : l'un tranché par le VERROU
 * (HARD gardé), l'autre par l'ANCIENNETÉ (NONE le plus ancien gardé) ; une ligne d'une AUTRE case
 * survit intacte.
 *
 * ⚠ DDL : connexion `admin` (amateo_owner, hors RLS), dans une transaction OUVERTE ET ANNULÉE ici
 * même (`finally`) — rien n'est committé, la base de test partagée finit comme la branche l'attend.
 */
#[Group('integration')]
final class MigrationUniqueCaseIndexTest extends KernelTestCase
{
    private const string CLUB_ID = '11111111-1111-4111-8111-111111111111';

    private const string SEASON_ID = '22222222-2222-4222-8222-222222222222';

    private const string SCHEDULE_ID = '33333333-3333-4333-8333-333333333333';

    private const string TEAM_LOCK = '44444444-4444-4444-8444-444444444444';

    private const string TEAM_AGE = '55555555-5555-4555-8555-555555555555';

    private const string VENUE_ID = '66666666-6666-4666-8666-666666666666';

    public function testPurgesDuplicatesKeepingTheRightRowThenCreatesUniqueIndex(): void
    {
        self::bootKernel();
        $admin = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        self::assertInstanceOf(Connection::class, $admin);

        $admin->beginTransaction();

        try {
            // On retire l'index pour pouvoir semer des doublons que, justement, il interdit.
            $this->runMigration($admin, fn (Version20261011130000 $m) => $m->down(new Schema));
            self::assertFalse($this->indexExists($admin), 'down() retire l\'index UNIQUE');

            // Groupe 1 — tranché par le VERROU : HARD gardé, quelle que soit l'ancienneté.
            $this->insertSlot($admin, 'aaaaaaaa-0001-4000-8000-000000000001', self::TEAM_LOCK, 2, '19:00:00', 'NONE', null, '2026-01-01 10:00:00+00');
            $this->insertSlot($admin, 'aaaaaaaa-0002-4000-8000-000000000002', self::TEAM_LOCK, 2, '19:00:00', 'HARD', 'MANUAL', '2026-01-03 10:00:00+00');
            $this->insertSlot($admin, 'aaaaaaaa-0003-4000-8000-000000000003', self::TEAM_LOCK, 2, '19:00:00', 'NONE', null, '2026-01-02 10:00:00+00');

            // Groupe 2 — tous NONE, tranché par l'ANCIENNETÉ : le plus ancien created_at gardé.
            $this->insertSlot($admin, 'bbbbbbbb-0001-4000-8000-000000000001', self::TEAM_AGE, 2, '19:00:00', 'NONE', null, '2026-02-02 10:00:00+00');
            $this->insertSlot($admin, 'bbbbbbbb-0002-4000-8000-000000000002', self::TEAM_AGE, 2, '19:00:00', 'NONE', null, '2026-02-01 10:00:00+00');
            $this->insertSlot($admin, 'bbbbbbbb-0003-4000-8000-000000000003', self::TEAM_AGE, 2, '19:00:00', 'NONE', null, '2026-02-03 10:00:00+00');

            // Une ligne d'une AUTRE case (même équipe, autre jour) : hors doublon, elle survit.
            $this->insertSlot($admin, 'cccccccc-0001-4000-8000-000000000001', self::TEAM_LOCK, 4, '19:00:00', 'NONE', null, '2026-01-01 10:00:00+00');

            // La migration purge puis recrée l'index.
            $this->runMigration($admin, fn (Version20261011130000 $m) => $m->up(new Schema));

            // Groupe 1 : il ne reste que la ligne HARD.
            self::assertSame(['aaaaaaaa-0002-4000-8000-000000000002'], $this->survivorsOf($admin, self::TEAM_LOCK, 2), 'la ligne la plus verrouillée (HARD) est gardée, les NONE supprimées');
            // Groupe 2 : il ne reste que la plus ancienne.
            self::assertSame(['bbbbbbbb-0002-4000-8000-000000000002'], $this->survivorsOf($admin, self::TEAM_AGE, 2), 'la ligne la plus ancienne (created_at) est gardée');
            // La ligne d'une autre case n'est pas touchée.
            self::assertSame(['cccccccc-0001-4000-8000-000000000001'], $this->survivorsOf($admin, self::TEAM_LOCK, 4), 'une case différente n\'est pas un doublon : elle survit');

            // L'index existe ET est UNIQUE (le filet de base).
            self::assertTrue($this->indexExists($admin), 'up() recrée l\'index');
            self::assertTrue(
                (bool) $admin->fetchOne('SELECT indisunique FROM pg_index WHERE indexrelid = \'uniq_schedule_slot_template_case\'::regclass'),
                'l\'index est UNIQUE',
            );
        } finally {
            // Jamais de commit : l'aller-retour DDL et les lignes semées disparaissent.
            $admin->rollBack();
        }
    }

    private function indexExists(Connection $admin): bool
    {
        return '1' === (string) $admin->fetchOne('SELECT 1 FROM pg_indexes WHERE indexname = \'uniq_schedule_slot_template_case\'');
    }

    /**
     * Les ids restants à une case (équipe + jour), triés — ce que la purge a laissé.
     *
     * @return list<string>
     */
    private function survivorsOf(Connection $admin, string $teamId, int $day): array
    {
        /** @var list<string> $ids */
        $ids = $admin->fetchFirstColumn(
            'SELECT id FROM schedule_slot_template WHERE schedule_id = ? AND team_id = ? AND venue_id = ? AND day_of_week = ? AND start_time = ? ORDER BY id',
            [self::SCHEDULE_ID, $teamId, self::VENUE_ID, $day, '19:00:00'],
        );

        return $ids;
    }

    private function insertSlot(Connection $admin, string $id, string $teamId, int $day, string $start, string $lockLevel, ?string $lockOrigin, string $createdAt): void
    {
        $admin->executeStatement(
            'INSERT INTO schedule_slot_template (id, created_at, updated_at, club_id, season_id, schedule_id, team_id, venue_id, day_of_week, start_time, duration_minutes, lock_level, lock_origin) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $createdAt, $createdAt, self::CLUB_ID, self::SEASON_ID, self::SCHEDULE_ID, $teamId, self::VENUE_ID, $day, $start, 90, $lockLevel, $lockOrigin],
        );
    }

    /**
     * Exécute un sens de la migration sur une instance NEUVE (chaque Version accumule son plan),
     * donc on joue exactement ce que la migration déclare.
     *
     * @param callable(Version20261011130000): void $direction
     */
    private function runMigration(Connection $admin, callable $direction): void
    {
        $migration = new Version20261011130000($admin, new NullLogger);
        $direction($migration);
        foreach ($migration->getSql() as $query) {
            $admin->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
