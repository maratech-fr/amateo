<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Tests\Security\PersonPlaceTenantIsolationTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Persistence\ManagerRegistry;
use DoctrineMigrations\Version20261125120000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR de migration de DONNÉES (charte docs/testing/tests-par-parcours.md §2, obligatoire jusqu'à
 * l'application en production ; axe *tenant isolation*) : la conversion de l'adresse des gymnases en
 * lieux au club ne perd, ne fusionne et ne déplace rien. Un lieu par ligne de gymnase, même adresse ou
 * pas ; les identifiants de gymnase ne changent pas ; adresse, latitude et longitude passent sur le
 * lieu, à l'égal, et quittent `venue` ; les photos de versions perdent l'adresse et la position et
 * gagnent l'identifiant de lieu ; un lieu qui manque à l'appel arrête la migration.
 *
 * Cas de production relevés le 2026-11-22 : 18 gymnases, 1 sans position, aucun doublon de nom et
 * d'adresse dans un même club. Le décor porte un gymnase sans adresse ni position (celui que crée
 * l'import du parc) et deux gymnases à la même adresse (deux saisons du même club).
 *
 * On part de l'état d'AVANT la migration, posé à la main dans une transaction de la connexion `admin`
 * toujours annulée (patron {@see PersonConversionMigrationTest}) : adresse et position sur `venue`, ni
 * `place` ni `venue.place_id`. Puis on joue TOUTES les requêtes de `up()` (et `postUp()`).
 *
 * Jumeau vivant : {@see PersonPlaceTenantIsolationTest} — la clé composée et l'isolation du lieu sur
 * le schéma réel. Ce test ne garde que le run qui convertit les données.
 */
#[Group('integration')]
final class PlaceConversionMigrationTest extends KernelTestCase
{
    private const array ADDRESS_COLUMNS = ['address', 'latitude', 'longitude'];

    private Connection $admin;

    public function testEveryVenueGetsItsOwnPlaceCarryingItsAddressAndKeepsItsId(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->restorePreConversionSchema();
            [$clubA, $clubB, $season1, $season2] = $this->clubsAndSeasons();
            $nord = $this->venue($clubA, $season1, 'Gymnase Nord', '12 rue du Nord, 69100 Villeurbanne', '45.7700000', '4.8800000');
            $parc = $this->venue($clubA, $season1, 'Gymnase importé', null, null, null);
            $nordAgain = $this->venue($clubA, $season2, 'Gymnase Nord', '12 rue du Nord, 69100 Villeurbanne', '45.7700000', '4.8800000');
            $other = $this->venue($clubB, $season1, 'Salle Sud', '3 avenue du Sud, 38000 Grenoble', '45.1800000', '5.7200000');

            $this->play();

            foreach ([
                $nord => [$clubA, '12 rue du Nord, 69100 Villeurbanne', '45.7700000', '4.8800000'],
                $parc => [$clubA, null, null, null],
                $nordAgain => [$clubA, '12 rue du Nord, 69100 Villeurbanne', '45.7700000', '4.8800000'],
                $other => [$clubB, '3 avenue du Sud, 38000 Grenoble', '45.1800000', '5.7200000'],
            ] as $venueId => [$clubId, $address, $latitude, $longitude]) {
                $place = $this->admin->fetchAssociative(
                    'SELECT p.club_id, p.address, p.latitude::text AS latitude, p.longitude::text AS longitude FROM venue v JOIN place p ON p.id = v.place_id WHERE v.id = ?',
                    [$venueId],
                );
                self::assertIsArray($place, \sprintf('le gymnase %s garde son identifiant et pointe un lieu', $venueId));
                self::assertSame(
                    ['club_id' => $clubId, 'address' => $address, 'latitude' => $latitude, 'longitude' => $longitude],
                    $place,
                    \sprintf('le lieu du gymnase %s porte son adresse et sa position, à l\'égal, dans son club', $venueId),
                );
            }
            self::assertNotSame($this->placeIdOf($nord), $this->placeIdOf($nordAgain), 'sans fusion : deux gymnases à la même adresse donnent deux lieux');
            self::assertSame(
                ['name' => 'Gymnase Nord', 'source' => 'manual', 'external_ref' => 'SALLE-NORD'],
                $this->admin->fetchAssociative('SELECT name, source, external_ref FROM venue WHERE id = ?', [$nord]),
                'nom, origine et numéro fédéral restent sur le gymnase de la saison',
            );
            self::assertSame(
                (int) $this->admin->fetchOne('SELECT COUNT(*) FROM venue'),
                (int) $this->admin->fetchOne('SELECT COUNT(*) FROM place'),
                'un lieu par ligne de gymnase, sur toute la base',
            );
        });
    }

    public function testTheAddressColumnsLeaveTheVenueTable(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->restorePreConversionSchema();

            $this->play();

            self::assertSame([], $this->admin->fetchFirstColumn(
                'SELECT column_name FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = \'venue\' AND column_name IN (?, ?, ?) ORDER BY column_name',
                self::ADDRESS_COLUMNS,
            ), 'aucune copie morte de l\'adresse ne reste sur le gymnase de saison');
            self::assertSame('NO', $this->admin->fetchOne(
                'SELECT is_nullable FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = \'venue\' AND column_name = \'place_id\'',
            ), 'tout gymnase pointe un lieu');
        });
    }

    public function testPhotosLoseTheAddressAndGainThePlace(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->restorePreConversionSchema();
            [$clubA, , $season1] = $this->clubsAndSeasons();
            $nord = $this->venue($clubA, $season1, 'Gymnase Nord', '12 rue du Nord, 69100 Villeurbanne', '45.7700000', '4.8800000');
            $team = ['id' => $this->uuid(), 'clubId' => $clubA, 'seasonId' => $season1, 'name' => 'U13M1'];
            $photo = $this->photo($clubA, $season1, [
                'Team' => [$team],
                'Venue' => [$this->photoVenue($nord, $clubA, $season1)],
            ]);

            $this->play();

            $data = json_decode((string) $this->admin->fetchOne('SELECT data FROM schedule_structure_snapshot WHERE id = ?', [$photo]), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            self::assertEquals([$team], $data['Team'], 'les autres familles de la photo ne bougent pas');
            self::assertCount(1, $data['Venue']);
            $row = $data['Venue'][0];
            self::assertSame($nord, $row['id']);
            self::assertSame($this->placeIdOf($nord), $row['placeId'] ?? null, 'la ligne pointe le lieu du gymnase');
            self::assertSame(['Gymnase Nord', 'SALLE-NORD'], [$row['name'] ?? null, $row['externalRef'] ?? null], 'nom et numéro fédéral restent dans la ligne de saison');
            foreach (self::ADDRESS_COLUMNS as $field) {
                self::assertArrayNotHasKey($field, $row, \sprintf('la photo ne garde plus « %s »', $field));
            }
            $raw = (string) $this->admin->fetchOne('SELECT data::text FROM schedule_structure_snapshot WHERE id = ?', [$photo]);
            self::assertStringNotContainsString('rue du Nord', $raw, 'aucune adresse dans la photo');
        });
    }

    /**
     * Ambiguïté 1 du plan : la ligne de photo d'un gymnase qui n'existe plus (supprimé depuis) reçoit un
     * lieu créé depuis l'adresse et la position que la photo portait, dans le club de la photo, sous un
     * identifiant déterministe (md5 de la photo et de la ligne : rejouer donne le même) ; « Charger » la
     * ramène sans rien perdre. Une ligne qui porte l'identifiant d'un gymnase d'un AUTRE club est traitée
     * de même : elle ne pointe jamais le lieu de l'autre club (abus, axe *tenant isolation*).
     */
    public function testAPhotoRowWhoseVenueIsGoneGetsAPlaceMadeFromThePhoto(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->restorePreConversionSchema();
            [$clubA, $clubB, $season1] = $this->clubsAndSeasons();
            $foreign = $this->venue($clubB, $season1, 'Salle Sud', '3 avenue du Sud, 38000 Grenoble', '45.1800000', '5.7200000');
            $gone = $this->uuid();
            $photo = $this->photo($clubA, $season1, [
                'Venue' => [$this->photoVenue($gone, $clubA, $season1), $this->photoVenue($foreign, $clubA, $season1)],
            ]);

            $this->play();

            $data = json_decode((string) $this->admin->fetchOne('SELECT data FROM schedule_structure_snapshot WHERE id = ?', [$photo]), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            self::assertCount(2, $data['Venue'], 'aucune ligne de la photo ne se perd');
            foreach ($data['Venue'] as $row) {
                $expected = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(md5($photo . ':' . $row['id']), 4));
                self::assertSame($expected, $row['placeId'] ?? null, \sprintf('la ligne %s pointe le lieu tiré de la photo et de la ligne', $row['id']));
                self::assertSame(
                    ['club_id' => $clubA, 'address' => '12 rue du Nord, 69100 Villeurbanne', 'latitude' => '45.7700000', 'longitude' => '4.8800000'],
                    $this->admin->fetchAssociative('SELECT club_id, address, latitude::text AS latitude, longitude::text AS longitude FROM place WHERE id = ?', [$expected]),
                    'le lieu porte l\'adresse et la position de la photo, dans le club de la photo',
                );
            }
            self::assertNotSame($this->placeIdOf($foreign), $data['Venue'][1]['placeId'], 'jamais le lieu du gymnase de l\'autre club');
        });
    }

    public function testAPlaceMissingAfterConversionStopsTheMigration(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->restorePreConversionSchema();
            [$clubA, , $season1] = $this->clubsAndSeasons();
            $this->venue($clubA, $season1, 'Gymnase Nord', '12 rue du Nord, 69100 Villeurbanne', '45.7700000', '4.8800000');
            $this->venue($clubA, $season1, 'Gymnase Perdu', 'Adresse perdue', null, null);

            try {
                // Sabotage posé dès la naissance de la table : le lieu du « Gymnase Perdu » n'est jamais écrit.
                $this->play(sabotage: true);
                self::fail('un lieu manquant doit arrêter la migration');
            } catch (DriverException $stop) {
                // C'est le contrôle de comptage qui arrête (RAISE EXCEPTION, SQLSTATE P0001), avant toute
                // clé étrangère : sans lui, la pose de la clé composée arrêterait aussi (23503), et ce test
                // resterait vert le contrôle retiré.
                self::assertSame('P0001', $stop->getSQLState(), 'le contrôle de comptage de la migration arrête la conversion');
                self::assertStringContainsString('Conversion des gymnases en lieux', $stop->getMessage());
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
     * Le schéma d'AVANT la conversion (`Venue.php` de `lot/conception` au 2026-11-22) : adresse et
     * position sur `venue`, ni lieu ni lien. Idempotent : vaut sur une base migrée avant comme après.
     */
    private function restorePreConversionSchema(): void
    {
        $this->admin->executeStatement('ALTER TABLE venue DROP COLUMN IF EXISTS place_id CASCADE');
        $this->admin->executeStatement('DROP TABLE IF EXISTS place CASCADE');
        $this->admin->executeStatement(
            'ALTER TABLE venue ADD COLUMN IF NOT EXISTS latitude NUMERIC(10, 7) DEFAULT NULL, '
            . 'ADD COLUMN IF NOT EXISTS longitude NUMERIC(10, 7) DEFAULT NULL, '
            . 'ADD COLUMN IF NOT EXISTS address VARCHAR(255) DEFAULT NULL',
        );
    }

    /** Une instance NEUVE, toutes ses requêtes jouées sur la connexion admin, puis `postUp()`. */
    private function play(bool $sabotage = false): void
    {
        $migration = new Version20261125120000($this->admin, new NullLogger);
        $migration->up(new Schema);
        $queries = $migration->getSql();
        self::assertNotEmpty($queries, 'up() doit planifier ses requêtes par addSql');
        foreach ($queries as $query) {
            $this->admin->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            if ($sabotage && 1 === preg_match('/^\s*CREATE\s+TABLE\s+(public\.)?place\b/i', $query->getStatement())) {
                $this->admin->executeStatement(
                    'CREATE FUNCTION pg_temp.lose_one_place() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.address = \'Adresse perdue\' THEN RETURN NULL; END IF; RETURN NEW; END $$',
                );
                $this->admin->executeStatement('CREATE TRIGGER lose_one_place BEFORE INSERT ON place FOR EACH ROW EXECUTE FUNCTION pg_temp.lose_one_place()');
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

    /** @return array{0: string, 1: string, 2: string, 3: string} [club A, club B, saison 1 de A, saison 2 de A] */
    private function clubsAndSeasons(): array
    {
        $clubs = [];
        foreach (['A', 'B'] as $tag) {
            $clubId = $this->uuid();
            $this->admin->executeStatement(
                'INSERT INTO club (id, created_at, updated_at, name, slug, timezone, locale, onboarding_completed, generation_count_season) VALUES (?, now(), now(), ?, ?, \'Europe/Paris\', \'fr\', true, 0)',
                [$clubId, 'Lieux ' . $tag . ' ' . $clubId, 'lieux-' . $clubId],
            );
            $clubs[] = $clubId;
        }

        // `venue.season_id` est un guid nu (sans clé étrangère) : deux guids neufs suffisent.
        return [$clubs[0], $clubs[1], $this->uuid(), $this->uuid()];
    }

    private function venue(string $clubId, string $seasonId, string $name, ?string $address, ?string $latitude, ?string $longitude): string
    {
        $id = $this->uuid();
        $this->admin->executeStatement(
            'INSERT INTO venue (id, version, created_at, updated_at, club_id, season_id, name, source, external_ref, is_active, address, latitude, longitude)'
            . ' VALUES (?, 1, now(), now(), ?, ?, ?, \'manual\', ?, true, ?, ?, ?)',
            [$id, $clubId, $seasonId, $name, 'Gymnase Nord' === $name ? 'SALLE-NORD' : null, $address, $latitude, $longitude],
        );

        return $id;
    }

    /** @return array<string, mixed> une ligne de gymnase telle que `StructureSnapshotter` la photographiait avant la conversion */
    private function photoVenue(string $id, string $clubId, string $seasonId): array
    {
        return [
            'id' => $id, 'version' => 1, 'createdAt' => '2026-09-01T10:00:00+00:00', 'updatedAt' => '2026-09-01T10:00:00+00:00',
            'clubId' => $clubId, 'seasonId' => $seasonId, 'name' => 'Gymnase Nord', 'canSplit' => false, 'color' => null,
            'latitude' => '45.7700000', 'longitude' => '4.8800000', 'address' => '12 rue du Nord, 69100 Villeurbanne',
            'source' => 'manual', 'externalRef' => 'SALLE-NORD', 'externalLabels' => [], 'isActive' => true,
        ];
    }

    /** @param array<string, list<array<string, mixed>>> $data */
    private function photo(string $clubId, string $seasonId, array $data): string
    {
        $id = $this->uuid();
        $this->admin->executeStatement(
            'INSERT INTO schedule_structure_snapshot (id, created_at, updated_at, club_id, season_id, schedule_id, data) VALUES (?, now(), now(), ?, ?, ?, ?)',
            [$id, $clubId, $seasonId, $this->uuid(), json_encode($data, \JSON_THROW_ON_ERROR)],
        );

        return $id;
    }

    private function placeIdOf(string $venueId): string
    {
        $placeId = $this->admin->fetchOne('SELECT place_id FROM venue WHERE id = ?', [$venueId]);
        self::assertIsString($placeId, \sprintf('le gymnase %s pointe un lieu', $venueId));

        return $placeId;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
