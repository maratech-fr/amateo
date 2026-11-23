<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Service\TenantConnectionContext;
use App\Structure\PersonDirectory;
use App\Tests\Integration\Migration\PersonConversionMigrationTest;
use App\Tests\Integration\Migration\PlaceConversionMigrationTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR d'axe *tenant isolation* (lot conception 3, PR 2b pour la personne, PR 4 pour le lieu) : la fiche
 * d'une personne et le lieu d'un gymnase appartiennent à leur club. Un autre club ne les lit pas, ne
 * les modifie pas, ne les efface pas ; un coach ne peut désigner que la fiche d'une personne de son
 * propre club, un gymnase que le lieu de son propre club ; une personne a au plus un rôle coach par
 * saison, un lieu au plus un gymnase par saison, et ni la fiche ni le lieu ne disparaissent sous la
 * ligne de saison qui les pointe.
 *
 * Prouvé en SQL brut sur la connexion de l'application (`amateo_app`, sous RLS, aucune couche
 * applicative) : la RLS de `person` et de `place`, les clés étrangères COMPOSÉES `(person_id, club_id)`
 * et `(place_id, club_id)`. Une clé simple `person_id → person(id)` laisserait passer un coach du
 * club A pointant la fiche du club B : une vérification de clé étrangère ne voit pas la RLS. dama
 * annule chaque test (lignes et GUC).
 *
 * Jumeaux cités : {@see RlsIsolationTest::testEveryClubIdTableIsUnderForcedRls} (RLS forcée sur toute
 * table à `club_id`, `person` et `place` comprises), {@see PersonConversionMigrationTest} et
 * {@see PlaceConversionMigrationTest} (les runs qui créent les fiches et les lieux).
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

    /** Lot 3, PR 4 : le lieu d'un gymnase (adresse, position) appartient à son club, comme la fiche d'une personne. */
    public function testAClubNeverReadsModifiesOrErasesThePlaceOfAnotherClub(): void
    {
        [$placeA, $placeB] = $this->placesOfBothClubs();
        $this->guc->setClubId($this->clubA);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM place WHERE id = ?', [$placeB]), 'le lieu du club B est invisible au club A');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM place WHERE id = ?', [$placeA]), 'témoin : le club A voit son propre lieu');
        self::assertSame(0, $this->connection->executeStatement('UPDATE place SET address = ? WHERE id = ?', ['ailleurs', $placeB]), 'UPDATE d\'un lieu d\'un autre club : zéro ligne');
        self::assertSame(0, $this->connection->executeStatement('DELETE FROM place WHERE id = ?', [$placeB]), 'DELETE d\'un lieu d\'un autre club : zéro ligne');

        $this->guc->setClubId($this->clubB);
        self::assertSame('2 place du Club B', $this->connection->fetchOne('SELECT address FROM place WHERE id = ?', [$placeB]), 'le lieu du club B est intact');
    }

    public function testAVenueCannotDesignateThePlaceOfAnotherClub(): void
    {
        [$placeA, $placeB] = $this->placesOfBothClubs();
        $this->guc->setClubId($this->clubA);

        self::assertSame('23503', $this->sqlStateOf(fn (): int => $this->insertVenue($this->clubA, $placeB)), 'un gymnase du club A ne pointe jamais le lieu du club B (clé composée avec le club)');
        self::assertNull($this->sqlStateOf(fn (): int => $this->insertVenue($this->clubA, $placeA)), 'témoin : un gymnase du club A pointe un lieu du club A');
    }

    public function testAPlaceHasOneVenuePerSeasonAndCannotVanishUnderIt(): void
    {
        [$placeA] = $this->placesOfBothClubs();
        $this->guc->setClubId($this->clubA);
        $season = $this->uuid();
        $this->insertVenue($this->clubA, $placeA, $season);

        self::assertSame('23505', $this->sqlStateOf(fn (): int => $this->insertVenue($this->clubA, $placeA, $season)), 'au plus un gymnase par lieu et par saison');
        self::assertNull($this->sqlStateOf(fn (): int => $this->insertVenue($this->clubA, $placeA)), 'témoin : le même lieu sert un gymnase d\'une autre saison');
        self::assertSame('23503', $this->sqlStateOf(fn (): int => $this->connection->executeStatement('DELETE FROM place WHERE id = ?', [$placeA])), 'un lieu pointé par un gymnase ne s\'efface pas (ON DELETE RESTRICT)');
    }

    /** La forme exacte de la clé gymnase → lieu, patron de {@see testTheCoachToPersonKeyIsCompositeWithTheClub}. */
    public function testTheVenueToPlaceKeyIsCompositeWithTheClub(): void
    {
        $keys = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT
              (SELECT string_agg(a.attname, ',' ORDER BY k.ord) FROM unnest(c.conkey) WITH ORDINALITY k(attnum, ord) JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum) AS local_columns,
              (SELECT string_agg(a.attname, ',' ORDER BY k.ord) FROM unnest(c.confkey) WITH ORDINALITY k(attnum, ord) JOIN pg_attribute a ON a.attrelid = c.confrelid AND a.attnum = k.attnum) AS referenced_columns,
              c.confdeltype AS on_delete
            FROM pg_constraint c
            WHERE c.contype = 'f' AND c.conrelid = 'public.venue'::regclass AND c.confrelid = 'public.place'::regclass
            SQL);

        self::assertSame([['local_columns' => 'place_id,club_id', 'referenced_columns' => 'id,club_id', 'on_delete' => 'r']], $keys, 'une seule clé venue → place : (place_id, club_id) → (id, club_id), ON DELETE RESTRICT');
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

    /**
     * Un lieu dans chaque club, posé sous le contexte de son club. Hors de `setUp` : les cas de la fiche
     * de personne ne dépendent pas de la table `place`.
     *
     * @return array{0: string, 1: string} [lieu du club A, lieu du club B]
     */
    private function placesOfBothClubs(): array
    {
        $places = [];
        foreach ([[$this->clubA, '1 place du Club A'], [$this->clubB, '2 place du Club B']] as [$clubId, $address]) {
            $this->guc->setClubId($clubId);
            $id = $this->uuid();
            $this->connection->executeStatement(
                'INSERT INTO place (id, version, created_at, updated_at, club_id, address, latitude, longitude) VALUES (?, 1, now(), now(), ?, ?, 45.7000000, 4.8000000)',
                [$id, $clubId, $address],
            );
            $places[] = $id;
        }
        $this->guc->clear();

        return [$places[0], $places[1]];
    }

    private function insertVenue(string $clubId, string $placeId, ?string $seasonId = null): int
    {
        return $this->connection->executeStatement(
            'INSERT INTO venue (id, version, created_at, updated_at, club_id, season_id, place_id, name, source, is_active) VALUES (?, 1, now(), now(), ?, ?, ?, \'Gymnase\', \'manual\', true)',
            [$this->uuid(), $clubId, $seasonId ?? $this->uuid(), $placeId],
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
