<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\VenueExternalLabelController;
use App\Entity\Club;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\SeasonStatus;
use App\Exception\ImportRejectedException;
use App\Service\Fbi\FbiDeviationService;
use App\Service\FbiFixtureImporter;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\Integration\Service\ImportVenueRequiredTest;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * SEC — chemins d'ABUS de l'appariement de salles au dépôt FBI (chantier A PR 2),
 * sur le patron {@see ImportFixturesAuthorizationTest}. Chaque cas prouve que le
 * canal multipart `venueMappings` / `venueChoices` ne peut JAMAIS écrire ce qu'il
 * ne doit pas : un gymnase d'un autre club, un corps forgé, un neutre interdit — et
 * que RIEN n'est écrit quand c'est refusé (jamais une écriture partielle).
 *
 * CONTRAT (décidé en option A, cf. {@see ImportVenueRequiredTest}) :
 * POST /api/fixtures/import accepte deux champs multipart JSON :
 *  - `venueMappings` : list<{label, venueId}> — venueId = uuid d'un gymnase du club OU 'NEUTRAL' ;
 *  - `venueChoices`  : list<{division, venueId}> — venueId = uuid OU 'NEUTRAL'.
 * L'appariement réutilise la MAISON de l'attach ({@see VenueExternalLabelController})
 * : libellé > 120 car → 422 · > 30 alias → 422 · libellé déjà porté par un AUTRE gymnase → 422 ·
 * gymnase étranger invisible (filtre tenant) → 400.
 */
#[Group('phase1')]
#[Group('integration')]
final class ImportVenueMappingAbuseTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;
    use VerifiesRegistration;

    private const string DIVISION = 'DABUSE';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $tempFiles = [];

    /** A1 — un `venueMappings` vers le gymnase d'un AUTRE club est refusé, et rien n'est écrit chez personne. */
    public function testVenueMappingToAForeignVenueIsRefusedAndWritesNothing(): void
    {
        [$tokenA, , $clubA, $needleA] = $this->register('A1');
        $teamA = $this->createTeam($clubA);
        [, , $clubB] = $this->register('B1');
        $venueB = $this->createVenue($clubB, 'Gymnase Étranger');

        $status = $this->import($tokenA, [$this->homeRow('AB1-1', $needleA, 'SALLE A1')], [
            'mappings' => $this->mapping($teamA->getId()),
            'venueMappings' => json_encode([['label' => 'SALLE A1', 'venueId' => $venueB->getId()]], \JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(400, $status, 'un gymnase étranger est invisible → 400, jamais une écriture cross-tenant');
        $this->assertNoFixture('AB1-1');
        // La requête d'import (tokenA) a laissé le GUC sur le club A ; pour relire le gymnase
        // du club B (vérifier qu'il n'a reçu aucun alias) on se rescope sur son tenant.
        $this->scopeGucToClub($clubB);
        $this->assertVenueLabels($venueB->getId(), [], 'le gymnase étranger n\'a reçu aucun alias');
    }

    /** A2 — un libellé de plus de 120 caractères est refusé (corps forgé, jamais stocké). */
    public function testAnOverLongLabelIsRefused(): void
    {
        [$tokenA, , $clubA, $needleA] = $this->register('A2');
        $teamA = $this->createTeam($clubA);
        $venue = $this->createVenue($clubA, 'Gymnase A2');
        $longLabel = str_repeat('X', 121);

        $status = $this->import($tokenA, [$this->homeRow('AB2-1', $needleA, $longLabel)], [
            'mappings' => $this->mapping($teamA->getId()),
            'venueMappings' => json_encode([['label' => $longLabel, 'venueId' => $venue->getId()]], \JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(422, $status, 'un libellé > 120 caractères est refusé');
        $this->assertNoFixture('AB2-1');
    }

    /** A3 — au-delà de 30 alias sur un gymnase, un 31ᵉ est refusé (le json ne grossit pas sans fin). */
    public function testMoreThanThirtyLabelsIsRefused(): void
    {
        [$tokenA, , $clubA, $needleA] = $this->register('A3');
        $teamA = $this->createTeam($clubA);
        $venue = $this->createVenue($clubA, 'Gymnase A3');
        $this->scopeGucToClub($clubA);
        $venue->setExternalLabels(array_map(static fn (int $i): string => 'alias ' . $i, range(1, 30)));
        $this->em->flush();

        $status = $this->import($tokenA, [$this->homeRow('AB3-1', $needleA, 'SALLE A3 EN TROP')], [
            'mappings' => $this->mapping($teamA->getId()),
            'venueMappings' => json_encode([['label' => 'SALLE A3 EN TROP', 'venueId' => $venue->getId()]], \JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(422, $status, 'un 31ᵉ alias est refusé');
        $this->assertNoFixture('AB3-1');
        $this->assertVenueLabelCount($venue->getId(), 30, 'le gymnase garde ses 30 alias, pas un de plus');
    }

    /** A4 — un libellé déjà porté par un AUTRE gymnase du club ne peut pas être ré-attribué à l'aveugle (422). */
    public function testALabelAlreadyCarriedByAnotherVenueIsRefused(): void
    {
        [$tokenA, , $clubA, $needleA] = $this->register('A4');
        $teamA = $this->createTeam($clubA);
        $this->createVenue($clubA, 'Gymnase A4 Porteur', ['salle partagee']);
        $other = $this->createVenue($clubA, 'Gymnase A4 Autre');

        $status = $this->import($tokenA, [$this->homeRow('AB4-1', $needleA, 'SALLE PARTAGEE')], [
            'mappings' => $this->mapping($teamA->getId()),
            'venueMappings' => json_encode([['label' => 'SALLE PARTAGEE', 'venueId' => $other->getId()]], \JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(422, $status, 'un libellé déjà porté par un autre gymnase est refusé');
        $this->assertNoFixture('AB4-1');
        $this->assertVenueLabels($other->getId(), [], 'le second gymnase n\'a pas volé l\'alias');
    }

    /** A5 — un `venueChoices` (domicile sans salle) vers un gymnase étranger est refusé, rien n'est écrit. */
    public function testVenueChoiceToAForeignVenueIsRefused(): void
    {
        [$tokenA, , $clubA, $needleA] = $this->register('A5');
        $teamA = $this->createTeam($clubA);
        [, , $clubB] = $this->register('B5');
        $venueB = $this->createVenue($clubB, 'Gymnase Étranger A5');

        // Un domicile SANS salle dans le fichier (colonne Salle vide) → venueChoices par division.
        $status = $this->import($tokenA, [$this->homeRow('AB5-1', $needleA, '')], [
            'mappings' => $this->mapping($teamA->getId()),
            'venueChoices' => json_encode([['division' => self::DIVISION, 'venueId' => $venueB->getId()]], \JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(400, $status, 'un venueChoices vers un gymnase étranger est refusé');
        $this->assertNoFixture('AB5-1');
    }

    /** A6 — un marquage neutre n'écrit JAMAIS chez un autre club (l'import reste scopé au club appelant). */
    public function testNeutralMarkingNeverWritesIntoAnotherClub(): void
    {
        [$tokenA, , $clubA, $needleA] = $this->register('A6');
        $teamA = $this->createTeam($clubA);
        [, , $clubB] = $this->register('B6');

        $status = $this->import($tokenA, [$this->homeRow('AB6-1', $needleA, 'GYMNASE ADVERSE A6')], [
            'mappings' => $this->mapping($teamA->getId()),
            'venueMappings' => json_encode([['label' => 'GYMNASE ADVERSE A6', 'venueId' => 'NEUTRAL']], \JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(200, $status, 'un terrain neutre du club appelant s\'importe');
        $this->scopeGucToClub($clubB);
        self::assertSame(0, $this->em->getRepository(Fixture::class)->count([]), 'aucune rencontre n\'a été écrite dans l\'autre club');
    }

    /** A7 — déclarer neutre un libellé qui est un alias CONFIRMÉ d'un gymnase est refusé (D-h), rien n'est écrit. */
    public function testNeutralOnAConfirmedAliasIsRefused(): void
    {
        [$tokenA, , $clubA, $needleA] = $this->register('A7');
        $teamA = $this->createTeam($clubA);
        $this->createVenue($clubA, 'Gymnase A7 Confirmé', ['salle confirmee a7']);

        $status = $this->import($tokenA, [$this->homeRow('AB7-1', $needleA, 'SALLE CONFIRMEE A7')], [
            'mappings' => $this->mapping($teamA->getId()),
            'venueMappings' => json_encode([['label' => 'SALLE CONFIRMEE A7', 'venueId' => 'NEUTRAL']], \JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(422, $status, 'un alias confirmé ne peut pas être déclaré terrain neutre');
        $this->assertNoFixture('AB7-1');
    }

    /** A8 — un choix neutre FORGÉ (division absente du fichier) ne couvre rien : le domicile réel reste sans gymnase → 422, rien n'est écrit. */
    public function testAForgedNeutralChoiceCoversNothing(): void
    {
        [$tokenA, , $clubA, $needleA] = $this->register('A8');
        $teamA = $this->createTeam($clubA);

        // Un domicile importable sans salle, et un venueChoices neutre pour une AUTRE
        // division (absente du fichier) : le choix forgé ne couvre pas le vrai domicile.
        $status = $this->import($tokenA, [$this->homeRow('AB8-1', $needleA, '')], [
            'mappings' => $this->mapping($teamA->getId()),
            'venueChoices' => json_encode([['division' => 'DIVISION FANTOME', 'venueId' => 'NEUTRAL']], \JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(422, $status, 'un choix neutre forgé ne couvre aucun domicile réel');
        $this->assertNoFixture('AB8-1');
    }

    /** A9 — déclarer un libellé neutre ne touche JAMAIS une rencontre de ce libellé DÉJÀ placée. */
    public function testNeutralMarkingLeavesPlacedFixturesOfTheLabelIntact(): void
    {
        [$tokenA, , $clubA, $needleA] = $this->register('A9');
        $teamA = $this->createTeam($clubA);
        $venue = $this->createVenue($clubA, 'Gymnase A9 Placé');
        $placed = $this->seedPlacedHome($clubA, $teamA, $venue->getId(), 'GYMNASE ADVERSE A9');
        $placedId = $placed->getId();

        $status = $this->import($tokenA, [$this->homeRow('AB9-1', $needleA, 'GYMNASE ADVERSE A9')], [
            'mappings' => $this->mapping($teamA->getId()),
            'venueMappings' => json_encode([['label' => 'GYMNASE ADVERSE A9', 'venueId' => 'NEUTRAL']], \JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(200, $status);
        $this->scopeGucToClub($clubA);
        $this->em->clear();
        $still = $this->em->find(Fixture::class, $placedId);
        self::assertInstanceOf(Fixture::class, $still);
        self::assertSame(FixtureStatus::PLACED, $still->getStatus(), 'un domicile déjà placé reste placé');
        self::assertSame($venue->getId(), $still->getVenueId(), 'un domicile déjà placé garde son gymnase');
        self::assertNull($still->getUnplacedReason(), 'un placé ne devient jamais neutre');
    }

    /**
     * A10 — un `venueMappings` vers un gymnase du MÊME club mais d'une AUTRE saison (archivée)
     * est refusé comme un inconnu/étranger (400), sans écrire d'alias : le canal d'import ne
     * doit pas contourner la garde d'écriture de saison (SEC-13) qui protège l'endpoint des
     * libellés. Le club du gymnase ne suffit pas — sa SAISON doit être la saison d'écriture.
     */
    public function testVenueMappingToAVenueOfAnotherSeasonIsRefused(): void
    {
        [, , $clubA, $needleA] = $this->register('A10');
        $teamA = $this->createTeam($clubA);
        $archived = $this->archivedSeasonVenue($clubA, 'Gymnase Saison Archivée');

        $this->assertArchivedSeasonVenueRefused(
            $clubA,
            $teamA,
            $archived,
            [$this->homeRow('AB10-1', $needleA, 'SALLE A10')],
            [['label' => 'SALLE A10', 'venueId' => $archived]],
            [],
            'AB10-1',
        );
    }

    /**
     * A10 (venueChoices) — même refus pour un domicile SANS salle apparié à un gymnase d'une
     * autre saison : ni rencontre, ni alias dans la saison archivée. Même foyer, même
     * falsification du season_filter que ci-dessus.
     */
    public function testVenueChoiceToAVenueOfAnotherSeasonIsRefused(): void
    {
        [, , $clubA, $needleA] = $this->register('A10b');
        $teamA = $this->createTeam($clubA);
        $archived = $this->archivedSeasonVenue($clubA, 'Gymnase Saison Archivée B');

        $this->assertArchivedSeasonVenueRefused(
            $clubA,
            $teamA,
            $archived,
            [$this->homeRow('AB10b-1', $needleA, '')],
            [],
            [['division' => self::DIVISION, 'venueId' => $archived]],
            'AB10b-1',
        );
    }

    /**
     * A11 (service) — l'appariement INLINE de l'arbitrage hors dépôt ({@see FbiDeviationService})
     * ne doit pas s'appuyer sur les SEULS filtres : comme {@see ownedVenueForImport} (A10), il
     * vérifie club ET saison. On COUPE tenant_filter + season_filter (défense en profondeur
     * intestable en boîte noire : au dépôt HTTP le gymnase hors saison est déjà masqué) et on
     * appelle le service : un gymnase d'une AUTRE saison doit être refusé, ZÉRO alias écrit.
     * ÉCHOUE tant que le service ne vérifie que les filtres ; VERT une fois le contrôle ajouté.
     */
    public function testArbitrationInlineVenueChoiceToAVenueOfAnotherSeasonIsRefusedAtTheService(): void
    {
        [, , $clubA] = $this->register('A11');
        $team = $this->createTeam($clubA);
        $archivedVenueId = $this->archivedSeasonVenue($clubA, 'Gymnase Saison Archivée Arbitrage');

        $this->assertServiceVenueChoiceRefused($clubA, $team, $archivedVenueId);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // PAS de disableReboot (contrairement aux tests mono-session) : on crée DEUX
        // clubs par scénario, et garder le noyau vivant fait fuiter la 1ʳᵉ session dans
        // l'inscription de la 2ᵉ. Le noyau redémarre à chaque requête ; les écritures EM
        // survivent via la transaction DAMA partagée (patron ImportFixturesAuthorizationTest).
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    // Note : le gymnase d'un AUTRE CLUB n'est pas falsifiable proprement à ce niveau — la RLS
    // PostgreSQL (GUC du club appelant), indépendante des filtres Doctrine, masque déjà la ligne
    // du club étranger à la LECTURE (le `find` de résolution ET celui de la relecture de preuve).
    // Le cross-club est donc garanti par la couche RLS, pas par ce contrôle de défense en
    // profondeur ; seul le cross-SAISON (même club, RLS permissive) l'éprouve au service.

    /**
     * Falsification du service : filtres coupés, un écart de salle d'un domicile NON PLACÉ du
     * club A arbitré « prendre le fichier » vers $venueId (hors saison) doit lever
     * {@see ImportRejectedException} AVANT toute écriture — aucun alias posé sur le gymnase visé,
     * le gymnase courant de la rencontre intact.
     */
    private function assertServiceVenueChoiceRefused(string $clubA, Team $team, string $venueId): void
    {
        $this->scopeGucToClub($clubA);
        $currentGym = $this->createVenue($clubA, 'Gymnase Courant Arbitrage');
        $fixture = new Fixture;
        $fixture->setClubId($clubA);
        $fixture->setSeasonId($team->getSeasonId());
        $fixture->setTeamId($team->getId());
        $fixture->setMatchDate(new DateTimeImmutable('2099-03-14'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv Arbitrage');
        $fixture->setExternalRef('A11-DEV');
        $fixture->setVenueId($currentGym->getId());
        $fixture->setFbiVenueLabel('SALLE SOURCE INCONNUE');
        $fixture->setStatus(FixtureStatus::UNPLACED, new DateTimeImmutable);
        $this->em->persist($fixture);
        $this->em->flush();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getFilters()->disable('tenant_filter');
        $em->getFilters()->disable('season_filter');
        $service = self::getContainer()->get(FbiDeviationService::class);
        self::assertInstanceOf(FbiDeviationService::class, $service);

        $row = [
            'numero' => 'A11-DEV',
            'matchDate' => new DateTimeImmutable('2099-03-14'),
            'homeAway' => FixtureHomeAway::HOME,
            'opponentLabel' => 'Adv Arbitrage',
            'kickoffTime' => null,
            'venueLabel' => 'SALLE SOURCE INCONNUE',
        ];

        try {
            $service->applyFieldTakeFile($fixture, 'venue', $row, new DateTimeImmutable, $venueId);
        } catch (ImportRejectedException) {
            $target = $em->find(Venue::class, $venueId);
            self::assertSame([], $target?->getExternalLabels() ?? ['x'], 'aucun alias écrit sur le gymnase hors club/saison');
            self::assertSame($currentGym->getId(), $fixture->getVenueId(), 'le gymnase courant de la rencontre est intact');

            return;
        }
        self::fail('un gymnase hors du club/saison de la rencontre doit être refusé à l\'arbitrage (club+saison vérifiés au service, pas seulement les filtres)');
    }

    /**
     * FALSIFICATION du season_filter (backend.md) pour atteindre le FOYER
     * {@see FbiFixtureImporter::import} → `ownedVenueForImport` : au dépôt HTTP réel, le
     * season_filter masque déjà un gymnase hors saison courante (`find()` → null → 400
     * « introuvable »), court-circuitant le défaut qu'on garde — défense en profondeur
     * INTESTABLE en boîte noire. On COUPE le garde amont (appel SERVICE direct, season_filter
     * désactivé) : le gymnase archivé devient VISIBLE, et SEUL un contrôle de SAISON dans
     * `ownedVenueForImport` doit alors refuser l'appariement. ÉCHOUE tant que l'import accepte
     * le gymnase hors saison (il pose l'alias dans la saison archivée) ; VERT une fois le
     * contrôle ajouté, avec ZÉRO écriture (ni alias, ni rencontre) dans la saison archivée.
     *
     * @param list<list<string>>                             $rows
     * @param list<array{label: string, venueId: string}>    $venueMappings
     * @param list<array{division: string, venueId: string}> $venueChoices
     */
    private function assertArchivedSeasonVenueRefused(string $clubId, Team $team, string $archivedVenueId, array $rows, array $venueMappings, array $venueChoices, string $ref): void
    {
        $this->scopeGucToClub($clubId);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        // Le garde amont coupé : le gymnase archivé n'est plus masqué par la saison courante.
        $em->getFilters()->disable('season_filter');
        $club = $em->find(Club::class, $clubId);
        self::assertInstanceOf(Club::class, $club);
        $importer = self::getContainer()->get(FbiFixtureImporter::class);
        self::assertInstanceOf(FbiFixtureImporter::class, $importer);

        $before = $em->find(Venue::class, $archivedVenueId)?->getExternalLabels() ?? [];
        $mappings = [['division' => self::DIVISION, 'fbiTeamLabel' => null, 'teamId' => $team->getId()]];

        try {
            $importer->import($this->xlsx($rows), $club, $mappings, [], $team->getSeasonId(), $venueMappings, $venueChoices);
        } catch (ImportRejectedException) {
            // Refus attendu une fois le contrôle de saison ajouté : AUCUNE écriture archivée.
            $em->clear();
            self::assertSame($before, $em->find(Venue::class, $archivedVenueId)?->getExternalLabels() ?? [], 'aucun alias écrit dans la saison archivée (SEC-13 non contournée)');
            self::assertSame(0, $em->getRepository(Fixture::class)->count(['externalRef' => $ref]), 'aucune rencontre créée dans la saison archivée');

            return;
        }
        self::fail('un gymnase d\'une autre saison doit être refusé à l\'appariement (garde de saison SEC-13 non contournée au dépôt)');
    }

    /**
     * POST /api/fixtures/import en multipart (file + parts) ; rend le code HTTP.
     *
     * @param list<list<string>>    $rows  lignes de données (sans en-tête)
     * @param array<string, string> $parts champs multipart additionnels (mappings, venueMappings, venueChoices)
     */
    private function import(string $token, array $rows, array $parts): int
    {
        $file = $this->xlsx($rows);
        $this->client->request('POST', '/api/fixtures/import', $parts, [
            'file' => new UploadedFile($file, 'fbi.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        return $this->client->getResponse()->getStatusCode();
    }

    /** @return string le JSON `mappings` reliant DIVISION → l'équipe donnée */
    private function mapping(string $teamId): string
    {
        return json_encode([['division' => self::DIVISION, 'teamId' => $teamId]], \JSON_THROW_ON_ERROR);
    }

    /** @return list<string> une ligne HOME (le club en Equipe 1) au libellé de salle donné */
    private function homeRow(string $ref, string $needle, string $salle): array
    {
        return [self::DIVISION, $ref, $needle . ' - 1', 'AS Voisins', '14/03/2099', '15:30', $salle];
    }

    private function assertNoFixture(string $ref): void
    {
        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(Fixture::class)->count(['externalRef' => $ref]), \sprintf('aucune rencontre « %s » ne doit être écrite', $ref));
    }

    /** @param list<string> $expected */
    private function assertVenueLabels(string $venueId, array $expected, string $message): void
    {
        $this->em->clear();
        $venue = $this->em->find(Venue::class, $venueId);
        self::assertInstanceOf(Venue::class, $venue);
        self::assertSame($expected, $venue->getExternalLabels(), $message);
    }

    private function assertVenueLabelCount(string $venueId, int $expected, string $message): void
    {
        $this->em->clear();
        $venue = $this->em->find(Venue::class, $venueId);
        self::assertInstanceOf(Venue::class, $venue);
        self::assertCount($expected, $venue->getExternalLabels(), $message);
    }

    /** @param list<string> $aliases */
    private function createVenue(string $clubId, string $name, array $aliases = []): Venue
    {
        $this->scopeGucToClub($clubId);
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        self::assertInstanceOf(Season::class, $season);
        $venue = new Venue;
        $venue->setClubId($clubId);
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setSource('manual');
        if ([] !== $aliases) {
            $venue->setExternalLabels($aliases);
        }
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    /**
     * Crée une saison ARCHIVÉE (passée) du club + un gymnase vivant dans CETTE saison, et rend
     * son id. La saison COURANTE reste celle semée par l'inscription (où l'import écrit).
     */
    private function archivedSeasonVenue(string $clubId, string $name): string
    {
        $this->scopeGucToClub($clubId);
        $currentYear = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $past = new Season;
        $past->setClubId($clubId);
        $past->setName((string) ($currentYear - 1));
        $past->setStartDate(new DateTimeImmutable(($currentYear - 1) . '-08-01'));
        $past->setEndDate(new DateTimeImmutable($currentYear . '-07-15'));
        $past->setStatus(SeasonStatus::ARCHIVED);
        $past->setTransitionData([]);
        $this->em->persist($past);

        $venue = new Venue;
        $venue->setClubId($clubId);
        $venue->setSeasonId($past->getId());
        $venue->setName($name);
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue->getId();
    }

    private function seedPlacedHome(string $clubId, Team $team, string $venueId, string $fbiLabel): Fixture
    {
        $this->scopeGucToClub($clubId);
        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($team->getSeasonId());
        $fixture->setTeamId($team->getId());
        $fixture->setMatchDate(new DateTimeImmutable('2099-03-14'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv Placé');
        $fixture->setExternalRef('A9-PLACED');
        $fixture->setFbiVenueLabel($fbiLabel);
        $fixture->setVenueId($venueId);
        $fixture->setKickoffTime(new DateTimeImmutable('15:30'));
        $fixture->setStatus(FixtureStatus::PLACED, new DateTimeImmutable('now'));
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }

    private function createTeam(string $clubId): Team
    {
        $this->scopeGucToClub($clubId);
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        self::assertInstanceOf(Season::class, $season);
        if (null === $this->chosenPlanVersion($season)) {
            $this->settleSeasonPlan($season);
        }

        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (null === $sport) {
            $uid = uniqid('', true);
            $sport = new Sport;
            $sport->setName('Basket ' . $uid);
            $sport->setSlug('basket-' . $uid);
            $sport->setIsActive(true);
            $this->em->persist($sport);
        }
        $category = new SportCategory;
        $category->setClubId($clubId);
        $category->setSportId($sport->getId());
        $category->setName('U13-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($clubId);
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('U13-1');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    /** @param list<list<string>> $rows */
    private function xlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(
            [['Division', 'N° de match ', 'Equipe 1', 'Equipe 2', 'Date de rencontre', 'Heure', 'Salle'], ...$rows],
            null,
            'A1',
        );
        $path = tempnam(sys_get_temp_dir(), 'fbiabuse') . '.xlsx';
        new Xlsx($spreadsheet)->save($path);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string} [token, userId, clubId, needle (nom du club)]
     */
    private function register(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $clubName = 'Club ' . $ara;
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'F', 'lastName' => 'Import', 'ara' => strtoupper($suffix), 'club_name' => $clubName, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $me['club']['id'], $clubName];
    }
}
