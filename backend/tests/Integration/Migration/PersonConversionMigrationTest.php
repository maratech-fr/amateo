<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Tests\Security\PersonPlaceTenantIsolationTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Persistence\ManagerRegistry;
use DoctrineMigrations\Version20261122140000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR de migration de DONNÉES (charte docs/testing/tests-par-parcours.md §2, obligatoire jusqu'à
 * l'application en production ; axe *tenant isolation*) : la conversion de l'identité des coachs en
 * fiches de personne au club ne perd, ne fusionne et ne déplace rien. Une personne par ligne de coach,
 * même nom ou pas ; les identifiants de coach ne changent pas ; nom, mail, téléphone et genre passent
 * sur la fiche, les colonnes d'identité quittent `coach` ; les photos de versions perdent l'identité
 * et gagnent l'identifiant de personne, une ligne de coach qui n'existe plus en sort ; une fiche qui
 * manque à l'appel arrête la migration.
 *
 * Cas de production relevés le 2026-11-22 : 20 coachs sur 52 n'ont pas de nom de famille (champ vide) ;
 * aucun doublon de prénom et nom dans un même club. Le décor porte un coach sans nom de famille et deux
 * coachs homonymes (deux saisons du même club, cas des clubs de démonstration).
 *
 * On part de l'état d'AVANT la migration, posé à la main dans une transaction de la connexion `admin`
 * toujours annulée (patron {@see ClubRoleClosingMigrationTest}) : colonnes d'identité sur `coach`,
 * ni `person` ni `coach.person_id`. Puis on joue TOUTES les requêtes de `up()` (et `postUp()`).
 *
 * Jumeau vivant : {@see PersonPlaceTenantIsolationTest} — la clé composée et l'isolation de la fiche
 * sur le schéma réel. Ce test ne garde que le run qui convertit les données.
 */
#[Group('integration')]
final class PersonConversionMigrationTest extends KernelTestCase
{
    private const array IDENTITY_COLUMNS = ['first_name', 'last_name', 'email', 'phone', 'gender'];

    private Connection $admin;

    public function testEveryCoachGetsItsOwnPersonCarryingItsIdentityAndKeepsItsId(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->restorePreConversionSchema();
            [$clubA, $clubB, $season1, $season2] = $this->clubsAndSeasons();
            $paul = $this->coach($clubA, $season1, 'Paul', 'Martin', 'paul@club.fr', '0601020304', 'MALE', employee: true, vehicled: true, maxDays: 3);
            $sacha = $this->coach($clubA, $season1, 'Sacha', '', null, null, 'UNSPECIFIED');
            $paulAgain = $this->coach($clubA, $season2, 'Paul', 'Martin', 'paul@club.fr', '0601020304', 'MALE');
            $other = $this->coach($clubB, $season1, 'Inès', 'Durand', 'ines@autre.fr', null, 'FEMALE');

            $this->play();

            foreach ([
                $paul => [$clubA, 'Paul', 'Martin', 'paul@club.fr', '0601020304', 'MALE'],
                $sacha => [$clubA, 'Sacha', '', null, null, 'UNSPECIFIED'],
                $paulAgain => [$clubA, 'Paul', 'Martin', 'paul@club.fr', '0601020304', 'MALE'],
                $other => [$clubB, 'Inès', 'Durand', 'ines@autre.fr', null, 'FEMALE'],
            ] as $coachId => [$clubId, $firstName, $lastName, $email, $phone, $gender]) {
                $person = $this->admin->fetchAssociative(
                    'SELECT p.club_id, p.first_name, p.last_name, p.email, p.phone, p.gender FROM coach c JOIN person p ON p.id = c.person_id WHERE c.id = ?',
                    [$coachId],
                );
                self::assertIsArray($person, \sprintf('le coach %s garde son identifiant et pointe une fiche', $coachId));
                self::assertSame(
                    ['club_id' => $clubId, 'first_name' => $firstName, 'last_name' => $lastName, 'email' => $email, 'phone' => $phone, 'gender' => $gender],
                    $person,
                    \sprintf('la fiche du coach %s porte son identité, dans son club', $firstName),
                );
            }
            self::assertNotSame($this->personIdOf($paul), $this->personIdOf($paulAgain), 'sans fusion : deux lignes de coach homonymes donnent deux fiches');
            self::assertSame(
                ['is_employee' => true, 'is_vehicled' => true, 'max_days_override' => 3],
                $this->admin->fetchAssociative('SELECT is_employee, is_vehicled, max_days_override FROM coach WHERE id = ?', [$paul]),
                'les options de planning restent sur le rôle de la saison',
            );
            self::assertSame(
                (int) $this->admin->fetchOne('SELECT COUNT(*) FROM coach'),
                (int) $this->admin->fetchOne('SELECT COUNT(*) FROM person'),
                'une personne par ligne de coach, sur toute la base',
            );
        });
    }

    public function testTheIdentityColumnsLeaveTheCoachTable(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->restorePreConversionSchema();

            $this->play();

            self::assertSame([], $this->admin->fetchFirstColumn(
                'SELECT column_name FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = \'coach\' AND column_name IN (?, ?, ?, ?, ?) ORDER BY column_name',
                self::IDENTITY_COLUMNS,
            ), 'aucune copie morte de l\'identité ne reste sur le rôle de saison');
            self::assertSame('NO', $this->admin->fetchOne(
                'SELECT is_nullable FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = \'coach\' AND column_name = \'person_id\'',
            ), 'tout coach pointe une fiche');
        });
    }

    public function testPhotosLoseTheIdentityGainThePersonAndDropACoachThatNoLongerExists(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->restorePreConversionSchema();
            [$clubA, , $season1] = $this->clubsAndSeasons();
            $paul = $this->coach($clubA, $season1, 'Paul', 'Martin', 'paul@club.fr', '0601020304', 'MALE');
            $ghost = $this->uuid();
            $team = ['id' => $this->uuid(), 'clubId' => $clubA, 'seasonId' => $season1, 'name' => 'U13M1'];
            $photo = $this->photo($clubA, $season1, [
                'Team' => [$team],
                'Coach' => [
                    $this->photoCoach($paul, $clubA, $season1, 'Paul', 'Martin', 'paul@club.fr', '0601020304', 'MALE'),
                    $this->photoCoach($ghost, $clubA, $season1, 'Fantôme', 'Parti', 'parti@club.fr', '0700000000', 'FEMALE'),
                ],
            ]);

            $this->play();

            $data = json_decode((string) $this->admin->fetchOne('SELECT data FROM schedule_structure_snapshot WHERE id = ?', [$photo]), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            self::assertEquals([$team], $data['Team'], 'les autres familles de la photo ne bougent pas');
            self::assertCount(1, $data['Coach'], 'la ligne d\'un coach qui n\'existe plus sort de la photo');
            $row = $data['Coach'][0];
            self::assertSame($paul, $row['id']);
            self::assertSame($this->personIdOf($paul), $row['personId'] ?? null, 'la ligne pointe la fiche de la personne');
            foreach (['firstName', 'lastName', 'email', 'phone', 'gender'] as $field) {
                self::assertArrayNotHasKey($field, $row, \sprintf('la photo ne garde plus « %s »', $field));
            }
            $raw = (string) $this->admin->fetchOne('SELECT data::text FROM schedule_structure_snapshot WHERE id = ?', [$photo]);
            foreach (['Martin', 'paul@club.fr', '0601020304', 'Fantôme', 'parti@club.fr'] as $trace) {
                self::assertStringNotContainsString($trace, $raw, 'aucune trace d\'identité dans la photo');
            }
        });
    }

    /**
     * La ligne d'un coach qui n'existe plus sort de la photo AVEC ses liens d'équipe et de joueur et
     * ses règles de portée coach : sinon « Charger » cette version réinsérerait des liens vers un coach
     * absent. Jumeau, cité : `Service/StructureRestorerTest::testLoadingNeverReinsertsLinksOrRulesOfACoachAbsentFromThePhoto`
     * (le filet du chargement, pour toute photo) ; ici la photo nettoyée à la source.
     */
    public function testPhotosDropTheLinksAndRulesOfACoachThatNoLongerExists(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->restorePreConversionSchema();
            [$clubA, , $season1] = $this->clubsAndSeasons();
            $paul = $this->coach($clubA, $season1, 'Paul', 'Martin', null, null, 'MALE');
            $ghost = $this->uuid();
            $team = $this->uuid();
            $paulLink = ['id' => $this->uuid(), 'clubId' => $clubA, 'seasonId' => $season1, 'teamId' => $team, 'coachId' => $paul, 'role' => 'MAIN'];
            $teamRule = ['id' => $this->uuid(), 'clubId' => $clubA, 'seasonId' => $season1, 'scope' => 'TEAM', 'scopeTargetId' => $team];
            $photo = $this->photo($clubA, $season1, [
                'Coach' => [
                    $this->photoCoach($paul, $clubA, $season1, 'Paul', 'Martin', 'paul@club.fr', '0601020304', 'MALE'),
                    $this->photoCoach($ghost, $clubA, $season1, 'Fantôme', 'Parti', 'parti@club.fr', '0700000000', 'FEMALE'),
                ],
                'TeamCoach' => [
                    $paulLink,
                    ['id' => $this->uuid(), 'clubId' => $clubA, 'seasonId' => $season1, 'teamId' => $team, 'coachId' => $ghost, 'role' => 'MAIN'],
                ],
                'CoachPlayerMembership' => [
                    ['id' => $this->uuid(), 'clubId' => $clubA, 'seasonId' => $season1, 'teamId' => $team, 'coachId' => $ghost, 'isActive' => true],
                ],
                'Constraint' => [
                    $teamRule,
                    ['id' => $this->uuid(), 'clubId' => $clubA, 'seasonId' => $season1, 'scope' => 'COACH', 'scopeTargetId' => $ghost],
                ],
            ]);

            $this->play();

            $data = json_decode((string) $this->admin->fetchOne('SELECT data FROM schedule_structure_snapshot WHERE id = ?', [$photo]), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            self::assertEquals([$paulLink], $data['TeamCoach'], 'le lien d\'équipe du coach disparu sort de la photo, celui de Paul reste');
            self::assertEquals([], $data['CoachPlayerMembership'], 'le lien de joueur du coach disparu sort de la photo');
            self::assertEquals([$teamRule], $data['Constraint'], 'la règle de portée coach du coach disparu sort, la règle d\'équipe reste');
        });
    }

    public function testAPersonMissingAfterConversionStopsTheMigration(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->restorePreConversionSchema();
            [$clubA, , $season1] = $this->clubsAndSeasons();
            $this->coach($clubA, $season1, 'Paul', 'Martin', null, null, 'MALE');
            $this->coach($clubA, $season1, 'Perdu', 'Endroute', null, null, 'UNSPECIFIED');

            try {
                // Sabotage posé dès la naissance de la table : la fiche de « Perdu » n'est jamais écrite.
                $this->play(sabotage: true);
                self::fail('une fiche manquante doit arrêter la migration');
            } catch (DriverException $stop) {
                // C'est le contrôle de comptage qui arrête (RAISE EXCEPTION, SQLSTATE P0001), avant toute
                // clé étrangère : sans lui, la pose de la clé composée arrêterait aussi (23503), et ce test
                // resterait vert le contrôle retiré.
                self::assertSame('P0001', $stop->getSQLState(), 'le contrôle de comptage de la migration arrête la conversion');
                self::assertStringContainsString('Conversion des coachs en personnes', $stop->getMessage());
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
     * Le schéma d'AVANT la conversion (`Coach.php` de `lot/conception` au 2026-11-22) : identité sur
     * `coach`, ni fiche ni lien. Idempotent : vaut sur une base migrée avant comme après.
     */
    private function restorePreConversionSchema(): void
    {
        $this->admin->executeStatement('DROP TABLE IF EXISTS person CASCADE');
        $this->admin->executeStatement('ALTER TABLE coach DROP COLUMN IF EXISTS person_id CASCADE');
        $this->admin->executeStatement(
            'ALTER TABLE coach ADD COLUMN IF NOT EXISTS first_name VARCHAR(120) DEFAULT \'\' NOT NULL, '
            . 'ADD COLUMN IF NOT EXISTS last_name VARCHAR(120) DEFAULT \'\' NOT NULL, '
            . 'ADD COLUMN IF NOT EXISTS email VARCHAR(180) DEFAULT NULL, '
            . 'ADD COLUMN IF NOT EXISTS phone VARCHAR(40) DEFAULT NULL, '
            . 'ADD COLUMN IF NOT EXISTS gender VARCHAR(12) DEFAULT \'UNSPECIFIED\' NOT NULL',
        );
        // La table des photos de structure, supprimée au lot 5 (PR 3), existait avant cette conversion.
        $this->admin->executeStatement('CREATE TABLE IF NOT EXISTS schedule_structure_snapshot (id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, schedule_id UUID NOT NULL, data JSON NOT NULL, PRIMARY KEY (id))');
    }

    /** Une instance NEUVE, toutes ses requêtes jouées sur la connexion admin, puis `postUp()`. */
    private function play(bool $sabotage = false): void
    {
        $migration = new Version20261122140000($this->admin, new NullLogger);
        $migration->up(new Schema);
        $queries = $migration->getSql();
        self::assertNotEmpty($queries, 'up() doit planifier ses requêtes par addSql');
        foreach ($queries as $query) {
            $this->admin->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            if ($sabotage && 1 === preg_match('/^\s*CREATE\s+TABLE\s+(public\.)?person\b/i', $query->getStatement())) {
                $this->admin->executeStatement(
                    'CREATE FUNCTION pg_temp.lose_one_person() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.first_name = \'Perdu\' THEN RETURN NULL; END IF; RETURN NEW; END $$',
                );
                $this->admin->executeStatement('CREATE TRIGGER lose_one_person BEFORE INSERT ON person FOR EACH ROW EXECUTE FUNCTION pg_temp.lose_one_person()');
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
                [$clubId, 'Personnes ' . $tag . ' ' . $clubId, 'personnes-' . $clubId],
            );
            $clubs[] = $clubId;
        }

        // `coach.season_id` est un guid nu (sans clé étrangère) : deux guids neufs suffisent.
        return [$clubs[0], $clubs[1], $this->uuid(), $this->uuid()];
    }

    private function coach(string $clubId, string $seasonId, string $firstName, string $lastName, ?string $email, ?string $phone, string $gender, bool $employee = false, bool $vehicled = false, ?int $maxDays = null): string
    {
        $id = $this->uuid();
        $this->admin->executeStatement(
            'INSERT INTO coach (id, version, created_at, updated_at, club_id, season_id, first_name, last_name, email, phone, max_days_override, is_active, is_employee, is_vehicled, gender)'
            . ' VALUES (?, 1, now(), now(), ?, ?, ?, ?, ?, ?, ?, true, ?, ?, ?)',
            [$id, $clubId, $seasonId, $firstName, $lastName, $email, $phone, $maxDays, $employee ? 'true' : 'false', $vehicled ? 'true' : 'false', $gender],
        );

        return $id;
    }

    /** @return array<string, mixed> une ligne de coach telle que `StructureSnapshotter` la photographiait avant la conversion */
    private function photoCoach(string $id, string $clubId, string $seasonId, string $firstName, string $lastName, string $email, string $phone, string $gender): array
    {
        return [
            'id' => $id, 'version' => 1, 'createdAt' => '2026-09-01T10:00:00+00:00', 'updatedAt' => '2026-09-01T10:00:00+00:00',
            'clubId' => $clubId, 'seasonId' => $seasonId, 'firstName' => $firstName, 'lastName' => $lastName,
            'email' => $email, 'phone' => $phone, 'maxDaysOverride' => null, 'isActive' => true, 'isEmployee' => false,
            'isVehicled' => false, 'gender' => $gender,
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

    private function personIdOf(string $coachId): string
    {
        $personId = $this->admin->fetchOne('SELECT person_id FROM coach WHERE id = ?', [$coachId]);
        self::assertIsString($personId, \sprintf('le coach %s pointe une fiche', $coachId));

        return $personId;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
