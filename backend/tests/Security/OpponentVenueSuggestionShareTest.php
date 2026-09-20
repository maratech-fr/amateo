<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\FixtureHomeAway;
use App\Enum\SeasonStatus;
use App\Repository\ClubRepository;
use App\Repository\FixtureRepository;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentVenueLinkRepository;
use App\Repository\OpponentVenueSuggestionRepository;
use App\Service\Basketball\FfbbApiClient;
use App\Service\Basketball\FfbbSalleResolver;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\OpponentTravelResolver;
use App\Service\Geo\OpponentVenueLinkManager;
use App\Service\Geo\TravelTimeCache;
use App\Service\OpponentPairingKey;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * NR BLOQUANT — P2-54 « adversaire multi-gymnases » PR-2 « suggestions PARTAGÉES de
 * gymnases par club adverse » (axe §7.1 : tenant isolation — l'invariant de PARTAGE
 * hors-tenant est structurant, patron `OpponentDirectoryShareTest`/`EntryDeadlineShareTest`).
 *
 * POURQUOI ce test existe : `opponent_venue_suggestion` est une table PARTAGÉE entre
 * clubs — les gymnases connus d'un adversaire (vus dans le calendrier fédéral, ou
 * choisis par des clubs), keyés sur le code organisme fédéral PUBLIC. La décision
 * fondateur + la revue sécurité sont strictes : « UN COMPTE, JAMAIS UN QUI », et
 * JAMAIS de texte SAISI par un club — le libellé/les coordonnées d'un choix manuel sont
 * RE-RÉSOLUS côté serveur contre l'index FFBB, jamais pris du corps. Falsifié dans les
 * DEUX sens.
 *
 * (a) le SCHÉMA du partagé n'a AUCUNE colonne club-identifiante (catalogue Postgres,
 *     liste blanche exacte + interdits nommés) · (b) la réponse de l'endpoint est
 *     BYTE-IDENTIQUE quel que soit le club lecteur, sans aucune identité · (c) le rôle
 *     app a SELECT+INSERT+UPDATE mais PAS DELETE · (d) le décompte ne descend JAMAIS
 *     sous 0 · (e) un choix manuel écrit le libellé FÉDÉRAL (jamais le texte du corps),
 *     une ref inconnue n'écrit RIEN, un second club au corps différent ne réécrit pas
 *     la ligne · (f) le trajet de A ne change pas quand B choisit.
 */
#[Group('phase1')]
#[Group('security')]
final class OpponentVenueSuggestionShareTest extends WebTestCase
{
    use TenantGucTrait;

    private const string CODE = 'ARA0069424';

    private const string REF = '166900001';

    // Salles FÉDÉRALES que le mock FfbbSalleResolver résout ; UNKNOWN_REF ne résout jamais.
    private const string FED_REF_A = '166900801';

    private const string FED_REF_B = '166900802';

    private const string FED_LABEL_A = 'GYMNASE FEDERAL ALPHA';

    private const string FED_LABEL_B = 'GYMNASE FEDERAL BRAVO';

    private const string UNKNOWN_REF = '999999999';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    // ─────────────────────────────────────────────────────────────────────────
    // (a) le SCHÉMA du partagé — aucune colonne club-identifiante
    // ─────────────────────────────────────────────────────────────────────────

    public function testSharedTableSchemaHasNoClubIdentifyingColumn(): void
    {
        /** @var list<string> $columns */
        $columns = $this->conn()->fetchFirstColumn(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = \'opponent_venue_suggestion\' ORDER BY column_name',
        );
        sort($columns);

        // Liste blanche EXACTE — falsifiée dans les deux sens (une colonne en plus ou en moins rougit).
        self::assertSame(
            ['chosen_by_count', 'city', 'created_at', 'ffbb_organisme_code', 'id', 'last_chosen_at', 'latitude', 'longitude', 'postal_code', 'source', 'updated_at', 'venue_external_ref', 'venue_label'],
            $columns,
            'la suggestion partagée ne porte QUE ces colonnes — un compte, jamais un qui',
        );

        // Explicite pour le lecteur : les colonnes interdites PAR CONCEPTION sont absentes.
        foreach (['club_id', 'user_id', 'season_id', 'author_id', 'source_club_id', 'created_by', 'chosen_by'] as $forbidden) {
            self::assertNotContains($forbidden, $columns, \sprintf('« %s » est interdite par conception sur le partagé', $forbidden));
        }
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
                'SELECT has_table_privilege(\'amateo_app\', \'opponent_venue_suggestion\', :privilege)',
                ['privilege' => $privilege],
            ), \sprintf('amateo_app doit avoir %s (upsert/incrément/décrément l\'exigent)', $privilege));
        }

        self::assertFalse((bool) $this->conn()->fetchOne(
            'SELECT has_table_privilege(\'amateo_app\', \'opponent_venue_suggestion\', :privilege)',
            ['privilege' => 'DELETE'],
        ), 'amateo_app ne doit JAMAIS pouvoir DELETE une suggestion partagée (corollaire F-2 ; A2 : une ligne à 0 reste)');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (d) le décompte ne descend jamais sous 0 (GREATEST)
    // ─────────────────────────────────────────────────────────────────────────

    public function testDecrementNeverGoesBelowZero(): void
    {
        $repository = $this->suggestions();
        $repository->upsertManual(self::CODE, self::REF, 'GYMNASE ZERO', null, null, 45.7, 4.9);
        self::assertSame(0, $this->sharedCount(self::CODE, self::REF), 'une suggestion fraîche naît à 0');

        $repository->decrement(self::CODE, self::REF);
        $repository->decrement(self::CODE, self::REF);
        self::assertSame(0, $this->sharedCount(self::CODE, self::REF), 'GREATEST(0, …) : le compte ne devient jamais négatif');

        $repository->increment(self::CODE, self::REF);
        self::assertSame(1, $this->sharedCount(self::CODE, self::REF), 'un incrément le remonte à 1');
        $repository->decrement(self::CODE, self::REF);
        self::assertSame(0, $this->sharedCount(self::CODE, self::REF), 'et le décrément redescend à 0, jamais en dessous');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (b) réponse byte-identique quel que soit le club lecteur, sans identité
    // ─────────────────────────────────────────────────────────────────────────

    public function testServedSuggestionIsByteIdenticalRegardlessOfReaderAndCarriesNoIdentity(): void
    {
        $ffbb = 'ARA00694' . random_int(10, 99);

        // Une suggestion FÉDÉRALE partagée (semée comme le résolveur l'écrirait).
        $this->suggestions()->upsertManual($ffbb, self::FED_REF_A, self::FED_LABEL_A, 'Lyon', '69001', 45.76, 4.86);
        $this->suggestions()->increment($ffbb, self::FED_REF_A);

        // Deux clubs DIFFÉRENTS, chacun engagé à l'extérieur contre le même adversaire.
        [$clubA, $seasonA, $userA] = $this->createClub('ba');
        $this->awayFixture($seasonA, $ffbb, 'ADVERSAIRE PARTAGE');
        [, $seasonB, $userB] = $this->createClub('bb');
        $this->awayFixture($seasonB, $ffbb, 'ADVERSAIRE PARTAGE');

        $fragmentA = json_encode($this->getSuggestions($userA, $ffbb), \JSON_THROW_ON_ERROR);
        $fragmentB = json_encode($this->getSuggestions($userB, $ffbb), \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"chosenByCount":1', $fragmentA, 'la suggestion est « choisie par 1 »');
        self::assertSame($fragmentB, $fragmentA, 'ce qui est servi ne dépend pas du club lecteur — la table est sans identité');

        // La LIGNE partagée ne porte aucune identité : ni club, ni user, ni provenance.
        $row = $this->conn()->fetchAssociative(
            'SELECT * FROM opponent_venue_suggestion WHERE ffbb_organisme_code = :code AND venue_external_ref = :ref',
            ['code' => $ffbb, 'ref' => self::FED_REF_A],
        );
        self::assertIsArray($row);
        self::assertSame(
            ['chosen_by_count', 'city', 'created_at', 'ffbb_organisme_code', 'id', 'last_chosen_at', 'latitude', 'longitude', 'postal_code', 'source', 'updated_at', 'venue_external_ref', 'venue_label'],
            $this->sortedKeys($row),
            'la ligne ne porte que les colonnes du partagé',
        );
        $serialized = json_encode($row, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($clubA->getId(), $serialized, 'aucune trace du club dans la ligne partagée');
        self::assertStringNotContainsString($userA->getId(), $serialized, 'aucune trace de l\'utilisateur dans la ligne partagée');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (e) le partagé ne reçoit QUE des données fédérales (revue sécurité 2026-09-15)
    // ─────────────────────────────────────────────────────────────────────────

    public function testManualChoiceStoresTheFederalLabelNotTheClientText(): void
    {
        $ffbb = 'ARA00695' . random_int(10, 99);
        [$club, $season] = $this->createClub('fed');

        // Le corps ment sur le libellé ET les coordonnées : le partagé doit écrire le
        // libellé FÉDÉRAL re-résolu, JAMAIS le texte du client.
        $this->scopeGucToClub($club->getId());
        $this->manager()->addOrUpdate($club->getId(), $ffbb, 'SALLE FBI', 'LIBELLE FORGE PAR LE CLUB', self::FED_REF_A, 45.76, 4.86);
        unset($season);

        $row = $this->conn()->fetchAssociative(
            'SELECT venue_label, city, postal_code FROM opponent_venue_suggestion WHERE ffbb_organisme_code = :code AND venue_external_ref = :ref',
            ['code' => $ffbb, 'ref' => self::FED_REF_A],
        );
        self::assertIsArray($row);
        self::assertSame(self::FED_LABEL_A, $row['venue_label'], 'le libellé partagé vient de l\'index FFBB, jamais du corps client');
        self::assertStringNotContainsString('FORGE', (string) $row['venue_label']);
        self::assertSame('Lyon', $row['city'], 'la ville aussi vient du hit fédéral');
    }

    public function testManualWithUnresolvableRefWritesNothingToShared(): void
    {
        $ffbb = 'ARA00696' . random_int(10, 99);
        [$club, $season] = $this->createClub('unk');

        // Ref inconnue de l'index FFBB : la correction reste TENANT, RIEN au partagé.
        $this->scopeGucToClub($club->getId());
        $this->manager()->addOrUpdate($club->getId(), $ffbb, 'SALLE FBI', 'GYMNASE INVENTE', self::UNKNOWN_REF, 45.76, 4.86);
        unset($season);

        self::assertSame(0, (int) $this->conn()->fetchOne(
            'SELECT COUNT(*) FROM opponent_venue_suggestion WHERE ffbb_organisme_code = :code',
            ['code' => $ffbb],
        ), 'une ref non fédérale n\'écrit rien dans le partagé (tenant seulement)');
    }

    public function testSecondClubWithADifferentBodyLabelDoesNotChangeTheFederalRow(): void
    {
        $ffbb = 'ARA00697' . random_int(10, 99);
        [$clubA, $seasonA] = $this->createClub('la');
        [$clubB, $seasonB] = $this->createClub('lb');
        $manager = $this->manager();

        $this->scopeGucToClub($clubA->getId());
        $manager->addOrUpdate($clubA->getId(), $ffbb, 'SALLE FBI', 'CORPS DE A', self::FED_REF_A, 45.76, 4.86);
        $this->scopeGucToClub($clubB->getId());
        $manager->addOrUpdate($clubB->getId(), $ffbb, 'SALLE FBI', 'CORPS DE B DIFFERENT', self::FED_REF_A, 45.10, 4.10);
        unset($seasonA, $seasonB);

        $row = $this->conn()->fetchAssociative(
            'SELECT venue_label, chosen_by_count FROM opponent_venue_suggestion WHERE ffbb_organisme_code = :code AND venue_external_ref = :ref',
            ['code' => $ffbb, 'ref' => self::FED_REF_A],
        );
        self::assertIsArray($row);
        self::assertSame(self::FED_LABEL_A, $row['venue_label'], 'le libellé reste le fédéral du premier writer — un second corps ne le réécrit pas');
        self::assertSame(2, (int) $row['chosen_by_count'], 'deux clubs → le compte monte à 2, le libellé ne bouge pas');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (f) le trajet (tenant) de A ne change pas quand B choisit
    // ─────────────────────────────────────────────────────────────────────────

    public function testChoiceOfBLeavesTravelOfAUntouched(): void
    {
        $ffbb = 'ARA00698' . random_int(10, 99);
        [$clubA, $seasonA] = $this->createClub('ta');
        [$clubB, $seasonB] = $this->createClub('tb');
        $manager = $this->manager();

        $this->scopeGucToClub($clubA->getId());
        $manager->addOrUpdate($clubA->getId(), $ffbb, 'SALLE FBI', 'GYMNASE DE A', self::FED_REF_A, 45.76, 4.86);
        $linkBefore = $this->linkRowOf($clubA->getId(), $ffbb);

        $this->scopeGucToClub($clubB->getId());
        $manager->addOrUpdate($clubB->getId(), $ffbb, 'SALLE FBI', 'GYMNASE DE B', self::FED_REF_B, 45.76, 4.86);

        $linkAfter = $this->linkRowOf($clubA->getId(), $ffbb);
        self::assertSame($linkBefore, $linkAfter, 'le choix de B ne touche jamais l\'appariement (tenant) de A');
        unset($seasonA, $seasonB);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (g) la comptabilité du partagé est IDEMPOTENTE et SYMÉTRIQUE (revue sécurité)
    // ─────────────────────────────────────────────────────────────────────────

    public function testTwoManualLinksOfOneClubOnTheSameFederalRefCreditTheSharedCatalogOnce(): void
    {
        $ffbb = 'ARA0069I' . random_int(10, 99);
        [$club] = $this->createClub('idem');
        $manager = $this->manager();

        // Un même club apparie DEUX libellés de fichier différents vers le MÊME gymnase fédéral.
        // Le compteur communautaire compte des CLUBS, pas des libellés : il ne doit monter qu'à 1.
        $this->scopeGucToClub($club->getId());
        $manager->addOrUpdate($club->getId(), $ffbb, 'SALLE UNE', 'GYM CHOISI', self::FED_REF_A, 45.76, 4.86);
        $manager->addOrUpdate($club->getId(), $ffbb, 'SALLE DEUX', 'GYM CHOISI', self::FED_REF_A, 45.76, 4.86);

        self::assertSame(1, $this->sharedCount($ffbb, self::FED_REF_A), 'deux libellés du MÊME club vers le MÊME gymnase = +1, jamais +2');
    }

    public function testAManualRefThatDoesNotResolveFederallyIsPersistedAsNull(): void
    {
        $ffbb = 'ARA0069N' . random_int(10, 99);
        [$club] = $this->createClub('nullref');

        // Une ref qui NE résout PAS fédéralement (radius/annuaire) n'alimente pas le partagé —
        // elle ne doit pas non plus être persistée sur le lien : un ref présent implique toujours
        // un crédit passé, sinon un retrait pourrait décrémenter sans avoir jamais incrémenté.
        $this->scopeGucToClub($club->getId());
        $manager = $this->manager();
        $manager->addOrUpdate($club->getId(), $ffbb, 'SALLE FBI', 'GYM INCONNU', self::UNKNOWN_REF, 45.76, 4.86);

        $count = (int) $this->conn()->fetchOne(
            'SELECT COUNT(*) FROM opponent_venue_link WHERE club_id = :cid AND opponent_organisme_code = :code',
            ['cid' => $club->getId(), 'code' => $ffbb],
        );
        self::assertSame(1, $count, 'le lien tenant est bien créé (la correction locale reste)');
        $ref = $this->conn()->fetchOne(
            'SELECT venue_external_ref FROM opponent_venue_link WHERE club_id = :cid AND opponent_organisme_code = :code',
            ['cid' => $club->getId(), 'code' => $ffbb],
        );
        self::assertNull($ref, 'une ref non résolue fédéralement n\'est jamais persistée (lien par coordonnées seules)');
    }

    /**
     * (h) un adversaire SANS code fédéral (clé sentinelle) n'entre JAMAIS dans le catalogue
     * partagé, même si le corps porte une ref qui résoudrait fédéralement : le contrôleur force
     * l'appariement LOCAL (ref neutralisée avant écriture). Falsifié via l'API réelle.
     */
    public function testASentinelKeyManualChoiceNeverCreditsTheSharedCatalog(): void
    {
        [$club, $season, $user] = $this->createClub('sentinel');
        // Un adversaire AWAY sans code fédéral (amical saisi à la main).
        $label = 'CLUB AMICAL SANS CODE';
        $this->awayFixtureNoCode($season, $label);
        $key = 'X' . substr(hash('sha256', mb_strtolower($label)), 0, 40);

        // Le corps porte une ref FÉDÉRALE (elle résoudrait) — le contrôleur DOIT la neutraliser.
        $this->client->request('POST', '/api/opponents/' . $key . '/venues', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'venueLabel' => 'Gymnase amical',
            'venueExternalRef' => self::FED_REF_A,
            'latitude' => 45.76,
            'longitude' => 4.86,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());

        // Le lien tenant existe sous la clé sentinelle, SANS ref (appariement local seul)…
        $this->scopeGucToClub($club->getId());
        $storedRef = $this->conn()->fetchOne(
            'SELECT venue_external_ref FROM opponent_venue_link WHERE club_id = :cid AND opponent_organisme_code = :key',
            ['cid' => $club->getId(), 'key' => $key],
        );
        self::assertNull($storedRef, 'un adversaire sans code est apparié LOCALEMENT — jamais de ref fédérale persistée');

        // … et RIEN n'entre dans le catalogue partagé pour la clé sentinelle.
        self::assertSame(0, (int) $this->conn()->fetchOne(
            'SELECT COUNT(*) FROM opponent_venue_suggestion WHERE ffbb_organisme_code = :key',
            ['key' => $key],
        ), 'la clé sentinelle n\'entre JAMAIS dans le partagé fédéral');
        unset($season);
    }

    /**
     * (h bis) — MIROIR du cas POST par le PUT de ré-appariement : un lien SOUS clé sentinelle
     * (adversaire sans code) est d'abord posé LOCALEMENT, puis re-pointé (PUT) vers un gymnase
     * dont le corps porte une ref FÉDÉRALE qui résoudrait. La garde vit dans la maison unique
     * ({@see OpponentVenueLinkManager::writeGym}) et couvre AUSSI ce chemin : la ref est
     * neutralisée AVANT toute résolution, RIEN n'entre dans `opponent_venue_suggestion` (table
     * PARTAGÉE, hors-tenant, sans GRANT DELETE — une écriture forgée serait DÉFINITIVE). Sans le
     * correctif du manager, le PUT créditait le partagé sous un code organisme inexistant.
     */
    public function testASentinelKeyRepointNeverCreditsTheSharedCatalog(): void
    {
        [$club, $season, $user] = $this->createClub('sentinelput');
        $label = 'CLUB AMICAL PUT SANS CODE';
        $this->awayFixtureNoCode($season, $label);
        $key = 'X' . substr(hash('sha256', mb_strtolower($label)), 0, 40);

        // 1) Poser le lien LOCAL (aucune ref) sous la clé sentinelle via l'API réelle.
        $this->client->request('POST', '/api/opponents/' . $key . '/venues', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'venueLabel' => 'Gymnase amical local',
            'latitude' => 45.76,
            'longitude' => 4.86,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());
        /** @var array{id: string} $created */
        $created = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $linkId = $created['id'];

        // 2) Ré-apparier (PUT) vers un gymnase dont le corps porte une ref FÉDÉRALE qui RÉSOUT
        //    réellement côté serveur (« 900000001 » est servi par le stub FFBB via la recherche
        //    géo, cf. FfbbHttpClientStub) — c'est le chemin qui n'avait AUCUNE garde avant le
        //    correctif : sans lui, le partagé serait crédité sous la clé sentinelle.
        $this->client->request('PUT', '/api/opponents/venue-links/' . $linkId, [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'venueLabel' => 'Autre gymnase amical',
            'venueExternalRef' => '900000001',
            'latitude' => 45.001,
            'longitude' => 4.001,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());

        // Le lien reste LOCAL (aucune ref persistée) même après le PUT à ref fédérale…
        $this->scopeGucToClub($club->getId());
        $storedRef = $this->conn()->fetchOne(
            'SELECT venue_external_ref FROM opponent_venue_link WHERE club_id = :cid AND opponent_organisme_code = :key',
            ['cid' => $club->getId(), 'key' => $key],
        );
        self::assertNull($storedRef, 'le PUT sous clé sentinelle reste un appariement LOCAL — jamais de ref fédérale persistée');

        // … et RIEN n'entre dans le catalogue partagé pour la clé sentinelle.
        self::assertSame(0, (int) $this->conn()->fetchOne(
            'SELECT COUNT(*) FROM opponent_venue_suggestion WHERE ffbb_organisme_code = :key',
            ['key' => $key],
        ), 'le ré-appariement d\'une clé sentinelle n\'entre JAMAIS dans le partagé fédéral');
        unset($season);
    }

    public function testTheSharedDecrementIsSymmetricPerFederalRef(): void
    {
        $ffbb = 'ARA0069S' . random_int(10, 99);
        [$club] = $this->createClub('sym');
        $manager = $this->manager();

        $this->scopeGucToClub($club->getId());
        $manager->addOrUpdate($club->getId(), $ffbb, 'SALLE UNE', 'GYM CHOISI', self::FED_REF_A, 45.76, 4.86);
        $manager->addOrUpdate($club->getId(), $ffbb, 'SALLE DEUX', 'GYM CHOISI', self::FED_REF_A, 45.76, 4.86);
        self::assertSame(1, $this->sharedCount($ffbb, self::FED_REF_A), 'deux liens du club vers un gymnase = compte 1');

        /** @var list<string> $ids */
        $ids = $this->conn()->fetchFirstColumn(
            'SELECT id FROM opponent_venue_link WHERE club_id = :cid AND opponent_organisme_code = :code ORDER BY fbi_label_norm',
            ['cid' => $club->getId(), 'code' => $ffbb],
        );
        self::assertCount(2, $ids);

        // Retirer UN lien alors qu'un AUTRE du même club pointe encore ce gymnase : pas de décrément.
        $manager->delete($club->getId(), $ids[0]);
        self::assertSame(1, $this->sharedCount($ffbb, self::FED_REF_A), 'retirer un lien non-dernier sur ce gymnase ne décrémente pas le partagé');

        // Retirer le DERNIER lien du club sur ce gymnase : le compte redescend.
        $manager->delete($club->getId(), $ids[1]);
        self::assertSame(0, $this->sharedCount($ffbb, self::FED_REF_A), 'retirer le dernier lien du club sur ce gymnase décrémente');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function awayFixtureNoCode(Season $season, string $opponentLabel): void
    {
        $this->scopeGucToClub($season->getClubId());
        $fixture = new Fixture;
        $fixture->setClubId($season->getClubId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId($this->uuid());
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-04'));
        $fixture->setHomeAway(FixtureHomeAway::AWAY);
        $fixture->setOpponentLabel($opponentLabel);
        $this->em->persist($fixture);
        $this->em->flush();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Infrastructure
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The real OpponentTravelResolver, but with a MOCK FfbbSalleResolver (federal
     * salles for FED_REF_A/B, nothing for an unknown ref) and a stub IGN — so the
     * server-side federal re-resolution is deterministic and off-network.
     */
    private function manualResolver(): OpponentTravelResolver
    {
        $ign = new IgnRoutingClient(
            new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode(['duration' => 900]))),
            new MockClock,
        );

        return new OpponentTravelResolver(
            $ign,
            $this->service(OpponentVenueLinkRepository::class),
            $this->service(OpponentDirectoryEntryRepository::class),
            $this->suggestions(),
            $this->salleResolverMock(),
            $this->service(ClubRepository::class),
            $this->service(FixtureRepository::class),
            $this->service(TravelTimeCache::class),
            new OpponentPairingKey,
            new NullLogger,
        );
    }

    /**
     * Le gestionnaire d'appariement MANUEL réel, sur le résolveur ci-dessus (mock salle
     * fédérale) — le geste qui, comme l'API, crée le lien tenant ET alimente le partagé.
     */
    private function manager(): OpponentVenueLinkManager
    {
        return new OpponentVenueLinkManager(
            $this->em,
            $this->service(OpponentVenueLinkRepository::class),
            $this->manualResolver(),
            $this->service(VenueLabelNormalizer::class),
            new OpponentPairingKey,
        );
    }

    private function salleResolverMock(): FfbbSalleResolver
    {
        $salles = [
            ['numero' => self::FED_REF_A, 'libelle' => self::FED_LABEL_A, 'cartographie' => ['ville' => 'Lyon', 'latitude' => 45.76, 'longitude' => 4.86], 'commune' => ['codePostal' => '69001']],
            ['numero' => self::FED_REF_B, 'libelle' => self::FED_LABEL_B, 'cartographie' => ['ville' => 'Bron', 'latitude' => 45.73, 'longitude' => 4.91], 'commune' => ['codePostal' => '69500']],
        ];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use ($salles): MockResponse {
            $body = \is_string($options['body'] ?? null) ? $options['body'] : '';

            return new MockResponse((string) json_encode(['results' => [['hits' => str_contains($body, 'ffbbserver_salles') ? $salles : []]]]));
        });

        return new FfbbSalleResolver(new FfbbApiClient($mock, 'stub-token'));
    }

    /**
     * @return array<string, mixed>
     */
    private function getSuggestions(User $user, string $code): array
    {
        $this->client->request('GET', '/api/opponents/' . $code . '/venue-suggestions', [], [], $this->authHeaders($user) + ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /* @var array<string, mixed> $payload */
        return $payload;
    }

    private function sharedCount(string $code, string $ref): int
    {
        return (int) $this->conn()->fetchOne(
            'SELECT chosen_by_count FROM opponent_venue_suggestion WHERE ffbb_organisme_code = :code AND venue_external_ref = :ref',
            ['code' => $code, 'ref' => $ref],
        );
    }

    /** A's opponent_venue_link row for the code, as a stable string (read under A's GUC). */
    private function linkRowOf(string $clubId, string $code): string
    {
        $this->scopeGucToClub($clubId);
        $row = $this->conn()->fetchAssociative(
            'SELECT venue_external_ref, venue_label, latitude, longitude, source, fbi_label_norm FROM opponent_venue_link WHERE club_id = :cid AND opponent_organisme_code = :code',
            ['cid' => $clubId, 'code' => $code],
        );
        self::assertIsArray($row);

        return json_encode($row, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function sortedKeys(array $row): array
    {
        $keys = array_map(strval(...), array_keys($row));
        sort($keys);

        return $keys;
    }

    /** @return array{0: Club, 1: Season, 2: User} */
    private function createClub(string $suffix): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club ' . $suffix);
        $club->setSlug('club-suggestion-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        // Un siège géolocalisé pour que le calcul de trajet MANUAL rende une valeur.
        $club->setLatitude(45.75);
        $club->setLongitude(4.85);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('suggestion' . $uid . '@test.com');
        $user->setFirstName('Sug');
        $user->setLastName('Gestion');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season, $user];
    }

    private function awayFixture(Season $season, string $opponentCode, string $opponentLabel): Fixture
    {
        $this->scopeGucToClub($season->getClubId());
        $fixture = new Fixture;
        $fixture->setClubId($season->getClubId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId($this->uuid());
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-04'));
        $fixture->setHomeAway(FixtureHomeAway::AWAY);
        $fixture->setOpponentLabel($opponentLabel);
        $fixture->setOpponentOrganismeCode($opponentCode);
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }

    private function conn(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function suggestions(): OpponentVenueSuggestionRepository
    {
        return $this->service(OpponentVenueSuggestionRepository::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /** @return array{HTTP_AUTHORIZATION: string} */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
