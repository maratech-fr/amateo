<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Tests\FrozenSchemaDatabaseTrait;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse : la conversion « compétition et engagement » (lot 7, PR 3b, plan §9) ne réunit rien et ne perd rien —
 * une ligne d'engagement par ligne de compétition, aucune compétition ne change d'identifiant ni de nom, aucun match
 * n'est repointé ; elle s'arrête, sans rien écrire, plutôt que de fabriquer un engagement (§12).
 *
 * Base dédiée née du schéma figé d'avant la PR 3b (`tests/Fixtures/lot-7/schema-avant.sql`), décor de la référence 16
 * (deux équipes dans « DF2 », `tests/Fixtures/lot-7/decor-avant.sql`), retouché en SQL par chaque cas dans le schéma
 * d'avant, puis `doctrine:migrations:migrate`. La migration est irréversible : la transaction annulée des autres tests
 * de migration ne s'applique pas ({@see FrozenSchemaDatabaseTrait}).
 *
 * Jumeau : `Lot7MigrationChainTest` prouve sur les mêmes décors que la demande de placement ne change pas. NR de
 * migration de données : obligatoire jusqu'à l'application en production (charte des tests, §2 PHPUnit). Bloquant
 * (step `Blocking suite` de `ci.yml`, `docs/testing/blocking-tests.md`) : le groupe `phase1` est requis.
 */
#[Group('phase1')]
#[Group('integration')]
#[SkipDatabaseRollback]
final class CompetitionEngagementMigrationTest extends KernelTestCase
{
    use FrozenSchemaDatabaseTrait;

    private const string FROZEN_SCHEMA_FILE = __DIR__ . '/../../Fixtures/lot-7/schema-avant.sql';

    private const string FROZEN_DECOR_FILE = __DIR__ . '/../../Fixtures/lot-7/decor-avant.sql';

    private const string FROZEN_DATABASE_SUFFIX = 'lot7_engagement';

    private const string DECOR = 'placement-16-deux-equipes-une-division';

    private const string CLUB = 'c10b0000-0000-4000-8000-000000000001';

    private const string SEASON = '5ea50000-0000-4000-8000-000000000001';

    private const string U13 = '7ea00000-0000-4000-8000-000000000001';

    private const string U15 = '7ea00000-0000-4000-8000-000000000002';

    private const string U13_F2 = '7ea00000-0000-4000-8000-000000000016';

    /** « DF2 » de U13 F et de U13 F2 : deux lignes de même nom, deux équipes. */
    private const string DF2_U13 = 'c0e90000-0000-4000-8000-000000000161';

    private const string DF2_U13_F2 = 'c0e90000-0000-4000-8000-000000000162';

    /** Les six colonnes qui quittent la compétition pour l'engagement. */
    private const array MOVED_COLUMNS = ['team_id', 'fbi_team_label', 'ffbb_poule_id', 'ffbb_poule_name', 'ffbb_poule_opponents', 'expected_matchdays'];

    /**
     * Une ligne seule, et deux lignes de même nom pour deux équipes : chaque compétition garde son identifiant et son
     * nom et reçoit UN engagement, celui de son ancienne équipe ; les deux « DF2 » restent deux compétitions ; aucun
     * match ne change d'équipe ni de compétition.
     */
    public function testEveryCompetitionGetsItsOwnEngagementAndNothingIsMerged(): void
    {
        $admin = self::frozenCaseConnection();
        $before = $admin->fetchAllAssociativeIndexed('SELECT id, name, team_id, club_id, season_id FROM competition ORDER BY id');
        $fixturesBefore = $admin->fetchAllKeyValue('SELECT id, concat_ws(\'|\', team_id, competition_id) FROM fixture ORDER BY id');
        self::assertSame(2, (int) $admin->fetchOne('SELECT COUNT(*) FROM competition WHERE name = \'DF2\''), 'témoin : deux lignes « DF2 »');
        self::assertCount(5, $before, 'témoin : cinq compétitions dans le décor');

        $this->migrateOrFail();

        self::assertSame(
            array_map(static fn (array $row): string => (string) $row['name'], $before),
            $admin->fetchAllKeyValue('SELECT id, name FROM competition ORDER BY id'),
            'mêmes compétitions, mêmes identifiants, mêmes noms : rien n\'est réuni ni ajouté',
        );
        $engagements = $admin->fetchAllAssociative('SELECT competition_id, team_id, club_id, season_id FROM engagement ORDER BY competition_id');
        self::assertSame(
            array_map(static fn (string $id, array $row): array => ['competition_id' => $id, 'team_id' => $row['team_id'], 'club_id' => $row['club_id'], 'season_id' => $row['season_id']], array_keys($before), $before),
            $engagements,
            'un engagement par compétition : son ancienne équipe, son club, sa saison',
        );
        self::assertSame(
            [self::DF2_U13 => self::U13, self::DF2_U13_F2 => self::U13_F2],
            $admin->fetchAllKeyValue('SELECT e.competition_id, e.team_id FROM engagement e JOIN competition c ON c.id = e.competition_id WHERE c.name = \'DF2\' ORDER BY e.competition_id'),
            'les deux « DF2 » restent deux compétitions, une par équipe',
        );
        self::assertSame($fixturesBefore, $admin->fetchAllKeyValue('SELECT id, concat_ws(\'|\', team_id, competition_id) FROM fixture ORDER BY id'), 'aucun match n\'est repointé');
    }

    /** Poule, journées attendues, libellé FBI et échéance survivent à la conversion, chacun à sa nouvelle place. */
    public function testPouleMatchdaysLabelAndDeadlineAreKept(): void
    {
        $admin = self::frozenCaseConnection();
        $admin->executeStatement(
            'UPDATE competition SET ffbb_poule_id = \'P-161\', ffbb_poule_name = \'Poule A\', ffbb_poule_opponents = \'["Adversaire 1","Adversaire 2"]\', expected_matchdays = 10,'
            . ' entry_deadline = \'2026-10-15\', ffbb_competition_id = \'FFBB-161\', ffbb_competition_name = \'Départementale féminine 2\','
            . ' start_date = \'2026-09-01\', end_date = \'2027-05-31\' WHERE id = ?',
            [self::DF2_U13],
        );
        self::assertSame('CLUB DE REFERENCE - 1', $admin->fetchOne('SELECT fbi_team_label FROM competition WHERE id = ?', [self::DF2_U13]), 'témoin : le libellé FBI d\'équipe est posé');

        $this->migrateOrFail();

        self::assertSame(
            ['team_id' => self::U13, 'fbi_team_label' => 'CLUB DE REFERENCE - 1', 'ffbb_poule_id' => 'P-161', 'ffbb_poule_name' => 'Poule A', 'ffbb_poule_opponents' => ['Adversaire 1', 'Adversaire 2'], 'expected_matchdays' => 10],
            $this->engagementOf($admin, self::DF2_U13),
            'l\'engagement porte l\'équipe, son libellé FBI, la poule et les journées attendues',
        );
        self::assertSame(
            ['name' => 'DF2', 'competition_type' => 'CHAMPIONSHIP', 'entry_deadline' => '2026-10-15', 'ffbb_competition_id' => 'FFBB-161', 'ffbb_competition_name' => 'Départementale féminine 2', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31'],
            $admin->fetchAssociative('SELECT name, competition_type, entry_deadline::text AS entry_deadline, ffbb_competition_id, ffbb_competition_name, start_date::text AS start_date, end_date::text AS end_date FROM competition WHERE id = ?', [self::DF2_U13]),
            'la compétition garde son nom, sa nature, son échéance, ses dates et ses références fédérales',
        );
        self::assertSame(
            ['team_id' => self::U13_F2, 'fbi_team_label' => 'CLUB DE REFERENCE - 2', 'ffbb_poule_id' => null, 'ffbb_poule_name' => null, 'ffbb_poule_opponents' => null, 'expected_matchdays' => null],
            $this->engagementOf($admin, self::DF2_U13_F2),
            'une ligne sans poule donne un engagement sans poule',
        );
    }

    /**
     * Après la conversion, la structure est celle du contrat : six colonnes parties, plus aucune unicité sur le nom,
     * un engagement par (compétition, équipe), une clé (compétition, club) en cascade vers la compétition, une clé
     * (compétition, équipe, club) du match vers l'engagement qui interdit de supprimer un engagement porté par un
     * match, aucune clé vers l'équipe (E3), la RLS active et forcée.
     */
    public function testTheConversionLeavesTheContractStructure(): void
    {
        $admin = self::frozenCaseConnection();

        $this->migrateOrFail();

        foreach (self::MOVED_COLUMNS as $column) {
            self::assertFalse(self::frozenColumnExists($admin, 'competition', $column), \sprintf('competition.%s est retirée', $column));
        }
        foreach (['club_id', 'season_id', 'competition_id', 'team_id'] as $column) {
            self::assertSame('NO', $admin->fetchOne('SELECT is_nullable FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = \'engagement\' AND column_name = ?', [$column]), \sprintf('engagement.%s est obligatoire', $column));
        }
        self::assertSame(0, (int) $admin->fetchOne(
            'SELECT COUNT(*) FROM pg_index i WHERE i.indrelid = \'public.competition\'::regclass AND i.indisunique AND NOT i.indisprimary'
            . ' AND EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey::int2[]) AND a.attname = \'name\')',
        ), 'plus aucune unicité sur le nom d\'une compétition');
        self::assertSame(1, (int) $admin->fetchOne(
            'SELECT COUNT(*) FROM pg_index i WHERE i.indrelid = \'public.engagement\'::regclass AND i.indisunique AND i.indpred IS NULL'
            . ' AND (SELECT array_agg(a.attname::text ORDER BY a.attname) FROM pg_attribute a WHERE a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey::int2[])) = ARRAY[\'competition_id\', \'team_id\']',
        ), 'un engagement par couple (compétition, équipe)');
        self::assertSame(1, $this->foreignKeys($admin, 'engagement', 'competition', ['club_id', 'competition_id'], 'c'), 'une clé composée (compétition, club) vers la compétition, en cascade : un engagement ne désigne jamais la compétition d\'un autre club');
        self::assertSame(1, $this->foreignKeys($admin, 'fixture', 'engagement', ['club_id', 'competition_id', 'team_id'], 'r'), 'une clé composée (compétition, équipe, club) du match vers l\'engagement, ON DELETE RESTRICT');
        self::assertSame(0, (int) $admin->fetchOne('SELECT COUNT(*) FROM pg_constraint c WHERE c.contype = \'f\' AND c.conrelid = \'public.engagement\'::regclass AND c.confrelid = \'public.team\'::regclass'), 'aucune clé vers l\'équipe (E3)');
        self::assertSame(1, (int) $admin->fetchOne('SELECT COUNT(*) FROM pg_class WHERE oid = \'public.engagement\'::regclass AND relrowsecurity AND relforcerowsecurity'), 'la RLS est active et forcée');
    }

    /**
     * Arrêt : un match dont l'équipe n'est pas celle de sa compétition n'a aucun engagement où se ranger. La
     * conversion s'annule : aucune table neuve, la compétition garde son équipe, la migration n'est pas enregistrée.
     */
    public function testAFixtureWhoseTeamIsNotItsCompetitionsStopsTheConversion(): void
    {
        $admin = self::frozenCaseConnection();
        $admin->executeStatement('UPDATE fixture SET team_id = ? WHERE id = ?', [self::U15, 'f1c70000-0000-4000-8000-000000000161']);
        self::assertSame(1, (int) $admin->fetchOne('SELECT COUNT(*) FROM fixture f JOIN competition c ON c.id = f.competition_id WHERE f.team_id <> c.team_id'), 'témoin : un match hors de l\'équipe de sa compétition');

        // Le motif nommé, pas seulement un échec : sans l'arrêt, la clé du match vers l'engagement ferait échouer la
        // conversion aussi, sans dire pourquoi.
        $this->assertTheConversionIsCancelled($admin, 'hors de l\'équipe de leur compétition');
    }

    /**
     * Arrêt : deux lignes d'une même équipe dont les noms ne diffèrent que par la casse ou les accents deviendraient
     * deux compétitions que la porte normalisée ne sait plus départager. La conversion s'annule sans rien écrire.
     * Témoin contraire, dans le décor : U13 F porte « Championnat 1 » et « DF2 », deux noms vraiment distincts.
     */
    #[TestWith(['DF3', 'df3'])]
    #[TestWith(['Régionale 2', 'REGIONALE 2'])]
    public function testTwoNamesOfOneTeamEqualOnceNormalisedStopTheConversion(string $name, string $twin): void
    {
        $admin = self::frozenCaseConnection();
        foreach (['c0e90000-0000-4000-8000-0000000000a1' => $name, 'c0e90000-0000-4000-8000-0000000000a2' => $twin] as $id => $label) {
            $admin->executeStatement(
                'INSERT INTO competition SELECT * FROM json_populate_record(NULL::competition, (SELECT (row_to_json(c)::jsonb || jsonb_build_object(\'id\', ?::text, \'name\', ?::text, \'team_id\', ?::text, \'fbi_team_label\', NULL))::json FROM competition c WHERE c.id = ?))',
                [$id, $label, self::U15, self::DF2_U13],
            );
        }
        self::assertSame(2, (int) $admin->fetchOne('SELECT COUNT(*) FROM competition WHERE team_id = ? AND id IN (\'c0e90000-0000-4000-8000-0000000000a1\', \'c0e90000-0000-4000-8000-0000000000a2\')', [self::U15]), 'témoin : deux lignes de U15 M, noms jumeaux');

        $this->assertTheConversionIsCancelled($admin, 'ne diffèrent que par la casse ou les accents');
    }

    protected function setUp(): void
    {
        $this->setUpFrozenCase(self::DECOR);
    }

    protected function tearDown(): void
    {
        $this->tearDownFrozenCase();
        parent::tearDown();
    }

    private function migrateOrFail(): void
    {
        [$code, $output, $played] = $this->runFrozenMigrations();
        self::assertSame(0, $code, 'la migration se joue sur le décor d\'avant la PR 3b : ' . $output);
        self::assertNotSame([], $played, 'la migration de la PR 3b est jouée');
    }

    private function assertTheConversionIsCancelled(Connection $admin, string $reason): void
    {
        $competitions = (int) $admin->fetchOne('SELECT COUNT(*) FROM competition');

        [$code, $output, $played] = $this->runFrozenMigrations();

        self::assertNotSame(0, $code, 'la conversion refuse ce décor : ' . $output);
        self::assertStringContainsString($reason, $output, 'l\'arrêt nomme son motif');
        self::assertSame([], $played, 'la migration n\'est pas enregistrée');
        self::assertNull($admin->fetchOne('SELECT to_regclass(\'public.engagement\')'), 'aucun engagement n\'est écrit');
        self::assertTrue(self::frozenColumnExists($admin, 'competition', 'team_id'), 'la compétition garde son équipe');
        self::assertSame($competitions, (int) $admin->fetchOne('SELECT COUNT(*) FROM competition'), 'aucune compétition n\'est touchée');
    }

    /** @return array<string, mixed>|false */
    private function engagementOf(Connection $admin, string $competitionId): array|false
    {
        $row = $admin->fetchAssociative('SELECT team_id, fbi_team_label, ffbb_poule_id, ffbb_poule_name, ffbb_poule_opponents, expected_matchdays FROM engagement WHERE competition_id = ?', [$competitionId]);
        if (false !== $row) {
            $row['ffbb_poule_opponents'] = null === $row['ffbb_poule_opponents'] ? null : json_decode((string) $row['ffbb_poule_opponents'], true, 512, \JSON_THROW_ON_ERROR);
            $row['expected_matchdays'] = null === $row['expected_matchdays'] ? null : (int) $row['expected_matchdays'];
        }

        return $row;
    }

    /** @param list<string> $columns colonnes de la clé, triées */
    private function foreignKeys(Connection $admin, string $from, string $to, array $columns, string $onDelete): int
    {
        return (int) $admin->fetchOne(
            'SELECT COUNT(*) FROM pg_constraint c WHERE c.contype = \'f\' AND c.conrelid = (\'public.\' || ?)::regclass AND c.confrelid = (\'public.\' || ?)::regclass AND c.confdeltype = ?'
            . ' AND (SELECT array_agg(a.attname::text ORDER BY a.attname) FROM pg_attribute a WHERE a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)) = ?::text[]',
            [$from, $to, $onDelete, '{' . implode(',', $columns) . '}'],
        );
    }
}
