<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Tests\Security\PlanEntitlementsTest;
use App\Tests\Security\SubscriptionPlanAttributionTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\Persistence\ManagerRegistry;
use DoctrineMigrations\Version20261111120000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR de migration (charte docs/testing/tests-par-parcours.md §2, obligatoire jusqu'à l'application
 * en prod) : le passage à la grille 20 / 34 / 35+ ne fait jamais retomber un club payant sur
 * Découverte. Un club de l'ancien palier « sans-limite » part sur Grand club (illimité) avec sa
 * saison réglée INTACTE ; sans Grand club en base, la migration refuse plutôt que de laisser la FK
 * (ON DELETE SET NULL) remettre ces clubs en Découverte en silence.
 *
 * - `up()` : Club passe à 34, Grand club à 0 (illimité), les clubs « sans-limite » sont déplacés
 *   vers Grand club AVANT la suppression de la ligne « sans-limite ».
 * - `down()` : réinsère « sans-limite », remet Club 30 et Grand club 50, ne redéplace aucun club.
 *
 * On joue les requêtes de `getSql()` sur la connexion `admin` (celle des migrations) dans une
 * transaction ANNULÉE en `finally` (patron VenueUnavailabilityDropMigrationTest). Chaque cas
 * commence par `down()` : il rend l'état d'avant la migration quel que soit l'état de la base de test.
 *
 * La RÈGLE vivante (caps servis, refus nommés) est prouvée par {@see PlanEntitlementsTest} et
 * {@see SubscriptionPlanAttributionTest} ; ce test ne garde que le run qui modifie les données.
 */
#[Group('integration')]
final class OfferTiersMigrationTest extends KernelTestCase
{
    private const string CLUB_PLAN = '00000000-0000-4000-8000-000000000003';
    private const string GRAND_CLUB_PLAN = '00000000-0000-4000-8000-000000000004';
    private const string SANS_LIMITE_PLAN = '00000000-0000-4000-8000-000000000005';
    private const string ESSENTIEL_PLAN = '00000000-0000-4000-8000-000000000002';

    private const string UNLIMITED_CLUB = 'a7a7a7a7-a7a7-4a7a-8a7a-a7a7a7a7a7a7';
    private const string ESSENTIEL_CLUB = 'b8b8b8b8-b8b8-4b8b-8b8b-b8b8b8b8b8b8';

    private Connection $admin;

    public function testUpMovesSansLimiteClubsToGrandClubKeepingTheirPaidSeason(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->play('down');
            $this->insertClub(self::UNLIMITED_CLUB, self::SANS_LIMITE_PLAN, 2026);
            $this->insertClub(self::ESSENTIEL_CLUB, self::ESSENTIEL_PLAN, 2026);

            $this->play('up');

            self::assertSame(['plan_id' => self::GRAND_CLUB_PLAN, 'paid_season_year' => 2026], $this->planOf(self::UNLIMITED_CLUB), 'un club « sans-limite » passe Grand club, saison réglée intacte (jamais Découverte)');
            self::assertSame(['plan_id' => self::ESSENTIEL_PLAN, 'paid_season_year' => 2026], $this->planOf(self::ESSENTIEL_CLUB), 'témoin : un club d\'une autre offre ne bouge pas');
            self::assertFalse($this->planExists(self::SANS_LIMITE_PLAN), 'le palier « sans-limite » disparaît');
            self::assertSame(34, $this->maxTeams(self::CLUB_PLAN), 'Club monte à 34 équipes');
            self::assertSame(0, $this->maxTeams(self::GRAND_CLUB_PLAN), 'Grand club devient illimité (0)');
        });
    }

    public function testUpRefusesWithoutGrandClubAndMovesNobody(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->play('down');
            $this->insertClub(self::UNLIMITED_CLUB, self::SANS_LIMITE_PLAN, 2026);
            $this->admin->executeStatement('DELETE FROM subscription_plan WHERE id = ?', [self::GRAND_CLUB_PLAN]);

            try {
                $this->play('up');
                self::fail('sans Grand club, up() doit refuser (AbortMigration) plutôt que de remettre des clubs en Découverte');
            } catch (AbortMigration) {
                self::assertSame(['plan_id' => self::SANS_LIMITE_PLAN, 'paid_season_year' => 2026], $this->planOf(self::UNLIMITED_CLUB), 'refus AVANT tout déplacement : le club garde son offre');
                self::assertTrue($this->planExists(self::SANS_LIMITE_PLAN), 'refus AVANT la suppression du palier');
            }
        });
    }

    public function testDownRestoresSansLimiteAndOldCapsWithoutMovingClubsBack(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->play('down');
            $this->insertClub(self::UNLIMITED_CLUB, self::SANS_LIMITE_PLAN, 2026);
            $this->play('up');

            $this->play('down');

            self::assertTrue($this->planExists(self::SANS_LIMITE_PLAN), 'down() réinsère le palier « sans-limite »');
            self::assertSame(0, $this->maxTeams(self::SANS_LIMITE_PLAN), '« sans-limite » revient illimité');
            self::assertSame(30, $this->maxTeams(self::CLUB_PLAN), 'Club revient à 30');
            self::assertSame(50, $this->maxTeams(self::GRAND_CLUB_PLAN), 'Grand club revient à 50');
            self::assertSame(['plan_id' => self::GRAND_CLUB_PLAN, 'paid_season_year' => 2026], $this->planOf(self::UNLIMITED_CLUB), 'down() ne redéplace aucun club');
        });
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        self::assertInstanceOf(Connection::class, $connection);
        $this->admin = $connection;
    }

    /** Tout le test dans une transaction de la connexion admin, toujours annulée. */
    private function inRolledBackTransaction(callable $scenario): void
    {
        $this->admin->beginTransaction();
        try {
            $scenario();
        } finally {
            $this->admin->rollBack();
        }
    }

    /** Une instance NEUVE par sens (getSql() cumule), TOUTES ses requêtes jouées sur la connexion admin. */
    private function play(string $direction): void
    {
        $migration = new Version20261111120000($this->admin, new NullLogger);
        'up' === $direction ? $migration->up(new Schema) : $migration->down(new Schema);
        $queries = $migration->getSql();
        self::assertNotEmpty($queries, \sprintf('%s() doit planifier ses requêtes par addSql', $direction));
        foreach ($queries as $query) {
            $this->admin->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function insertClub(string $id, string $planId, int $paidSeasonYear): void
    {
        $this->admin->executeStatement(
            'INSERT INTO club (id, version, created_at, updated_at, name, slug, timezone, locale, onboarding_completed, generation_count_season, is_demo, plan_id, paid_season_year) VALUES (?, 1, now(), now(), ?, ?, \'Europe/Paris\', \'fr\', true, 0, false, ?, ?)',
            [$id, 'Offres ' . $id, 'offres-' . $id, $planId, $paidSeasonYear],
        );
    }

    /** @return array<string, mixed> */
    private function planOf(string $clubId): array
    {
        $row = $this->admin->fetchAssociative('SELECT plan_id, paid_season_year FROM club WHERE id = ?', [$clubId]);
        self::assertIsArray($row, 'club de test introuvable');

        return $row;
    }

    private function planExists(string $planId): bool
    {
        return false !== $this->admin->fetchOne('SELECT id FROM subscription_plan WHERE id = ?', [$planId]);
    }

    private function maxTeams(string $planId): int
    {
        $value = $this->admin->fetchOne('SELECT max_teams FROM subscription_plan WHERE id = ?', [$planId]);
        self::assertNotFalse($value, \sprintf('offre %s introuvable', $planId));

        return (int) $value;
    }
}
