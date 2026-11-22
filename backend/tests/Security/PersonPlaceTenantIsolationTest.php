<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Service\TenantConnectionContext;
use App\Structure\PersonDirectory;
use App\Tests\Integration\Migration\PersonConversionMigrationTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR d'axe *tenant isolation* (lot conception 3, PR 2b ; les lieux s'y ajoutent à la PR 4) : la fiche
 * d'une personne appartient à son club. Un autre club ne la lit pas, ne la modifie pas, ne l'efface
 * pas ; un coach ne peut désigner que la fiche d'une personne de son propre club ; une personne a au
 * plus un rôle coach par saison, et sa fiche ne disparaît pas sous un rôle qui la pointe.
 *
 * Prouvé en SQL brut sur la connexion de l'application (`amateo_app`, sous RLS, aucune couche
 * applicative) : la RLS de `person` et la clé étrangère COMPOSÉE `(person_id, club_id)`. Une clé
 * simple `person_id → person(id)` laisserait passer un coach du club A pointant la fiche du club B :
 * une vérification de clé étrangère ne voit pas la RLS. dama annule chaque test (lignes et GUC).
 *
 * Jumeaux cités : {@see RlsIsolationTest::testEveryClubIdTableIsUnderForcedRls} (RLS forcée sur toute
 * table à `club_id`, `person` comprise), {@see PersonConversionMigrationTest} (le run qui crée les
 * fiches).
 */
#[Group('phase1')]
#[Group('integration')]
final class PersonPlaceTenantIsolationTest extends KernelTestCase
{
    private Connection $connection;

    private TenantConnectionContext $guc;

    private string $clubA;

    private string $clubB;

    private string $personA;

    private string $personB;

    public function testAClubNeverReadsThePersonRecordOfAnotherClub(): void
    {
        $this->guc->setClubId($this->clubA);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM person WHERE id = ?', [$this->personB]), 'la fiche du club B est invisible au club A');
        self::assertSame([$this->clubA], $this->connection->fetchFirstColumn('SELECT DISTINCT club_id FROM person WHERE id IN (?, ?)', [$this->personA, $this->personB]), 'témoin : le club A voit sa propre fiche');
        self::assertSame([], self::getContainer()->get(PersonDirectory::class)->emailsOfClub($this->clubB), 'la porte de lecture de l\'identité ne rend aucune adresse d\'un autre club');
    }

    public function testAClubNeverModifiesOrErasesThePersonRecordOfAnotherClub(): void
    {
        $this->guc->setClubId($this->clubA);

        self::assertSame(0, $this->connection->executeStatement('UPDATE person SET phone = ? WHERE id = ?', ['0000000000', $this->personB]), 'UPDATE d\'une fiche d\'un autre club : zéro ligne');
        self::assertSame(0, $this->connection->executeStatement('DELETE FROM person WHERE id = ?', [$this->personB]), 'DELETE d\'une fiche d\'un autre club : zéro ligne');

        $this->guc->setClubId($this->clubB);
        self::assertSame('0611111111', $this->connection->fetchOne('SELECT phone FROM person WHERE id = ?', [$this->personB]), 'la fiche du club B est intacte');
    }

    public function testACoachCannotDesignateThePersonRecordOfAnotherClub(): void
    {
        $this->guc->setClubId($this->clubA);

        self::assertSame('23503', $this->sqlStateOf(fn (): int => $this->insertCoach($this->clubA, $this->personB)), 'un coach du club A ne pointe jamais la fiche du club B (clé composée avec le club)');
        self::assertNull($this->sqlStateOf(fn (): int => $this->insertCoach($this->clubA, $this->personA)), 'témoin : un coach du club A pointe une fiche du club A');
    }

    public function testAPersonHasOneCoachRolePerSeasonAndItsRecordCannotVanishUnderIt(): void
    {
        $this->guc->setClubId($this->clubA);
        $season = $this->uuid();
        $this->insertCoach($this->clubA, $this->personA, $season);

        self::assertSame('23505', $this->sqlStateOf(fn (): int => $this->insertCoach($this->clubA, $this->personA, $season)), 'au plus un rôle coach par personne et par saison');
        self::assertNull($this->sqlStateOf(fn (): int => $this->insertCoach($this->clubA, $this->personA)), 'témoin : la même personne coache une autre saison');
        self::assertSame('23503', $this->sqlStateOf(fn (): int => $this->connection->executeStatement('DELETE FROM person WHERE id = ?', [$this->personA])), 'une fiche pointée par un rôle ne s\'efface pas (ON DELETE RESTRICT)');
    }

    /** La forme exacte de la clé : un autre club n'y entre que si la colonne du club fait partie de la référence. */
    public function testTheCoachToPersonKeyIsCompositeWithTheClub(): void
    {
        $keys = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT
              (SELECT string_agg(a.attname, ',' ORDER BY k.ord) FROM unnest(c.conkey) WITH ORDINALITY k(attnum, ord) JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum) AS local_columns,
              (SELECT string_agg(a.attname, ',' ORDER BY k.ord) FROM unnest(c.confkey) WITH ORDINALITY k(attnum, ord) JOIN pg_attribute a ON a.attrelid = c.confrelid AND a.attnum = k.attnum) AS referenced_columns,
              c.confdeltype AS on_delete
            FROM pg_constraint c
            WHERE c.contype = 'f' AND c.conrelid = 'public.coach'::regclass AND c.confrelid = 'public.person'::regclass
            SQL);

        self::assertSame([['local_columns' => 'person_id,club_id', 'referenced_columns' => 'id,club_id', 'on_delete' => 'r']], $keys, 'une seule clé coach → person : (person_id, club_id) → (id, club_id), ON DELETE RESTRICT');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->guc = self::getContainer()->get(TenantConnectionContext::class);

        $this->clubA = $this->insertClub('A');
        $this->clubB = $this->insertClub('B');
        $this->guc->setClubId($this->clubA);
        $this->personA = $this->insertPerson($this->clubA, 'Paul', 'paul@club-a.fr', '0600000000');
        $this->guc->setClubId($this->clubB);
        $this->personB = $this->insertPerson($this->clubB, 'Inès', 'ines@club-b.fr', '0611111111');
        $this->guc->clear();
    }

    private function insertClub(string $tag): string
    {
        $id = $this->uuid();
        // `club` n'a pas de colonne club_id : hors RLS.
        $this->connection->executeStatement(
            'INSERT INTO club (id, version, created_at, updated_at, name, slug, timezone, locale, onboarding_completed, generation_count_season) VALUES (?, 1, now(), now(), ?, ?, \'Europe/Paris\', \'fr\', true, 0)',
            [$id, 'Fiches ' . $tag . ' ' . $id, 'fiches-' . $id],
        );

        return $id;
    }

    private function insertPerson(string $clubId, string $firstName, string $email, string $phone): string
    {
        $id = $this->uuid();
        $this->connection->executeStatement(
            'INSERT INTO person (id, version, created_at, updated_at, club_id, first_name, last_name, email, phone, gender) VALUES (?, 1, now(), now(), ?, ?, \'Test\', ?, ?, \'UNSPECIFIED\')',
            [$id, $clubId, $firstName, $email, $phone],
        );

        return $id;
    }

    private function insertCoach(string $clubId, string $personId, ?string $seasonId = null): int
    {
        return $this->connection->executeStatement(
            'INSERT INTO coach (id, version, created_at, updated_at, club_id, season_id, person_id, is_active, is_employee, is_vehicled) VALUES (?, 1, now(), now(), ?, ?, ?, true, false, false)',
            [$this->uuid(), $clubId, $seasonId ?? $this->uuid(), $personId],
        );
    }

    /** SQLSTATE du refus d'une écriture jouée dans un point de sauvegarde annulé, ou null si elle passe. */
    private function sqlStateOf(callable $write): ?string
    {
        $this->connection->beginTransaction();
        try {
            $write();

            return null;
        } catch (DriverException $refusal) {
            return $refusal->getSQLState();
        } finally {
            $this->connection->rollBack();
        }
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
