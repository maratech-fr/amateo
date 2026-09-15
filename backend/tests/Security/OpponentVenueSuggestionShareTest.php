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
use App\Repository\OpponentTravelRepository;
use App\Repository\OpponentVenueSuggestionRepository;
use App\Service\Basketball\FfbbApiClient;
use App\Service\Basketball\FfbbSalleResolver;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\OpponentTravelResolver;
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
        $this->manualResolver()->applyManualOverride($club->getId(), $season->getId(), $ffbb, null, self::FED_REF_A, 'LIBELLE FORGE PAR LE CLUB', 45.76, 4.86);

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
        $this->manualResolver()->applyManualOverride($club->getId(), $season->getId(), $ffbb, null, self::UNKNOWN_REF, 'GYMNASE INVENTE', 45.76, 4.86);

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
        $resolver = $this->manualResolver();

        $this->scopeGucToClub($clubA->getId());
        $resolver->applyManualOverride($clubA->getId(), $seasonA->getId(), $ffbb, null, self::FED_REF_A, 'CORPS DE A', 45.76, 4.86);
        $this->scopeGucToClub($clubB->getId());
        $resolver->applyManualOverride($clubB->getId(), $seasonB->getId(), $ffbb, null, self::FED_REF_A, 'CORPS DE B DIFFERENT', 45.10, 4.10);

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
        $resolver = $this->manualResolver();

        $this->scopeGucToClub($clubA->getId());
        $resolver->applyManualOverride($clubA->getId(), $seasonA->getId(), $ffbb, null, self::FED_REF_A, 'GYMNASE DE A', 45.76, 4.86);
        $travelBefore = $this->travelRowOf($clubA->getId(), $seasonA->getId(), $ffbb);

        $this->scopeGucToClub($clubB->getId());
        $resolver->applyManualOverride($clubB->getId(), $seasonB->getId(), $ffbb, null, self::FED_REF_B, 'GYMNASE DE B', 45.76, 4.86);

        $travelAfter = $this->travelRowOf($clubA->getId(), $seasonA->getId(), $ffbb);
        self::assertSame($travelBefore, $travelAfter, 'le choix de B ne touche jamais le trajet (tenant) de A');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
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
            $this->em,
            $ign,
            $this->service(OpponentTravelRepository::class),
            $this->service(OpponentDirectoryEntryRepository::class),
            $this->suggestions(),
            $this->salleResolverMock(),
            $this->service(ClubRepository::class),
            $this->service(FixtureRepository::class),
            new NullLogger,
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

    /** A's opponent_travel row for the code, as a stable string (read under A's GUC). */
    private function travelRowOf(string $clubId, string $seasonId, string $code): string
    {
        $this->scopeGucToClub($clubId);
        $row = $this->conn()->fetchAssociative(
            'SELECT override_venue_external_ref, override_venue_label, override_latitude, override_longitude, travel_minutes, source, opponent_team_key FROM opponent_travel WHERE season_id = :sid AND opponent_organisme_code = :code',
            ['sid' => $seasonId, 'code' => $code],
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
