<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Tests\Security\PersonPlaceTenantIsolationTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Persistence\ManagerRegistry;
use DoctrineMigrations\Version20261126120000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR de migration de DONNÉES (charte docs/testing/tests-par-parcours.md §2, obligatoire jusqu'à
 * l'application en production ; axes *tenant isolation* et *generation pipeline*) : la conversion des
 * barèmes de trajet de saison (`venue_travel_time`, entre deux gymnases) en temps de trajet au club
 * (`place_travel_time`, entre deux lieux) garde chaque couple, ses minutes et leur origine, ligne à ligne,
 * dans son club ; le couple se range « plus petit identifiant de LIEU d'abord », même quand les gymnases
 * se rangeaient à l'inverse ; l'ancienne table n'existe plus ; un temps qui manque à l'appel arrête la
 * migration.
 *
 * Cas de production relevés le 2026-11-22 : 9 barèmes, une saison par club, aucun orphelin, aucun couple
 * mal rangé. Le décor porte un couple saisi à la main, un calculé, un sans minutes, et un couple d'un autre
 * club.
 *
 * On part de l'état d'AVANT la migration, posé à la main dans une transaction de la connexion `admin`
 * toujours annulée (patron {@see PlaceConversionMigrationTest}) : `venue_travel_time` présente, pas de
 * `place_travel_time`. Puis on joue TOUTES les requêtes de `up()` (et `postUp()`).
 *
 * Jumeau vivant : {@see PersonPlaceTenantIsolationTest} — les deux clés composées et l'isolation du temps de
 * trajet sur le schéma réel. Ce test ne garde que le run qui convertit les données.
 */
#[Group('integration')]
final class TravelTimeConversionMigrationTest extends KernelTestCase
{
    private Connection $admin;

    public function testEveryTravelTimeBecomesTheTravelTimeOfItsTwoPlacesWithItsMinutesAndOrigin(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->restorePreConversionSchema();
            [$clubA, $clubB, $season] = $this->clubsAndSeason();
            // Gymnases Nord < Sud, mais lieux de Sud < Nord : le couple Nord–Sud se range à l'envers en lieux.
            $nord = $this->venue($clubA, $season, 'Gymnase Nord', 'b0000000-0000-4000-8000-' . $this->suffix(), 'f0000000-0000-4000-8000-' . $this->suffix());
            $sud = $this->venue($clubA, $season, 'Gymnase Sud', 'b1000000-0000-4000-8000-' . $this->suffix(), 'e0000000-0000-4000-8000-' . $this->suffix());
            $est = $this->venue($clubA, $season, 'Gymnase Est', 'b2000000-0000-4000-8000-' . $this->suffix(), 'f1000000-0000-4000-8000-' . $this->suffix());
            $salle = $this->venue($clubB, $season, 'Salle 1', 'b3000000-0000-4000-8000-' . $this->suffix(), 'a0000000-0000-4000-8000-' . $this->suffix());
            $salle2 = $this->venue($clubB, $season, 'Salle 2', 'b4000000-0000-4000-8000-' . $this->suffix(), 'a1000000-0000-4000-8000-' . $this->suffix());
            $this->oldTravelTime($clubA, $season, $nord, $sud, 12, 30, 'MANUAL', 'MANUAL');
            $this->oldTravelTime($clubA, $season, $nord, $est, 8, 20, 'AUTO', 'AUTO');
            $this->oldTravelTime($clubA, $season, $sud, $est, null, null, null, null);
            $this->oldTravelTime($clubB, $season, $salle, $salle2, 15, null, 'AUTO', null);

            $this->play();

            $rows = $this->admin->fetchAllAssociative(
                'SELECT club_id, place_a_id::text AS place_a_id, place_b_id::text AS place_b_id, driving_minutes, walking_minutes, driving_source, walking_source FROM place_travel_time ORDER BY place_a_id, place_b_id',
            );
            $expected = [
                $this->expected($clubA, $sud, $nord, 12, 30, 'MANUAL', 'MANUAL'),
                $this->expected($clubA, $sud, $est, null, null, null, null),
                $this->expected($clubA, $nord, $est, 8, 20, 'AUTO', 'AUTO'),
                $this->expected($clubB, $salle, $salle2, 15, null, 'AUTO', null),
            ];
            usort($expected, static fn (array $x, array $y): int => [$x['place_a_id'], $x['place_b_id']] <=> [$y['place_a_id'], $y['place_b_id']]);
            self::assertSame($expected, $rows, 'un temps par barème, entre les lieux de ses deux gymnases rangés plus petit lieu d\'abord, minutes et origine à l\'égal, dans son club');
        });
    }

    public function testTheSeasonTableOfTravelTimesNoLongerExists(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->restorePreConversionSchema();

            $this->play();

            self::assertNull($this->admin->fetchOne('SELECT to_regclass(\'public.venue_travel_time\')::text'), 'aucune copie morte des barèmes de saison');
            self::assertSame([], $this->admin->fetchFirstColumn(
                'SELECT column_name FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = \'place_travel_time\' AND column_name = \'season_id\'',
            ), 'le temps de trajet au club ne porte pas de saison');
        });
    }

    public function testATravelTimeMissingAfterConversionStopsTheMigration(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->restorePreConversionSchema();
            [$clubA, , $season] = $this->clubsAndSeason();
            $nord = $this->venue($clubA, $season, 'Gymnase Nord', $this->uuid(), $this->uuid());
            $sud = $this->venue($clubA, $season, 'Gymnase Sud', $this->uuid(), $this->uuid());
            $est = $this->venue($clubA, $season, 'Gymnase Est', $this->uuid(), $this->uuid());
            $this->oldTravelTime($clubA, $season, $nord, $sud, 12, 30, 'MANUAL', 'MANUAL');
            $this->oldTravelTime($clubA, $season, $nord, $est, 99, null, 'MANUAL', null);

            try {
                // Sabotage posé dès la naissance de la table : le temps à 99 minutes n'est jamais écrit.
                $this->play(sabotage: true);
                self::fail('un temps de trajet manquant doit arrêter la migration');
            } catch (DriverException $stop) {
                // C'est le contrôle de comptage qui arrête (RAISE EXCEPTION, SQLSTATE P0001) : sans lui, la
                // migration passerait en perdant le couple en silence.
                self::assertSame('P0001', $stop->getSQLState(), 'le contrôle de comptage de la migration arrête la conversion');
                self::assertStringContainsString('temps de trajet', $stop->getMessage());
            }
        });
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        self::assertInstanceOf(Connection::class, $connection);
        $this->admin = $connection;
    }

    /**
     * Le schéma d'AVANT la conversion (`VenueTravelTime.php` de `lot/conception` au 2026-11-23, DDL de
     * `Version20260826130000`) : `venue_travel_time` par saison, pas de `place_travel_time`. Idempotent : vaut
     * sur une base migrée avant comme après.
     */
    private function restorePreConversionSchema(): void
    {
        $this->admin->executeStatement('DROP TABLE IF EXISTS place_travel_time CASCADE');
        $this->admin->executeStatement(
            'CREATE TABLE IF NOT EXISTS venue_travel_time (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, '
            . 'updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, venue_a_id UUID NOT NULL, venue_b_id UUID NOT NULL, '
            . 'driving_minutes SMALLINT DEFAULT NULL, walking_minutes SMALLINT DEFAULT NULL, driving_source VARCHAR(10) DEFAULT NULL, walking_source VARCHAR(10) DEFAULT NULL, PRIMARY KEY (id))',
        );
        $this->admin->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS uniq_venue_travel_time_couple ON venue_travel_time (club_id, season_id, venue_a_id, venue_b_id)');
        $this->admin->executeStatement('DELETE FROM venue_travel_time');
    }

    /** Une instance NEUVE, toutes ses requêtes jouées sur la connexion admin, puis `postUp()`. */
    private function play(bool $sabotage = false): void
    {
        $migration = new Version20261126120000($this->admin, new NullLogger);
        $migration->up(new Schema);
        $queries = $migration->getSql();
        self::assertNotEmpty($queries, 'up() doit planifier ses requêtes par addSql');
        foreach ($queries as $query) {
            $this->admin->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            if ($sabotage && 1 === preg_match('/^\s*CREATE\s+TABLE\s+(public\.)?place_travel_time\b/i', $query->getStatement())) {
                $this->admin->executeStatement(
                    'CREATE FUNCTION pg_temp.lose_one_travel_time() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.driving_minutes = 99 THEN RETURN NULL; END IF; RETURN NEW; END $$',
                );
                $this->admin->executeStatement('CREATE TRIGGER lose_one_travel_time BEFORE INSERT ON place_travel_time FOR EACH ROW EXECUTE FUNCTION pg_temp.lose_one_travel_time()');
            }
        }
        $migration->postUp(new Schema);
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

    /** @return array{0: string, 1: string, 2: string} [club A, club B, une saison (guid nu)] */
    private function clubsAndSeason(): array
    {
        $clubs = [];
        foreach (['A', 'B'] as $tag) {
            $clubId = $this->uuid();
            $this->admin->executeStatement(
                'INSERT INTO club (id, created_at, updated_at, name, slug, timezone, locale, onboarding_completed, generation_count_season) VALUES (?, now(), now(), ?, ?, \'Europe/Paris\', \'fr\', true, 0)',
                [$clubId, 'Trajets ' . $tag . ' ' . $clubId, 'trajets-' . $clubId],
            );
            $clubs[] = $clubId;
        }

        return [$clubs[0], $clubs[1], $this->uuid()];
    }

    /** @return array{venue: string, place: string} un gymnase de saison et son lieu, aux identifiants choisis */
    private function venue(string $clubId, string $seasonId, string $name, string $venueId, string $placeId): array
    {
        $this->admin->executeStatement(
            'INSERT INTO place (id, version, created_at, updated_at, club_id) VALUES (?, 1, now(), now(), ?)',
            [$placeId, $clubId],
        );
        $this->admin->executeStatement(
            'INSERT INTO venue (id, version, created_at, updated_at, club_id, season_id, place_id, name, source, is_active) VALUES (?, 1, now(), now(), ?, ?, ?, ?, \'manual\', true)',
            [$venueId, $clubId, $seasonId, $placeId, $name],
        );

        return ['venue' => $venueId, 'place' => $placeId];
    }

    /**
     * Un barème de saison tel que l'API l'écrivait : couple rangé « plus petit identifiant de gymnase d'abord ».
     *
     * @param array{venue: string, place: string} $x
     * @param array{venue: string, place: string} $y
     */
    private function oldTravelTime(string $clubId, string $seasonId, array $x, array $y, ?int $driving, ?int $walking, ?string $drivingSource, ?string $walkingSource): void
    {
        [$a, $b] = strcmp($x['venue'], $y['venue']) > 0 ? [$y['venue'], $x['venue']] : [$x['venue'], $y['venue']];
        $this->admin->executeStatement(
            'INSERT INTO venue_travel_time (id, version, created_at, updated_at, club_id, season_id, venue_a_id, venue_b_id, driving_minutes, walking_minutes, driving_source, walking_source) VALUES (?, 1, now(), now(), ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->uuid(), $clubId, $seasonId, $a, $b, $driving, $walking, $drivingSource, $walkingSource],
        );
    }

    /**
     * La ligne attendue, rangée « plus petit identifiant de lieu d'abord ».
     *
     * @param array{venue: string, place: string} $x
     * @param array{venue: string, place: string} $y
     *
     * @return array<string, mixed>
     */
    private function expected(string $clubId, array $x, array $y, ?int $driving, ?int $walking, ?string $drivingSource, ?string $walkingSource): array
    {
        [$a, $b] = strcmp($x['place'], $y['place']) > 0 ? [$y['place'], $x['place']] : [$x['place'], $y['place']];

        return [
            'club_id' => $clubId, 'place_a_id' => $a, 'place_b_id' => $b,
            'driving_minutes' => $driving, 'walking_minutes' => $walking,
            'driving_source' => $drivingSource, 'walking_source' => $walkingSource,
        ];
    }

    private function suffix(): string
    {
        return bin2hex(random_bytes(6));
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
