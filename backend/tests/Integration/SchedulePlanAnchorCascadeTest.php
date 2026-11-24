<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\TenantGucTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR — supprimer un plan emporte ses lignes ancrées, et RIEN d'autre (P4-30).
 *
 * `Reservation.schedulePlanId` et `VenueTrainingSlot.schedulePlanId` étaient des
 * colonnes `guid` nues, sans FK : `deletePeriodPlan` fait un `DELETE FROM
 * schedule_plan` sec, donc chaque suppression laissait des lignes à l'ancre
 * pendante, invisibles mais éternelles.
 *
 * Ce test tient les bords qui comptent :
 *  1. la contrainte EXISTE et casse bien en CASCADE, sur chaque table qui porte un plan (lot 4, PR 2 :
 *     les six familles du socle et les trois réglages de période) — Doctrine ignore ces FK (colonne
 *     nue), donc `migration-diff` propose de les DROPper ; sans cette assertion, un `migration-diff`
 *     committé sans relecture les ferait disparaître en silence et la dette reviendrait ;
 *  2. la cascade emporte les lignes du plan supprimé ;
 *  3. les lignes d'un AUTRE plan survivent — dont celles du socle, qui depuis le lot 4 portent le plan
 *     de saison (le survivant ci-dessous) : c'est l'inverse du piège `ON DELETE SET NULL`.
 */
#[Group('phase1')]
final class SchedulePlanAnchorCascadeTest extends KernelTestCase
{
    use TenantGucTrait;

    private Connection $connection;

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function anchoredTableProvider(): iterable
    {
        yield 'reservation' => ['reservation'];
        yield 'venue_training_slot' => ['venue_training_slot'];
        // P4-34 — les trois jumeaux. Leur ancre est NOT NULL : le cas « ligne de
        // base » (ancre nulle, partagée par la saison) n'existe pas pour eux, et
        // l'insérer échouerait — d'où le drapeau porté par le provider.
        yield 'plan_venue' => ['plan_venue'];
        yield 'plan_team' => ['plan_team'];
        yield 'constraint_period_override' => ['constraint_period_override'];
    }

    /**
     * Toutes les tables qui portent un plan : celles du cas de suppression, plus les quatre familles du
     * socle qui reçoivent leur clé au lot 4 (PR 2).
     *
     * @return iterable<string, array{0: string}>
     */
    public static function plannedTableProvider(): iterable
    {
        yield from self::anchoredTableProvider();
        yield 'venue_occupancy' => ['venue_occupancy'];
        yield 'shared_training_block' => ['shared_training_block'];
        yield 'shared_training_block_team' => ['shared_training_block_team'];
        yield 'implicit_rule_setting' => ['implicit_rule_setting'];
    }

    /**
     * La contrainte est là ET son action est CASCADE (`confdeltype = 'c'`).
     *
     * Vérifier seulement l'existence laisserait passer un `NO ACTION`, qui
     * ferait ÉCHOUER la suppression d'un plan au lieu de nettoyer — une panne
     * fonctionnelle, pas une fuite de lignes.
     */
    #[DataProvider('plannedTableProvider')]
    public function testTheForeignKeyExistsAndCascades(string $table): void
    {
        $deleteAction = $this->connection->fetchOne(
            'SELECT confdeltype FROM pg_constraint
             WHERE conrelid = ?::regclass AND contype = \'f\' AND conname = ?',
            [$table, \sprintf('fk_%s_schedule_plan', $table)],
        );

        self::assertSame('c', $deleteAction, \sprintf(
            'La FK `fk_%s_schedule_plan` doit exister en ON DELETE CASCADE. '
            . 'Doctrine ne la connaît pas (colonne `guid` nue) : `migration-diff` propose de la DROPper — ne jamais committer ce DROP.',
            $table,
        ));
    }

    #[DataProvider('anchoredTableProvider')]
    public function testDeletingAPlanTakesItsAnchoredRowsAndSparesTheOthers(string $table): void
    {
        $clubId = $this->uuid();
        $seasonId = $this->uuid();
        // Tables en RLS FORCE : sans le GUC, les INSERT seraient refusés.
        $this->scopeGucToClub($clubId);
        $doomedPlanId = $this->uuid();
        $survivingPlanId = $this->uuid();

        // Le plan supprimé est un plan de PÉRIODE — c'est celui que `deletePeriodPlan`
        // emporte. Le survivant est le plan de SAISON (un seul par saison : index partiel
        // `uniq_schedule_plan_season_base`), donc deux SEASON se heurteraient.
        $this->insertPlan($doomedPlanId, $clubId, $seasonId, 'HOLIDAY');
        $this->insertPlan($survivingPlanId, $clubId, $seasonId, 'SEASON');

        $doomedRow = $this->insertAnchoredRow($table, $clubId, $seasonId, $doomedPlanId);
        // Le survivant est le plan de saison : pour la grille et les réservations, c'est la ligne du socle.
        $survivingRow = $this->insertAnchoredRow($table, $clubId, $seasonId, $survivingPlanId);

        // Le geste réel : `deletePeriodPlan` supprime le plan en SQL brut.
        $this->connection->executeStatement('DELETE FROM schedule_plan WHERE id = ?', [$doomedPlanId]);

        self::assertFalse($this->rowExists($table, $doomedRow), 'La ligne du plan supprimé doit être emportée par la cascade.');
        self::assertTrue($this->rowExists($table, $survivingRow), 'La ligne d’un AUTRE plan — le socle compris — ne doit pas être touchée.');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function insertPlan(string $id, string $clubId, string $seasonId, string $type): void
    {
        $this->connection->executeStatement(
            'INSERT INTO schedule_plan (id, version, created_at, updated_at, club_id, season_id, type, name,
                start_date, end_date, last_version_number)
             VALUES (?, 1, NOW(), NOW(), ?, ?, ?, ?, NOW(), NOW(), 0)',
            [$id, $clubId, $seasonId, $type, 'Plan ' . substr($id, 0, 8)],
        );
    }

    /** @return string l'identifiant de la ligne insérée */
    private function insertAnchoredRow(string $table, string $clubId, string $seasonId, string $planId): string
    {
        $id = $this->uuid();

        if ('plan_venue' === $table) {
            $this->connection->executeStatement(
                'INSERT INTO plan_venue (id, version, created_at, updated_at, club_id, season_id,
                    schedule_plan_id, venue_id, participates)
                 VALUES (?, 1, NOW(), NOW(), ?, ?, ?, ?, false)',
                [$id, $clubId, $seasonId, $planId, $this->uuid()],
            );

            return $id;
        }

        if ('plan_team' === $table) {
            $this->connection->executeStatement(
                'INSERT INTO plan_team (id, version, created_at, updated_at, club_id, season_id,
                    team_id, participates, sessions_per_week, max_sessions_per_day, schedule_plan_id)
                 VALUES (?, 1, NOW(), NOW(), ?, ?, ?, true, 2, 1, ?)',
                [$id, $clubId, $seasonId, $this->uuid(), $planId],
            );

            return $id;
        }

        if ('constraint_period_override' === $table) {
            $this->connection->executeStatement(
                'INSERT INTO constraint_period_override (id, version, created_at, updated_at, club_id, season_id,
                    constraint_id, is_active, schedule_plan_id)
                 VALUES (?, 1, NOW(), NOW(), ?, ?, ?, true, ?)',
                [$id, $clubId, $seasonId, $this->uuid(), $planId],
            );

            return $id;
        }

        if ('reservation' === $table) {
            $this->connection->executeStatement(
                'INSERT INTO reservation (id, version, created_at, updated_at, club_id, season_id, schedule_plan_id,
                    team_id, venue_id, day_of_week, start_time, duration_minutes)
                 VALUES (?, 1, NOW(), NOW(), ?, ?, ?, ?, ?, 1, ?, 90)',
                [$id, $clubId, $seasonId, $planId, $this->uuid(), $this->uuid(), '18:00:00'],
            );

            return $id;
        }

        $this->connection->executeStatement(
            'INSERT INTO venue_training_slot (id, version, created_at, updated_at, club_id, season_id, schedule_plan_id,
                venue_id, day_of_week, start_time, duration_minutes, capacity)
             VALUES (?, 1, NOW(), NOW(), ?, ?, ?, ?, 1, ?, 120, 1)',
            [$id, $clubId, $seasonId, $planId, $this->uuid(), '18:00:00'],
        );

        return $id;
    }

    private function rowExists(string $table, string $id): bool
    {
        return (bool) $this->connection->fetchOne(\sprintf('SELECT 1 FROM %s WHERE id = ?', $table), [$id]);
    }

    private function uuid(): string
    {
        return (string) $this->connection->fetchOne('SELECT gen_random_uuid()');
    }
}
