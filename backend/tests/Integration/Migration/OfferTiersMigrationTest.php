<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Tests\Security\PlanEntitlementsTest;
use App\Tests\Security\SubscriptionPlanAttributionTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Persistence\ManagerRegistry;
use DoctrineMigrations\Version20261124120000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR de migration (charte docs/testing/tests-par-parcours.md §2, obligatoire jusqu'à l'application
 * en prod) : la fusion des trois offres payantes en UNE offre à trois paliers (lot 8, PR 2) laisse à
 * chaque club le plafond d'équipes qu'il avait. Une ligne Essentiel (20) passe au palier 1, Club
 * (34) au palier 2, Grand club (illimité) au palier 3, toutes sur l'offre complète ; la Bêta et
 * Découverte restent sans palier ; saison, règlement et origine de chaque ligne ne bougent pas.
 * L'offre complète est l'ancienne ligne Essentiel (même id) ; Club et Grand club disparaissent ;
 * le plafond quitte l'offre pour le palier.
 *
 * La migration est irréversible : l'état d'avant (cinq offres, plafond sur l'offre, aucune table
 * de paliers, aucune colonne `tier_id`) est RECONSTRUIT dans une transaction de la connexion
 * `admin` (celle des migrations), toujours annulée en `finally` (le DDL PostgreSQL est
 * transactionnel). Remplace l'ancien NR de `Version20261111120000` (grille 20 / 34 / 35+), migration
 * appliquée en production.
 *
 * La RÈGLE vivante (le plafond de création suit le palier, paliers servis) est prouvée par
 * {@see PlanEntitlementsTest} et {@see SubscriptionPlanAttributionTest} ; ce test ne garde que le
 * run qui convertit les données.
 */
#[Group('integration')]
final class OfferTiersMigrationTest extends KernelTestCase
{
    private const string DECOUVERTE_PLAN = '00000000-0000-4000-8000-000000000001';
    private const string ESSENTIEL_PLAN = '00000000-0000-4000-8000-000000000002';
    private const string CLUB_PLAN = '00000000-0000-4000-8000-000000000003';
    private const string GRAND_CLUB_PLAN = '00000000-0000-4000-8000-000000000004';
    private const string BETA_PLAN = '00000000-0000-4000-8000-000000000006';

    /** L'offre complète garde l'id de l'ancienne ligne Essentiel. */
    private const string COMPLETE_PLAN = self::ESSENTIEL_PLAN;

    /** Un club par offre d'avant : [id du club, offre d'avant, ligne réglée ?, palier attendu, plafond attendu]. */
    private const array CLUBS = [
        ['e1e1e1e1-e1e1-4e1e-8e1e-e1e1e1e1e1e1', self::ESSENTIEL_PLAN, true, 'palier-1', 20],
        ['e2e2e2e2-e2e2-4e2e-8e2e-e2e2e2e2e2e2', self::CLUB_PLAN, true, 'palier-2', 34],
        ['e3e3e3e3-e3e3-4e3e-8e3e-e3e3e3e3e3e3', self::GRAND_CLUB_PLAN, true, 'palier-3', 0],
        ['e4e4e4e4-e4e4-4e4e-8e4e-e4e4e4e4e4e4', self::BETA_PLAN, true, null, null],
        ['e5e5e5e5-e5e5-4e5e-8e5e-e5e5e5e5e5e5', self::DECOUVERTE_PLAN, false, null, null],
    ];

    private Connection $admin;

    /**
     * Chaque ligne d'une offre payante passe à l'offre complète, au palier qui porte SON plafond
     * d'avant (20, 34, illimité) ; Bêta et Découverte gardent leur offre, sans palier ; saison,
     * règlement et origine intacts.
     */
    public function testEveryPaidLineMovesToTheTierThatKeepsItsTeamCap(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $before = [];
            foreach (self::CLUBS as [$clubId, $planId, $paid]) {
                $this->insertClubWithLine($clubId, $planId, $paid);
                $before[$clubId] = $this->lineOf($clubId);
                self::assertSame(
                    [2026, $paid, 'conversion'],
                    [$before[$clubId]['season_year'], $before[$clubId]['paid'], $before[$clubId]['origin']],
                    'témoin : la ligne d\'avant est posée',
                );
            }
            $capsBefore = [];
            foreach ([self::ESSENTIEL_PLAN, self::CLUB_PLAN, self::GRAND_CLUB_PLAN] as $planId) {
                $capsBefore[$planId] = (int) $this->admin->fetchOne('SELECT max_teams FROM subscription_plan WHERE id = ?', [$planId]);
            }
            self::assertSame([self::ESSENTIEL_PLAN => 20, self::CLUB_PLAN => 34, self::GRAND_CLUB_PLAN => 0], $capsBefore, 'témoin : la grille d\'avant est 20 / 34 / illimité');

            $this->playUp();

            foreach (self::CLUBS as [$clubId, $planId, , $tier, $cap]) {
                $after = $this->lineWithTierOf($clubId);
                $expectedPlan = null === $tier ? $planId : self::COMPLETE_PLAN;
                self::assertSame(
                    ['plan_id' => $expectedPlan, 'tier' => $tier, 'max_teams' => $cap],
                    ['plan_id' => $after['plan_id'], 'tier' => $after['tier'], 'max_teams' => $after['max_teams']],
                    \sprintf('club %s : offre et palier qui gardent son plafond d\'avant', $clubId),
                );
                self::assertSame(
                    [$before[$clubId]['season_year'], $before[$clubId]['paid'], $before[$clubId]['paid_at'], $before[$clubId]['origin']],
                    [$after['season_year'], $after['paid'], $after['paid_at'], $after['origin']],
                    \sprintf('club %s : saison, règlement et origine intacts', $clubId),
                );
            }
        });
    }

    /**
     * Le catalogue d'après : trois offres (Découverte, l'offre complète, Bêta), trois paliers de
     * l'offre complète sans prix posé, et plus de plafond sur l'offre.
     */
    public function testTheCatalogueBecomesThreeOffersAndTheCompleteOfferThreeTiers(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->playUp();

            self::assertSame(
                [self::DECOUVERTE_PLAN => 'decouverte', self::COMPLETE_PLAN => 'complete', self::BETA_PLAN => 'beta'],
                $this->admin->fetchAllKeyValue('SELECT id::text, code FROM subscription_plan ORDER BY id'),
                'Club et Grand club disparaissent ; l\'ancienne ligne Essentiel devient l\'offre complète',
            );
            self::assertSame('Offre complète', $this->admin->fetchOne('SELECT name FROM subscription_plan WHERE id = ?', [self::COMPLETE_PLAN]));
            self::assertSame(
                [
                    ['code' => 'palier-1', 'position' => 1, 'max_teams' => 20, 'price_cents' => null],
                    ['code' => 'palier-2', 'position' => 2, 'max_teams' => 34, 'price_cents' => null],
                    ['code' => 'palier-3', 'position' => 3, 'max_teams' => 0, 'price_cents' => null],
                ],
                array_map(
                    static fn (array $row): array => ['code' => $row['code'], 'position' => (int) $row['position'], 'max_teams' => (int) $row['max_teams'], 'price_cents' => $row['price_cents']],
                    $this->admin->fetchAllAssociative('SELECT code, position, max_teams, price_cents FROM subscription_plan_tier WHERE plan_id = ? ORDER BY position', [self::COMPLETE_PLAN]),
                ),
                'trois paliers de l\'offre complète, prix vide (posé à la main en production)',
            );
            self::assertSame([], $this->columns('subscription_plan', ['max_teams']), 'le plafond quitte l\'offre');
        });
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        self::assertInstanceOf(Connection::class, $connection);
        $this->admin = $connection;
    }

    /** Tout le cas dans une transaction de la connexion admin, toujours annulée ; l'état d'avant la migration d'abord. */
    private function inRolledBackTransaction(callable $scenario): void
    {
        $this->admin->beginTransaction();
        try {
            $this->restorePreMigrationState();
            $scenario();
        } finally {
            $this->admin->rollBack();
        }
    }

    /**
     * L'état d'avant : aucune table de paliers, aucune colonne `tier_id` sur la ligne d'abonnement,
     * le plafond sur l'offre, et les cinq offres de `Version20260810120000` / `Version20261111120000`
     * (mêmes ids, codes, noms, plafonds 20 / 34 / illimité).
     */
    private function restorePreMigrationState(): void
    {
        $this->admin->executeStatement('DROP TABLE IF EXISTS subscription_plan_tier CASCADE');
        if ([] !== $this->columns('club_subscription', ['tier_id'])) {
            $this->admin->executeStatement('ALTER TABLE club_subscription DROP COLUMN tier_id');
        }
        if ([] === $this->columns('subscription_plan', ['max_teams'])) {
            $this->admin->executeStatement('ALTER TABLE subscription_plan ADD max_teams INT DEFAULT 0 NOT NULL');
        }
        foreach ([
            [self::ESSENTIEL_PLAN, 'essentiel', 'Essentiel', 20],
            [self::CLUB_PLAN, 'club', 'Club', 34],
            [self::GRAND_CLUB_PLAN, 'grand-club', 'Grand club', 0],
        ] as [$id, $code, $name, $maxTeams]) {
            $this->admin->executeStatement(
                'INSERT INTO subscription_plan (id, version, created_at, updated_at, code, name, max_teams, max_venues, max_generations, features)'
                . ' VALUES (:id, 1, NOW(), NOW(), :code, :name, :maxTeams, 0, 0, \'[]\')'
                . ' ON CONFLICT (id) DO UPDATE SET code = EXCLUDED.code, name = EXCLUDED.name, max_teams = EXCLUDED.max_teams',
                ['id' => $id, 'code' => $code, 'name' => $name, 'maxTeams' => $maxTeams],
            );
        }
    }

    /** Une instance NEUVE de la migration, TOUTES ses requêtes jouées sur la connexion admin. */
    private function playUp(): void
    {
        $migration = new Version20261124120000($this->admin, new NullLogger);
        $migration->up(new Schema);
        $queries = $migration->getSql();
        self::assertNotEmpty($queries, 'up() doit planifier ses requêtes par addSql');
        foreach ($queries as $query) {
            $this->admin->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** Un club et sa ligne d'abonnement 2026 sur l'offre d'avant ; réglée ou non. */
    private function insertClubWithLine(string $clubId, string $planId, bool $paid): void
    {
        $this->admin->executeStatement(
            'INSERT INTO club (id, version, created_at, updated_at, name, slug, timezone, locale, onboarding_completed, generation_count_season, is_demo) VALUES (?, 1, now(), now(), ?, ?, \'Europe/Paris\', \'fr\', true, 0, false)',
            [$clubId, 'Paliers ' . $clubId, 'paliers-' . $clubId],
        );
        $this->admin->executeStatement(
            'INSERT INTO club_subscription (id, club_id, season_year, plan_id, paid_at, origin, created_at, updated_at)'
            . ' VALUES (gen_random_uuid(), ?, 2026, ?, ' . ($paid ? '\'2026-05-12 10:00:00+00\'' : 'NULL') . ', \'conversion\', now(), now())',
            [$clubId, $planId],
        );
    }

    /** @return array{season_year: int, plan_id: string, paid: bool, paid_at: ?string, origin: string} */
    private function lineOf(string $clubId): array
    {
        $row = $this->admin->fetchAssociative(
            'SELECT season_year, plan_id::text AS plan_id, paid_at::text AS paid_at, origin FROM club_subscription WHERE club_id = ?',
            [$clubId],
        );
        self::assertIsArray($row, \sprintf('ligne d\'abonnement du club %s introuvable', $clubId));

        return [
            'season_year' => (int) $row['season_year'],
            'plan_id' => (string) $row['plan_id'],
            'paid' => null !== $row['paid_at'],
            'paid_at' => null === $row['paid_at'] ? null : (string) $row['paid_at'],
            'origin' => (string) $row['origin'],
        ];
    }

    /** @return array{season_year: int, plan_id: string, paid: bool, paid_at: ?string, origin: string, tier: ?string, max_teams: ?int} */
    private function lineWithTierOf(string $clubId): array
    {
        $tier = $this->admin->fetchAssociative(
            'SELECT t.code, t.max_teams FROM club_subscription l LEFT JOIN subscription_plan_tier t ON t.id = l.tier_id WHERE l.club_id = ?',
            [$clubId],
        );
        self::assertIsArray($tier, \sprintf('ligne d\'abonnement du club %s introuvable après la migration', $clubId));

        return $this->lineOf($clubId) + [
            'tier' => null === $tier['code'] ? null : (string) $tier['code'],
            'max_teams' => null === $tier['max_teams'] ? null : (int) $tier['max_teams'],
        ];
    }

    /**
     * @param list<string> $names
     *
     * @return list<string> les colonnes présentes parmi $names
     */
    private function columns(string $table, array $names): array
    {
        return array_map(strval(...), $this->admin->fetchFirstColumn(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = ? AND column_name = ANY(?::text[])',
            [$table, '{' . implode(',', $names) . '}'],
        ));
    }
}
