<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Service\SeasonResolver;
use App\Tests\Security\PlanEntitlementsTest;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Persistence\ManagerRegistry;
use DoctrineMigrations\Version20261123120000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR de migration (charte docs/testing/tests-par-parcours.md §2, obligatoire jusqu'à l'application
 * en prod) : passer de l'abonnement « deux champs du club » à une ligne par club et par saison ne
 * change l'offre effective d'aucun club, sauf l'écart écrit — un club en Découverte marqué « saison
 * suivante payée » n'a pas de ligne, il perd la bascule (plan du lot 8, PR 1).
 *
 * Règle de conversion jouée : pour chaque club dont l'offre stockée n'est pas Découverte, une ligne
 * réglée (origine « conversion ») par saison EXISTANTE couverte par l'année réglée, plus une ligne
 * pour l'année « suivante » (`max(année en cours + 1, année d'horloge + 1)`) si elle est réglée.
 * Découverte, offre sans année réglée : aucune ligne. Puis les deux colonnes du club partent.
 *
 * La migration est irréversible : l'état d'avant est RECONSTRUIT dans une transaction de la
 * connexion `admin` (celle des migrations), toujours annulée en `finally` (le DDL PostgreSQL est
 * transactionnel) — table absente, colonnes `club.plan_id` (clé `fk_club_plan`, index
 * `idx_club_plan_id`, noms d'origine de `Version20260810120000`) et `club.paid_season_year`
 * rétablies. Les années sont relatives à la saison d'aujourd'hui (Y) : le test tient quel que soit
 * le jour où il tourne. Chaque cas « aucune ligne » porte un club témoin qui, lui, en reçoit : un
 * `up()` qui n'écrirait rien ne passe pas pour juste.
 *
 * La RÈGLE vivante (l'offre d'une saison ne dépend que de sa ligne) est prouvée par
 * `l-abonnement-se-regle-par-saison.feature` et {@see PlanEntitlementsTest} ; ce test ne garde que
 * le run qui convertit les données.
 */
#[Group('integration')]
final class SubscriptionLineMigrationTest extends KernelTestCase
{
    private const string DECOUVERTE_PLAN = '00000000-0000-4000-8000-000000000001';
    private const string ESSENTIEL_PLAN = '00000000-0000-4000-8000-000000000002';
    private const string BETA_PLAN = '00000000-0000-4000-8000-000000000006';

    private const string SUBJECT_CLUB = 'c1c1c1c1-c1c1-4c1c-8c1c-c1c1c1c1c1c1';
    private const string WITNESS_CLUB = 'd2d2d2d2-d2d2-4d2d-8d2d-d2d2d2d2d2d2';

    private Connection $admin;

    private int $year;

    /** Bêta réglée pour la saison en cours, deux saisons en base : chacune garde sa ligne, la suivante (non réglée) n'en a pas. */
    public function testAPaidBetaClubGetsOnePaidLinePerCoveredSeason(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->insertClub(self::SUBJECT_CLUB, self::BETA_PLAN, $this->year, [$this->year - 1, $this->year]);

            $this->playUp();

            self::assertSame([
                ['season_year' => $this->year - 1, 'plan_id' => self::BETA_PLAN, 'paid' => true, 'origin' => 'conversion'],
                ['season_year' => $this->year, 'plan_id' => self::BETA_PLAN, 'paid' => true, 'origin' => 'conversion'],
            ], $this->linesOf(self::SUBJECT_CLUB), 'une ligne réglée par saison couverte, aucune pour une saison suivante non réglée');
        });
    }

    /** Découverte (stockée ou absente) : aucune ligne, même avec l'année que l'inscription posait sur tout club. */
    public function testADecouverteClubGetsNoLine(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->insertClub(self::SUBJECT_CLUB, self::DECOUVERTE_PLAN, $this->year, [$this->year]);
            $this->insertClub(self::WITNESS_CLUB, self::BETA_PLAN, $this->year, [$this->year]);

            $this->playUp();

            self::assertSame([], $this->linesOf(self::SUBJECT_CLUB), 'un club en Découverte n\'a pas de ligne : sans ligne, il est en Découverte');
            self::assertCount(1, $this->linesOf(self::WITNESS_CLUB), 'témoin : un club en Bêta réglée reçoit sa ligne');
        });
    }

    /** Une offre posée sans année réglée était lue Découverte : elle ne devient pas une ligne. */
    public function testAnOfferWithoutPaidYearGetsNoLine(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->insertClub(self::SUBJECT_CLUB, self::BETA_PLAN, null, [$this->year]);
            $this->insertClub(self::WITNESS_CLUB, self::BETA_PLAN, $this->year, [$this->year]);

            $this->playUp();

            self::assertSame([], $this->linesOf(self::SUBJECT_CLUB), 'offre sans année réglée = Découverte effective : aucune ligne');
            self::assertCount(1, $this->linesOf(self::WITNESS_CLUB), 'témoin : un club en Bêta réglée reçoit sa ligne');
        });
    }

    /**
     * Année réglée deux saisons en avance : la saison en cours et la saison « suivante » reçoivent leur
     * ligne ; l'année d'après n'en reçoit pas (la règle n'écrit que la suivante — le cas est compté par
     * la mesure de production, plan §8).
     */
    public function testAYearPaidTwoSeasonsAheadCoversTheCurrentAndTheNextSeasonOnly(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->insertClub(self::SUBJECT_CLUB, self::ESSENTIEL_PLAN, $this->year + 2, [$this->year]);

            $this->playUp();

            self::assertSame([
                ['season_year' => $this->year, 'plan_id' => self::ESSENTIEL_PLAN, 'paid' => true, 'origin' => 'conversion'],
                ['season_year' => $this->year + 1, 'plan_id' => self::ESSENTIEL_PLAN, 'paid' => true, 'origin' => 'conversion'],
            ], $this->linesOf(self::SUBJECT_CLUB), 'la saison existante couverte, plus la suivante réglée — rien au-delà');
        });
    }

    /**
     * Un club dont la PREMIÈRE saison n'a pas commencé : « suivante » se compte depuis elle
     * (`année en cours + 1`), pas depuis l'horloge — l'année réglée au-delà de sa première saison
     * garde sa ligne, sinon le club perdrait la bascule qu'il avait payée.
     */
    public function testAClubWhoseFirstSeasonHasNotStartedKeepsTheSeasonAfterIt(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->insertClub(self::SUBJECT_CLUB, self::BETA_PLAN, $this->year + 2, [$this->year + 1]);

            $this->playUp();

            self::assertSame([$this->year + 1, $this->year + 2], array_column($this->linesOf(self::SUBJECT_CLUB), 'season_year'), 'la première saison (à venir) ET celle qui la suit, réglée, reçoivent leur ligne');
        });
    }

    /** L'écart écrit : un club en Découverte marqué « saison suivante payée » n'a pas de ligne, la bascule lui est fermée. */
    public function testADecouverteClubMarkedNextSeasonPaidGetsNoLine(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->insertClub(self::SUBJECT_CLUB, self::DECOUVERTE_PLAN, $this->year + 1, [$this->year]);
            $this->insertClub(self::WITNESS_CLUB, self::BETA_PLAN, $this->year + 1, [$this->year]);

            $this->playUp();

            self::assertSame([], $this->linesOf(self::SUBJECT_CLUB), 'Découverte marquée payée : aucune ligne, ni pour la saison en cours ni pour la suivante');
            self::assertSame([$this->year, $this->year + 1], array_column($this->linesOf(self::WITNESS_CLUB), 'season_year'), 'témoin : la Bêta réglée pour la suivante reçoit la saison en cours ET la suivante');
        });
    }

    /** Après la conversion, l'abonnement ne vit plus sur le club : ses deux champs ont disparu. */
    public function testTheTwoClubFieldsAreDropped(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->insertClub(self::SUBJECT_CLUB, self::BETA_PLAN, $this->year, [$this->year]);

            $this->playUp();

            self::assertSame([], $this->clubColumns(['plan_id', 'paid_season_year']), 'club.plan_id et club.paid_season_year sont supprimés');
            self::assertCount(1, $this->linesOf(self::SUBJECT_CLUB), 'l\'offre a été convertie avant la suppression des colonnes');
        });
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        self::assertInstanceOf(Connection::class, $connection);
        $this->admin = $connection;
        $this->year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
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

    /** La table neuve absente, les deux champs du club rétablis sous leurs noms d'origine. */
    private function restorePreMigrationState(): void
    {
        $this->admin->executeStatement('DROP TABLE IF EXISTS club_subscription CASCADE');
        if ([] === $this->clubColumns(['plan_id'])) {
            $this->admin->executeStatement('ALTER TABLE club ADD plan_id UUID DEFAULT NULL');
            $this->admin->executeStatement('ALTER TABLE club ADD CONSTRAINT fk_club_plan FOREIGN KEY (plan_id) REFERENCES subscription_plan (id) ON DELETE SET NULL');
            $this->admin->executeStatement('CREATE INDEX idx_club_plan_id ON club (plan_id)');
        }
        if ([] === $this->clubColumns(['paid_season_year'])) {
            $this->admin->executeStatement('ALTER TABLE club ADD paid_season_year INT DEFAULT NULL');
        }
    }

    /** Une instance NEUVE de la migration, TOUTES ses requêtes jouées sur la connexion admin. */
    private function playUp(): void
    {
        $migration = new Version20261123120000($this->admin, new NullLogger);
        $migration->up(new Schema);
        $queries = $migration->getSql();
        self::assertNotEmpty($queries, 'up() doit planifier ses requêtes par addSql');
        foreach ($queries as $query) {
            $this->admin->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @param list<int> $seasonYears saisons du club, du 15 juillet au 14 juillet */
    private function insertClub(string $id, string $planId, ?int $paidSeasonYear, array $seasonYears): void
    {
        $this->admin->executeStatement(
            'INSERT INTO club (id, version, created_at, updated_at, name, slug, timezone, locale, onboarding_completed, generation_count_season, is_demo, plan_id, paid_season_year) VALUES (?, 1, now(), now(), ?, ?, \'Europe/Paris\', \'fr\', true, 0, false, ?, ?)',
            [$id, 'Abonnement ' . $id, 'abonnement-' . $id, $planId, $paidSeasonYear],
        );
        foreach ($seasonYears as $seasonYear) {
            $this->admin->executeStatement(
                'INSERT INTO season (id, created_at, updated_at, club_id, name, start_date, end_date, status, transition_data) VALUES (gen_random_uuid(), now(), now(), ?, ?, ?, ?, \'active\', \'[]\')',
                [$id, \sprintf('%d-%d', $seasonYear, $seasonYear + 1), \sprintf('%d-07-15', $seasonYear), \sprintf('%d-07-14', $seasonYear + 1)],
            );
        }
    }

    /** @return list<array{season_year: int, plan_id: string, paid: bool, origin: string}> */
    private function linesOf(string $clubId): array
    {
        /** @var list<array{season_year: int|string, plan_id: string, paid: bool, origin: string}> $rows */
        $rows = $this->admin->fetchAllAssociative(
            'SELECT season_year, plan_id::text AS plan_id, (paid_at IS NOT NULL) AS paid, origin FROM club_subscription WHERE club_id = ? ORDER BY season_year',
            [$clubId],
        );

        return array_map(static fn (array $row): array => [
            'season_year' => (int) $row['season_year'],
            'plan_id' => $row['plan_id'],
            'paid' => (bool) $row['paid'],
            'origin' => $row['origin'],
        ], $rows);
    }

    /**
     * @param list<string> $names
     *
     * @return list<string> celles qui existent encore sur `club`
     */
    private function clubColumns(array $names): array
    {
        /** @var list<string> $present */
        $present = $this->admin->fetchFirstColumn(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = \'club\' AND column_name IN (?) ORDER BY column_name',
            [$names],
            [ArrayParameterType::STRING],
        );

        return $present;
    }
}
