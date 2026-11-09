<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Tests\Security\ReadOnlyRoleTest;
use App\Tests\Security\RlsIsolationTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\Persistence\ManagerRegistry;
use DoctrineMigrations\Version20261108120000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR de migration (charte docs/testing/tests-par-parcours.md §2, obligatoire jusqu'à l'application
 * en prod) : le DROP de la table orpheline `venue_unavailability` ne détruit jamais en silence une
 * indisponibilité saisie par un vrai club, et son retour arrière rend une table aussi fermée que
 * ses sœurs vivantes.
 *
 * - `up()` refuse (AbortMigration) tant qu'il reste une ligne d'un club HORS démo ou d'un club qui
 *   n'existe plus ; seules les lignes de clubs de démo partent avec la table.
 * - `down()` recrée la table VIDE avec exactement les policies (`tenant_isolation`, `admin_all`,
 *   `readonly_tenant`), la RLS ENABLE + FORCE et les GRANT de `venue_match_window`, sa sœur née dans
 *   la même migration ; le prédicat tenant est lu dans le schéma vivant (`team_tag`), jamais recopié.
 *
 * On joue TOUTES les requêtes de `getSql()` (DDL compris) sur la connexion `admin` (celle des
 * migrations, `doctrine_migrations.yaml`), dans une transaction ANNULÉE en `finally` : le DDL
 * PostgreSQL est transactionnel. La table est donc recréée dans la base de test PARTAGÉE, sous verrou
 * exclusif le temps du test — sûr parce que la suite PHPUnit est séquentielle (pas de paratest).
 * La base de test est migrée : la table y est absente au départ.
 *
 * L'isolation des tables vivantes reste prouvée par {@see RlsIsolationTest} et {@see ReadOnlyRoleTest}
 * (énumération dynamique : rien n'y nomme la table disparue).
 */
#[Group('integration')]
final class VenueUnavailabilityDropMigrationTest extends KernelTestCase
{
    private const string DEMO_CLUB = 'd0d0d0d0-d0d0-4d0d-8d0d-d0d0d0d0d0d0';
    private const string REAL_CLUB = 'e1e1e1e1-e1e1-4e1e-8e1e-e1e1e1e1e1e1';
    private const string GONE_CLUB = 'f2f2f2f2-f2f2-4f2f-8f2f-f2f2f2f2f2f2';

    /** Forme de la table au moment du retrait de l'entité (Version20260803090000 l. 42-44). */
    private const array EXPECTED_COLUMNS = [
        ['column_name' => 'id', 'data_type' => 'uuid', 'is_nullable' => 'NO', 'column_default' => null, 'character_maximum_length' => null, 'datetime_precision' => null],
        ['column_name' => 'version', 'data_type' => 'integer', 'is_nullable' => 'NO', 'column_default' => '1', 'character_maximum_length' => null, 'datetime_precision' => null],
        ['column_name' => 'created_at', 'data_type' => 'timestamp with time zone', 'is_nullable' => 'NO', 'column_default' => null, 'character_maximum_length' => null, 'datetime_precision' => 0],
        ['column_name' => 'updated_at', 'data_type' => 'timestamp with time zone', 'is_nullable' => 'NO', 'column_default' => null, 'character_maximum_length' => null, 'datetime_precision' => 0],
        ['column_name' => 'club_id', 'data_type' => 'uuid', 'is_nullable' => 'NO', 'column_default' => null, 'character_maximum_length' => null, 'datetime_precision' => null],
        ['column_name' => 'season_id', 'data_type' => 'uuid', 'is_nullable' => 'NO', 'column_default' => null, 'character_maximum_length' => null, 'datetime_precision' => null],
        ['column_name' => 'venue_id', 'data_type' => 'uuid', 'is_nullable' => 'NO', 'column_default' => null, 'character_maximum_length' => null, 'datetime_precision' => null],
        ['column_name' => 'start_date', 'data_type' => 'date', 'is_nullable' => 'NO', 'column_default' => null, 'character_maximum_length' => null, 'datetime_precision' => 0],
        ['column_name' => 'end_date', 'data_type' => 'date', 'is_nullable' => 'NO', 'column_default' => null, 'character_maximum_length' => null, 'datetime_precision' => 0],
        ['column_name' => 'label', 'data_type' => 'character varying', 'is_nullable' => 'YES', 'column_default' => 'NULL::character varying', 'character_maximum_length' => 180, 'datetime_precision' => null],
    ];

    private const array EXPECTED_INDEXES = [
        'CREATE INDEX idx_venue_unavailability_club_season ON public.venue_unavailability USING btree (club_id, season_id)',
        'CREATE INDEX idx_venue_unavailability_venue ON public.venue_unavailability USING btree (venue_id)',
        'CREATE UNIQUE INDEX venue_unavailability_pkey ON public.venue_unavailability USING btree (id)',
    ];

    private Connection $admin;

    public function testDownRecreatesAnEmptyTableAsLockedDownAsItsLiveSister(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->play('down');

            self::assertTrue($this->tableExists(), 'down() recrée la table');
            self::assertSame(0, (int) $this->admin->fetchOne('SELECT count(*) FROM venue_unavailability'), 'la table revient VIDE');
            self::assertSame(self::EXPECTED_COLUMNS, $this->columns(), 'les colonnes de la table retirée, à l\'identique');
            self::assertSame(self::EXPECTED_INDEXES, $this->indexes(), 'la clé primaire et les deux index d\'origine');

            self::assertSame(
                $this->policies('venue_match_window'),
                $this->policies('venue_unavailability'),
                'mêmes policies que la sœur vivante : tenant_isolation, admin_all, readonly_tenant (rôles, commande, prédicats)',
            );
            self::assertCount(3, $this->policies('venue_unavailability'), 'témoin : la sœur porte bien ses trois policies');
            self::assertSame(
                $this->rlsFlags('venue_match_window'),
                $this->rlsFlags('venue_unavailability'),
                'RLS ENABLE + FORCE comme la sœur',
            );
            self::assertSame([true, true], $this->rlsFlags('venue_unavailability'), 'témoin : ENABLE et FORCE posés');
            self::assertSame(
                $this->grants('venue_match_window'),
                $this->grants('venue_unavailability'),
                'mêmes GRANT que la sœur (amateo_app en DML, amateo_read en lecture, propriétaire)',
            );
        });
    }

    public function testDownCopiesTheTenantPredicateFromTheLiveSchemaNotFromALiteral(): void
    {
        $this->inRolledBackTransaction(function (): void {
            // Le canon a bougé depuis août : le retour arrière doit suivre le schéma vivant.
            $this->admin->executeStatement('ALTER POLICY tenant_isolation ON public.team_tag USING (club_id IS NOT NULL) WITH CHECK (club_id IS NOT NULL)');

            $this->play('down');

            $quals = $this->admin->fetchAllKeyValue(
                'SELECT policyname, qual FROM pg_policies WHERE schemaname = \'public\' AND tablename = \'venue_unavailability\' AND policyname IN (\'tenant_isolation\', \'readonly_tenant\') ORDER BY policyname',
            );
            self::assertSame(
                ['readonly_tenant' => '(club_id IS NOT NULL)', 'tenant_isolation' => '(club_id IS NOT NULL)'],
                $quals,
                'le prédicat est lu sur team_tag.tenant_isolation au moment du down(), jamais le littéral d\'août',
            );
        });
    }

    public function testDownRefusesWhenTheReferenceTenantPolicyIsMissing(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->admin->executeStatement('DROP POLICY tenant_isolation ON public.team_tag');

            try {
                $this->play('down');
                self::fail('sans policy de référence, down() doit refuser plutôt que poser une table sans prédicat');
            } catch (AbortMigration) {
                self::assertFalse($this->tableExists(), 'refus AVANT toute création');
            }
        });
    }

    public function testUpDropsTheTableWhenOnlyDemoClubRowsRemain(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->play('down');
            $this->insertClub(self::DEMO_CLUB, true);
            $this->insertUnavailability(self::DEMO_CLUB);

            $this->play('up');

            self::assertFalse($this->tableExists(), 'les lignes d\'un club de démo partent avec la table');
        });
    }

    public function testUpRefusesWhileARealClubStillHasAnUnavailability(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->play('down');
            $this->insertClub(self::DEMO_CLUB, true);
            $this->insertClub(self::REAL_CLUB, false);
            $this->insertUnavailability(self::DEMO_CLUB);
            $this->insertUnavailability(self::REAL_CLUB);

            $message = $this->abortMessageOfUp();

            self::assertStringContainsString('1 indisponibilité', $message, 'compte les seules lignes hors démo (la ligne de démo ne compte pas)');
            self::assertStringContainsStringIgnoringCase('fermeture', $message, 'le message nomme le geste : re-saisir comme fermeture du calendrier');
            self::assertTrue($this->tableExists(), 'refus AVANT le DROP : la donnée du club survit');
        });
    }

    public function testUpRefusesARowWhoseClubNoLongerExists(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->play('down');
            $this->insertUnavailability(self::GONE_CLUB);

            self::assertStringContainsString('1 indisponibilité', $this->abortMessageOfUp(), 'pas de FK : une ligne orpheline d\'un club inconnu n\'est pas une ligne de démo');
            self::assertTrue($this->tableExists(), 'refus AVANT le DROP');
        });
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        self::assertInstanceOf(Connection::class, $connection);
        $this->admin = $connection;
    }

    /** Tout le test dans une transaction de la connexion admin, toujours annulée (DDL compris). */
    private function inRolledBackTransaction(callable $scenario): void
    {
        $this->admin->beginTransaction();
        try {
            self::assertFalse($this->tableExists(), 'précondition : la base de test est migrée, la table est absente');
            $scenario();
        } finally {
            $this->admin->rollBack();
        }
    }

    /** Une instance NEUVE par sens (getSql() cumule), TOUTES ses requêtes jouées sur la connexion admin. */
    private function play(string $direction): void
    {
        $migration = new Version20261108120000($this->admin, new NullLogger);
        'up' === $direction ? $migration->up(new Schema) : $migration->down(new Schema);
        $queries = $migration->getSql();
        self::assertNotEmpty($queries, \sprintf('%s() doit planifier ses requêtes par addSql', $direction));
        foreach ($queries as $query) {
            $this->admin->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function abortMessageOfUp(): string
    {
        try {
            $this->play('up');
        } catch (AbortMigration $abort) {
            return $abort->getMessage();
        }
        self::fail('up() doit refuser (AbortMigration) tant qu\'une ligne hors démo subsiste');
    }

    private function insertClub(string $id, bool $demo): void
    {
        $this->admin->executeStatement(
            'INSERT INTO club (id, version, created_at, updated_at, name, slug, timezone, locale, onboarding_completed, generation_count_season, is_demo) VALUES (?, 1, now(), now(), ?, ?, \'Europe/Paris\', \'fr\', true, 0, ?)',
            [$id, 'Drop VU ' . $id, 'drop-vu-' . $id, $demo ? 'true' : 'false'],
        );
    }

    private function insertUnavailability(string $clubId): void
    {
        $this->admin->executeStatement(
            'INSERT INTO venue_unavailability (id, version, created_at, updated_at, club_id, season_id, venue_id, start_date, end_date, label) VALUES (gen_random_uuid(), 1, now(), now(), ?, gen_random_uuid(), gen_random_uuid(), \'2026-12-21\', \'2026-12-31\', \'Travaux\')',
            [$clubId],
        );
    }

    private function tableExists(): bool
    {
        return null !== $this->admin->fetchOne('SELECT to_regclass(\'public.venue_unavailability\')');
    }

    /** @return list<array<string, mixed>> */
    private function columns(): array
    {
        return $this->admin->fetchAllAssociative(
            'SELECT column_name, data_type, is_nullable, column_default, character_maximum_length, datetime_precision FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = \'venue_unavailability\' ORDER BY ordinal_position',
        );
    }

    /** @return list<string> */
    private function indexes(): array
    {
        /** @var list<string> $indexes */
        $indexes = $this->admin->fetchFirstColumn('SELECT indexdef FROM pg_indexes WHERE schemaname = \'public\' AND tablename = \'venue_unavailability\' ORDER BY indexname');

        return $indexes;
    }

    /** @return list<array<string, mixed>> */
    private function policies(string $table): array
    {
        return $this->admin->fetchAllAssociative(
            'SELECT policyname, roles::text AS roles, cmd, permissive, qual, with_check FROM pg_policies WHERE schemaname = \'public\' AND tablename = ? ORDER BY policyname',
            [$table],
        );
    }

    /** @return list<mixed> */
    private function rlsFlags(string $table): array
    {
        $row = $this->admin->fetchAssociative(
            'SELECT c.relrowsecurity, c.relforcerowsecurity FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = \'public\' AND c.relname = ?',
            [$table],
        );
        self::assertIsArray($row, \sprintf('table %s introuvable', $table));

        return array_values($row);
    }

    /** @return list<array<string, mixed>> */
    private function grants(string $table): array
    {
        return $this->admin->fetchAllAssociative(
            'SELECT grantee, privilege_type, is_grantable, grantor FROM information_schema.role_table_grants WHERE table_schema = \'public\' AND table_name = ? ORDER BY grantee, privilege_type',
            [$table],
        );
    }
}
