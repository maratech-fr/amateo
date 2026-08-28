<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\OpponentDirectoryEntry;
use App\Enum\OpponentLocationPrecision;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Tests\TenantGucTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR BLOQUANT — P2-54 RMM-9 « annuaire adverse global : localisation d'un adversaire
 * partagée entre clubs » (axe §7.1 : tenant isolation — l'invariant de PARTAGE hors-tenant
 * est structurant, patron `EntryDeadlineShareTest`).
 *
 * POURQUOI ce test existe : `opponent_directory` est une table PARTAGÉE entre clubs — la
 * localisation d'un adversaire (salle ou ville), keyée sur son code organisme FÉDÉRAL PUBLIC,
 * bénéficie à tout club engagé contre le même adversaire. C'est une surface qui traverse la
 * frontière tenant, et la décision fondateur + la revue sécurité sont strictes : la table ne
 * porte AUCUNE donnée club-identifiante (pas de club_id, pas de provenance, pas de compteur —
 * sinon c'est une fuite tenant, vecteur A21/BCK-18). Sans ce test, une colonne
 * `club_id`/`author_id` pourrait s'y glisser, un club pourrait déduire quel AUTRE club a écrit
 * une entrée, ou une résolution ville pourrait piétiner une salle déjà connue. Falsifié dans
 * les DEUX sens.
 *
 * (a) le SCHÉMA du partagé n'a AUCUNE colonne club-identifiante (catalogue Postgres, liste
 *     blanche exacte) · (b) une entrée est VISIBLE et BYTE-IDENTIQUE quel que soit le club qui
 *     lit (zéro oracle, aucun filtre tenant) · (c) le rôle applicatif amateo_app a
 *     SELECT+INSERT+UPDATE mais PAS DELETE (le GRANT est la seule couche DB d'une table sans
 *     RLS) · (d) une résolution PLUS précise (VENUE) remplace une moins précise (CITY), JAMAIS
 *     l'inverse.
 */
#[Group('phase1')]
#[Group('security')]
final class OpponentDirectoryShareTest extends WebTestCase
{
    use TenantGucTrait;

    private const string CODE = 'ARA0069999';

    private EntityManagerInterface $em;

    // ─────────────────────────────────────────────────────────────────────────
    // (a) le SCHÉMA du partagé — aucune colonne club-identifiante
    // ─────────────────────────────────────────────────────────────────────────

    public function testSharedTableSchemaHasNoClubIdentifyingColumn(): void
    {
        /** @var list<string> $columns */
        $columns = $this->conn()->fetchFirstColumn(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = \'opponent_directory\' ORDER BY column_name',
        );
        sort($columns);

        // Liste blanche EXACTE — falsifiée dans les deux sens (une colonne en plus ou en moins rougit).
        self::assertSame(
            ['city', 'ffbb_organisme_code', 'id', 'latitude', 'longitude', 'name', 'postal_code', 'precision', 'resolved_at', 'venue_label'],
            $columns,
            'l\'annuaire adverse ne porte QUE ces colonnes — aucune donnée club-identifiante',
        );

        // Explicite pour le lecteur : les colonnes interdites PAR CONCEPTION sont absentes.
        foreach (['club_id', 'user_id', 'season_id', 'author_id', 'source_club_id', 'set_count', 'usage_count'] as $forbidden) {
            self::assertNotContains($forbidden, $columns, \sprintf('« %s » est interdite par conception sur le partagé', $forbidden));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (b) une entrée est visible et byte-identique quel que soit le club lecteur
    // ─────────────────────────────────────────────────────────────────────────

    public function testEntryIsVisibleAndByteIdenticalRegardlessOfClub(): void
    {
        $this->seedVenue(self::CODE, 'BASKET CLUB TEST', 'Villeurbanne', '69100', 45.76499, 4.9051, 'GYMNASE JEANNE DESPARMET-RUELLO');

        // Deux clubs DIFFÉRENTS lisent la MÊME ligne globale : ni le filtre Doctrine tenant
        // (l'entité n'est pas TenantOwnedInterface), ni la RLS (aucun club_id) ne la masquent.
        $clubA = $this->uuid('aaaa');
        $clubB = $this->uuid('bbbb');

        $fragmentA = $this->rawRowFragment($clubA);
        $fragmentB = $this->rawRowFragment($clubB);

        self::assertNotSame('', $fragmentA, 'la ligne partagée est visible sous le club A');
        self::assertSame($fragmentB, $fragmentA, 'ce qui est servi ne dépend pas du club lecteur — la table est sans identité');

        // La lecture ORM (repository) est elle aussi non filtrée par le tenant.
        $this->scopeGucToClub($this->uuid('cccc'));
        $entry = $this->repository()->findOneByFfbbOrganismeCode(self::CODE);
        self::assertInstanceOf(OpponentDirectoryEntry::class, $entry, 'un troisième club voit la même entrée (aucun filtre tenant)');
        self::assertSame('BASKET CLUB TEST', $entry->getName());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (c) le rôle applicatif a SELECT+INSERT+UPDATE mais PAS DELETE
    // ─────────────────────────────────────────────────────────────────────────

    public function testRuntimeRoleCannotDeleteFromTheSharedTable(): void
    {
        self::assertTrue(
            (bool) $this->conn()->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_app\''),
            'le rôle applicatif amateo_app existe (prémisse du modèle de sécurité)',
        );

        foreach (['SELECT', 'INSERT', 'UPDATE'] as $privilege) {
            self::assertTrue((bool) $this->conn()->fetchOne(
                'SELECT has_table_privilege(\'amateo_app\', \'opponent_directory\', :privilege)',
                ['privilege' => $privilege],
            ), \sprintf('amateo_app doit avoir %s (l\'upsert de l\'annuaire l\'exige)', $privilege));
        }

        self::assertFalse((bool) $this->conn()->fetchOne(
            'SELECT has_table_privilege(\'amateo_app\', \'opponent_directory\', :privilege)',
            ['privilege' => 'DELETE'],
        ), 'amateo_app ne doit JAMAIS pouvoir DELETE une ligne partagée (corollaire F-2, seule couche DB sans RLS)');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (d) VENUE remplace CITY, jamais l'inverse
    // ─────────────────────────────────────────────────────────────────────────

    public function testMorePreciseResolutionReplacesLessPreciseNeverTheReverse(): void
    {
        $repository = $this->repository();

        // CITY d'abord.
        $repository->upsert(self::CODE, OpponentLocationPrecision::CITY, [
            'name' => 'BASKET CLUB TEST',
            'city' => 'Villeurbanne',
            'postalCode' => '69100',
            'latitude' => 45.7,
            'longitude' => 4.9,
            'venueLabel' => null,
        ]);
        $this->em->flush();

        // VENUE remplace CITY (résolution plus précise).
        $repository->upsert(self::CODE, OpponentLocationPrecision::VENUE, [
            'name' => 'BASKET CLUB TEST',
            'city' => 'Villeurbanne',
            'postalCode' => '69100',
            'latitude' => 45.76499,
            'longitude' => 4.9051,
            'venueLabel' => 'GYMNASE JEANNE DESPARMET-RUELLO',
        ]);
        $this->em->flush();
        $this->em->clear();

        $upgraded = $repository->findOneByFfbbOrganismeCode(self::CODE);
        self::assertInstanceOf(OpponentDirectoryEntry::class, $upgraded);
        self::assertSame(OpponentLocationPrecision::VENUE, $upgraded->getPrecision(), 'VENUE remplace CITY');
        self::assertSame('GYMNASE JEANNE DESPARMET-RUELLO', $upgraded->getVenueLabel());

        // CITY NE redescend PAS un VENUE connu (falsification inverse).
        $repository->upsert(self::CODE, OpponentLocationPrecision::CITY, [
            'name' => 'AUTRE NOM',
            'city' => 'Lyon',
            'postalCode' => '69000',
            'latitude' => 45.75,
            'longitude' => 4.85,
            'venueLabel' => null,
        ]);
        $this->em->flush();
        $this->em->clear();

        $kept = $repository->findOneByFfbbOrganismeCode(self::CODE);
        self::assertInstanceOf(OpponentDirectoryEntry::class, $kept);
        self::assertSame(OpponentLocationPrecision::VENUE, $kept->getPrecision(), 'une résolution ville ne dégrade JAMAIS une salle connue');
        self::assertSame('GYMNASE JEANNE DESPARMET-RUELLO', $kept->getVenueLabel(), 'la salle connue est préservée');
    }

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Infrastructure
    // ─────────────────────────────────────────────────────────────────────────

    private function seedVenue(string $code, string $name, string $city, string $postalCode, float $lat, float $lng, string $venueLabel): void
    {
        $this->repository()->upsert($code, OpponentLocationPrecision::VENUE, [
            'name' => $name,
            'city' => $city,
            'postalCode' => $postalCode,
            'latitude' => $lat,
            'longitude' => $lng,
            'venueLabel' => $venueLabel,
        ]);
        $this->em->flush();
    }

    /** The full row as JSON, read via raw SQL under the given club GUC. */
    private function rawRowFragment(string $clubId): string
    {
        $this->scopeGucToClub($clubId);
        $row = $this->conn()->fetchAssociative(
            'SELECT ffbb_organisme_code, name, city, postal_code, latitude, longitude, precision, venue_label FROM opponent_directory WHERE ffbb_organisme_code = :code',
            ['code' => self::CODE],
        );

        return false === $row ? '' : json_encode($row, \JSON_THROW_ON_ERROR);
    }

    private function repository(): OpponentDirectoryEntryRepository
    {
        $repository = self::getContainer()->get(OpponentDirectoryEntryRepository::class);
        self::assertInstanceOf(OpponentDirectoryEntryRepository::class, $repository);

        return $repository;
    }

    private function conn(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function uuid(string $seed): string
    {
        $hex = substr(md5($seed), 0, 12);

        return \sprintf('%s-%s-4%s-8%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), '111', '111', '111111111111');
    }
}
