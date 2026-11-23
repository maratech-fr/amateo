<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Tests\CrossStack\HabitPayloadParityTest;
use App\Tests\Validator\TeamInputValidationTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Persistence\ManagerRegistry;
use DoctrineMigrations\Version20261121130000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR de migration de données (charte docs/testing/tests-par-parcours.md §2, obligatoire jusqu'à
 * l'application en prod) : le jour de match enregistré d'une équipe passe, en une fois, du comptage de 0
 * (lundi) à 6 (dimanche) au comptage de 1 (lundi) à 7 (dimanche), dans la table `team` ET dans chaque
 * photo de structure (`schedule_structure_snapshot.data`, clé `Team[].matchDay`) — sans quoi « Charger »
 * une version d'avant la migration réécrirait un samedi (5) qui se lirait vendredi. Un jour non renseigné
 * reste non renseigné ; rien d'autre de la photo ne bouge ; la demande déjà envoyée au moteur
 * (`schedule.snapshot_data`, déjà comptée de 1 à 7) n'est pas touchée.
 *
 * On joue les requêtes de `getSql()` de `up()` sur la connexion `admin` (celle des migrations) dans une
 * transaction ANNULÉE en `finally` (patron {@see ClubRoleClosingMigrationTest}). Les lignes sont posées
 * en SQL brut dans l'état d'avant la migration (aucune clé étrangère sur ces tables).
 *
 * Jumeaux vivants : {@see HabitPayloadParityTest::testDeclaredFieldFallbackIsEmittedAsStored} (le jour
 * enregistré part tel quel au moteur) et {@see TeamInputValidationTest::testMatchDayRunsFromOneMondayToSevenSunday}
 * (l'API n'accepte que 1 à 7). Ce test ne garde que le run qui convertit les données.
 */
#[Group('integration')]
final class TeamMatchDayIsoMigrationTest extends KernelTestCase
{
    private Connection $admin;

    public function testUpShiftsEveryStoredMatchDayByOneAndLeavesAnUnsetOneUnset(): void
    {
        $this->inRolledBackTransaction(function (): void {
            [$clubId, $seasonId] = [$this->uuid(), $this->uuid()];
            $monday = $this->team($clubId, $seasonId, 0);
            $saturday = $this->team($clubId, $seasonId, 5);
            $sunday = $this->team($clubId, $seasonId, 6);
            $unset = $this->team($clubId, $seasonId, null);

            $this->playUp();

            self::assertSame(1, $this->matchDayOf($monday), 'lundi : 0 → 1');
            self::assertSame(6, $this->matchDayOf($saturday), 'samedi : 5 → 6');
            self::assertSame(7, $this->matchDayOf($sunday), 'dimanche : 6 → 7');
            self::assertNull($this->matchDayOf($unset), 'un jour non renseigné reste non renseigné');
        });
    }

    public function testUpShiftsTheMatchDayInsideEveryStructurePhotoAndNothingElse(): void
    {
        $this->inRolledBackTransaction(function (): void {
            [$clubId, $seasonId] = [$this->uuid(), $this->uuid()];
            $before = [
                'SportCategory' => [['id' => 'cat-1', 'name' => 'Senior', 'ageMin' => 22]],
                'Team' => [
                    ['id' => 'team-sat', 'name' => 'SM1', 'matchDay' => 5, 'sessionsPerWeek' => 3],
                    ['id' => 'team-unset', 'name' => 'U13 F', 'matchDay' => null, 'sessionsPerWeek' => 2],
                    ['id' => 'team-sun', 'name' => 'SF1', 'matchDay' => 6, 'sessionsPerWeek' => 2],
                ],
                'Venue' => [['id' => 'venue-1', 'name' => 'Gymnase Nord']],
                'VenueTrainingSlot' => [['id' => 'slot-1', 'dayOfWeek' => 5, 'startTime' => '18:00:00']],
            ];
            $photo = $this->structurePhoto($clubId, $seasonId, $before);
            $withoutTeams = $this->structurePhoto($clubId, $seasonId, ['Venue' => [['id' => 'venue-2', 'name' => 'Gymnase Sud']]]);

            $this->playUp();

            $after = $before;
            $after['Team'][0]['matchDay'] = 6;
            $after['Team'][2]['matchDay'] = 7;
            self::assertEquals($after, $this->photoData($photo), 'dans la photo, seul le jour de match des équipes bouge (+1) ; null reste null ; l\'ordre des équipes est gardé ; le jour d\'un créneau ne bouge pas');
            $teams = $this->photoData($photo)['Team'] ?? null;
            self::assertIsArray($teams);
            self::assertSame(['team-sat', 'team-unset', 'team-sun'], array_column($teams, 'id'), 'l\'ordre des équipes de la photo est gardé');
            self::assertEquals(['Venue' => [['id' => 'venue-2', 'name' => 'Gymnase Sud']]], $this->photoData($withoutTeams), 'une photo sans équipe est intacte');
        });
    }

    public function testUpLeavesTheRequestAlreadySentToTheEngineUntouched(): void
    {
        $this->inRolledBackTransaction(function (): void {
            [$clubId, $seasonId] = [$this->uuid(), $this->uuid()];
            $sent = ['teams' => [['id' => 'team-sat', 'matchDay' => 6]], 'seasonId' => $seasonId];
            $version = $this->version($clubId, $seasonId, $sent);

            $this->playUp();

            $stored = $this->admin->fetchOne('SELECT snapshot_data FROM schedule WHERE id = ?', [$version]);
            self::assertIsString($stored);
            self::assertEquals($sent, json_decode($stored, true, 512, \JSON_THROW_ON_ERROR), 'la demande déjà envoyée au moteur comptait déjà de 1 à 7 : elle ne bouge pas');
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

    /** Une instance NEUVE (getSql() cumule), TOUTES les requêtes de `up()` jouées sur la connexion admin. */
    private function playUp(): void
    {
        $migration = new Version20261121130000($this->admin, new NullLogger);
        $migration->up(new Schema);
        $queries = $migration->getSql();
        self::assertNotEmpty($queries, 'up() doit planifier ses requêtes par addSql');
        foreach ($queries as $query) {
            $this->admin->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function team(string $clubId, string $seasonId, ?int $matchDay): string
    {
        $id = $this->uuid();
        $this->admin->executeStatement(
            'INSERT INTO team (id, created_at, updated_at, club_id, season_id, sport_category_id, priority_tier_id, name, is_active, match_day) VALUES (?, now(), now(), ?, ?, ?, 3, ?, true, ?)',
            [$id, $clubId, $seasonId, $this->uuid(), 'Équipe ' . $id, $matchDay],
        );

        return $id;
    }

    private function matchDayOf(string $teamId): ?int
    {
        $row = $this->admin->fetchAssociative('SELECT match_day FROM team WHERE id = ?', [$teamId]);
        self::assertIsArray($row, \sprintf('équipe %s introuvable', $teamId));

        return null === $row['match_day'] ? null : (int) $row['match_day'];
    }

    /** @param array<string, list<array<string, mixed>>> $data */
    private function structurePhoto(string $clubId, string $seasonId, array $data): string
    {
        $id = $this->uuid();
        $this->admin->executeStatement(
            'INSERT INTO schedule_structure_snapshot (id, created_at, updated_at, club_id, season_id, schedule_id, data) VALUES (?, now(), now(), ?, ?, ?, ?)',
            [$id, $clubId, $seasonId, $this->uuid(), json_encode($data, \JSON_THROW_ON_ERROR)],
        );

        return $id;
    }

    /** @return array<mixed> */
    private function photoData(string $photoId): array
    {
        $data = $this->admin->fetchOne('SELECT data FROM schedule_structure_snapshot WHERE id = ?', [$photoId]);
        self::assertIsString($data, \sprintf('photo %s introuvable', $photoId));
        $decoded = json_decode($data, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @param array<string, mixed> $sent */
    private function version(string $clubId, string $seasonId, array $sent): string
    {
        $id = $this->uuid();
        $this->admin->executeStatement(
            'INSERT INTO schedule (id, created_at, updated_at, club_id, season_id, name, status, solver_seed, snapshot_data, schedule_plan_id, version_number) VALUES (?, now(), now(), ?, ?, \'V1\', \'COMPLETED\', 1, ?, ?, 1)',
            [$id, $clubId, $seasonId, json_encode($sent, \JSON_THROW_ON_ERROR), $this->uuid()],
        );

        return $id;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
