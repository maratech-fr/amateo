<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Clock\DevClockStore;
use App\Controller\VenueExternalLabelController;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureReviewState;
use App\Enum\FixtureStatus;
use App\Enum\FixtureUnplacedReason;
use App\Enum\SeasonStatus;
use App\Exception\ImportRejectedException;
use App\Service\FbiFixtureImporter;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Chantier A PR 2 — « pas de salles appariées, pas d'import » + terrain neutre.
 *
 * LA promesse : un match à DOMICILE importable (division mappée à une équipe) doit
 * porter un gymnase du club AVANT que l'import n'écrive quoi que ce soit — soit par
 * un alias confirmé déjà posé, soit par un appariement fourni au dépôt
 * (`venueMappings` libellé→gymnase, `venueChoices` domicile-sans-salle→gymnase),
 * soit en le déclarant TERRAIN NEUTRE (« pas chez nous »). Tant qu'un domicile
 * importable reste sans gymnase, le dépôt est REFUSÉ (422 nommé) et rien n'est
 * écrit. Un gymnase n'est JAMAIS créé par l'import.
 *
 * Point sensible (matchs / constraint semantics) : les doublons d'angle sont voulus.
 * Jumeau de l'auto-validation {@see ImportLeagueAutoValidationTest} (le cas « un
 * domicile NEUTRE d'un championnat commencé n'est JAMAIS validé » vit là-bas, c'est
 * le NR gatant) ; ICI on prouve la SOURCE de la règle « domicile toujours avec
 * gymnase » et la sémantique du terrain neutre (D-c à D-h de l'amendement).
 *
 * ⚠ CONTRAT que NEO implémente (décidé ici, option A) :
 *  - `FbiFixtureImporter::import(..., array $venueMappings = [], array $venueChoices = [])` :
 *    · `$venueMappings` = list<array{label: string, venueId: string}> — chaque libellé
 *      de salle à domicile du fichier → un gymnase (uuid) OU la sentinelle 'NEUTRAL' ;
 *    · `$venueChoices`  = list<array{division: string, venueId: string}> — chaque
 *      division dont des domiciles n'ont PAS de salle dans le fichier → un gymnase
 *      (uuid) OU 'NEUTRAL'.
 *    Un venueMapping uuid APPARIE (attach alias + backfill, la MAISON de
 *    {@see VenueExternalLabelController::attach}) AVANT la vérification
 *    de couverture et AVANT la passe d'auto-validation. 'NEUTRAL' marque les domiciles
 *    UNPLACED du libellé/division en terrain neutre.
 *  - La vérification de couverture précède TOUTE écriture de rencontre : un domicile
 *    importable sans gymnase → {@see ImportRejectedException::unprocessable} (422),
 *    message nommant les libellés manquants, ZÉRO rencontre créée.
 *  - `FixtureUnplacedReason::NEUTRAL_GROUND` (zéro migration) ; `FixtureResource`
 *    expose un booléen `neutralGround` (dérivé de `unplacedReason`).
 *  - `analyze()` gagne deux clés : `venues` (par libellé à domicile : label, rowCount,
 *    confirmedVenueId, suggestedVenueId, neutral) et `homesWithoutVenue`
 *    (list<array{division, fbiTeamLabel, rowCount}>).
 */
#[Group('integration')]
#[Group('phase1')]
final class ImportVenueRequiredTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    /** Un samedi FUTUR : un championnat non commencé (aucune échéance, aucun match joué). */
    private const string FUTURE_DATE = '2099-03-14';

    /** Un samedi PASSÉ (fuseau club) : « premier match joué » → le championnat a démarré. */
    private const string PAST_DATE = '2020-09-19';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private FbiFixtureImporter $importer;

    // ───────────────────────── Promesse 1 : « pas de salles appariées, pas d'import » ─────────

    /**
     * Le cœur de la promesse : un domicile importable dont la salle n'est appariée à
     * AUCUN gymnase (ni alias confirmé, ni venueMapping) fait REFUSER tout le dépôt —
     * 422 nommant le libellé manquant, et ZÉRO rencontre créée (jamais d'écriture
     * partielle).
     */
    public function testImportRefusesUntilEveryImportableHomeHasAVenue(): void
    {
        [, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);

        try {
            $this->deposit($clubId, $seasonId, [
                ['D SALLES', 'NOMAP-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::FUTURE_DATE), '15:30', 'SALLE JAMAIS VUE'],
            ], $team->getId());
            self::fail('un domicile importable sans gymnase doit faire refuser le dépôt');
        } catch (ImportRejectedException $e) {
            self::assertSame(422, $e->getStatusCode(), 'le refus est un 422 métier');
            self::assertStringContainsStringIgnoringCase('SALLE JAMAIS VUE', $e->getMessage(), 'le refus NOMME le libellé manquant');
        }

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        self::assertSame(
            0,
            $this->em->getRepository(Fixture::class)->count(['externalRef' => 'NOMAP-1']),
            'un dépôt refusé n\'écrit AUCUNE rencontre (atomique, jamais une écriture partielle)',
        );
    }

    /**
     * L'appariement fourni au dépôt (`venueMappings` libellé→gymnase) rattache le
     * domicile (il naît AVEC son gymnase, mais UNPLACED) et fait passer l'import — et
     * l'alias normalisé est posé sur le gymnase (c'est bien la MAISON de l'attach, pas
     * une copie), de sorte qu'un dépôt suivant au même libellé est apparié d'office.
     */
    public function testVenueMappingAttachesTheLabelAndImportsTheHome(): void
    {
        [, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId, 'Gymnase Mappé');

        $result = $this->deposit($clubId, $seasonId, [
            ['D MAP', 'MAP-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::FUTURE_DATE), '15:30', 'SALLE DU FICHIER'],
        ], $team->getId(), venueMappings: [['label' => 'SALLE DU FICHIER', 'venueId' => $venue->getId()]]);
        self::assertSame(1, $result['created'] ?? null, 'le domicile apparié est importé');

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'MAP-1']);
        self::assertInstanceOf(Fixture::class, $fixture);
        self::assertSame($venue->getId(), $fixture->getVenueId(), 'le domicile naît avec le gymnase apparié');
        self::assertSame(FixtureStatus::UNPLACED, $fixture->getStatus(), 'apparier ne place jamais la rencontre');

        $fresh = $this->em->find(Venue::class, $venue->getId());
        self::assertInstanceOf(Venue::class, $fresh);
        self::assertContains('salle du fichier', $fresh->getExternalLabels(), 'l\'appariement pose l\'alias normalisé (MAISON de l\'attach)');
    }

    /**
     * Un gymnase apparié DANS le dépôt compte pour l'auto-validation « validé ligue »
     * du MÊME dépôt (PR 1) : l'appariement est appliqué AVANT la passe d'auto-validation,
     * donc un domicile de championnat COMMENCÉ, daté, avec sa salle juste appariée,
     * bascule VALIDATED tout seul.
     */
    public function testAMatchedVenueCountsForLeagueAutoValidationOfTheSameDeposit(): void
    {
        [, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId, 'Gymnase Commencé');

        $result = $this->deposit($clubId, $seasonId, [
            ['D COMMENCÉ', 'VAL-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::PAST_DATE), '15:30', 'SALLE COMMENCÉE'],
        ], $team->getId(), venueMappings: [['label' => 'SALLE COMMENCÉE', 'venueId' => $venue->getId()]]);
        self::assertSame(1, $result['leagueValidated'] ?? null, 'la salle appariée au dépôt rend le domicile commencé validable — il bascule tout seul');

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'VAL-1']);
        self::assertInstanceOf(Fixture::class, $fixture);
        self::assertSame(FixtureStatus::VALIDATED, $fixture->getStatus());
    }

    /** L'import n'invente JAMAIS un gymnase : l'appariement rattache un gymnase EXISTANT, le compte ne bouge pas. */
    public function testNoVenueIsEverCreatedByTheImport(): void
    {
        [, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId, 'Gymnase Unique');
        $before = $this->em->getRepository(Venue::class)->count(['seasonId' => $seasonId]);

        $this->deposit($clubId, $seasonId, [
            ['D NOCREATE', 'NC-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::FUTURE_DATE), '15:30', 'SALLE FICHIER NC'],
        ], $team->getId(), venueMappings: [['label' => 'SALLE FICHIER NC', 'venueId' => $venue->getId()]]);

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        self::assertSame($before, $this->em->getRepository(Venue::class)->count(['seasonId' => $seasonId]), 'l\'import n\'a créé aucun gymnase');
    }

    /**
     * D-a — une division NON appariée (aucun mapping équipe) est ignorée : ses domiciles
     * ne sont « ni créés ni erreurs », donc ils n'exigent AUCUN gymnase et ne font pas
     * refuser le dépôt. La règle ne mord que sur les domiciles IMPORTABLES.
     */
    public function testAnUnmatchedDivisionRequiresNothing(): void
    {
        [, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId, 'Gymnase Importable');

        // Une division mappée (salle appariée) + une division NON mappée (salle inconnue) :
        // le dépôt passe, la division non mappée est juste reportée « non associée ».
        $result = $this->deposit($clubId, $seasonId, [
            ['D MAPPÉE', 'DA-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::FUTURE_DATE), '15:30', 'SALLE IMPORTABLE'],
            ['D ORPHELINE', 'DA-2', $needle . ' - 2', 'AS Lointains', $this->fbiDate(self::FUTURE_DATE), '16:00', 'SALLE ORPHELINE'],
        ], $team->getId(), venueMappings: [['label' => 'SALLE IMPORTABLE', 'venueId' => $venue->getId()]], onlyMapDivision: 'D MAPPÉE');

        self::assertSame(1, $result['created'] ?? null, 'seule la division appariée crée sa rencontre');
        $unmapped = array_column($result['unmappedDivisions'] ?? [], 'name');
        self::assertContains('D ORPHELINE', $unmapped, 'la division orpheline est reportée non associée, jamais une erreur de gymnase');

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(Fixture::class)->count(['externalRef' => 'DA-2']), 'une division orpheline ne crée rien');
    }

    /**
     * D-b — un re-dépôt divergent tranché « prendre le fichier » sur la salle résout
     * vers le gymnase APPARIÉ (le venueMapping du nouveau libellé), JAMAIS vers venueId
     * null : dans le monde « domicile toujours avec gymnase », take_file ne peut plus
     * laisser une rencontre sans salle.
     */
    public function testReDepositTakeFileResolvesToTheMatchedVenueNeverNull(): void
    {
        [, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue1 = $this->createVenue($clubId, $seasonId, 'Gymnase Origine');
        $venue2 = $this->createVenue($clubId, $seasonId, 'Gymnase Repris');

        // Premier dépôt : domicile apparié au gymnase d'origine.
        $this->deposit($clubId, $seasonId, [
            ['D REDEP', 'RD-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::FUTURE_DATE), '15:30', 'SALLE ORIGINE RD'],
        ], $team->getId(), venueMappings: [['label' => 'SALLE ORIGINE RD', 'venueId' => $venue1->getId()]]);

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RD-1']);
        self::assertInstanceOf(Fixture::class, $fixture);
        $fixtureId = $fixture->getId();

        // Re-dépôt : la ligue nomme une AUTRE salle (appariée au 2ᵉ gymnase), et je
        // tranche « prendre le fichier » sur l'écart salle.
        $this->deposit($clubId, $seasonId, [
            ['D REDEP', 'RD-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::FUTURE_DATE), '15:30', 'SALLE REPRISE RD'],
        ], $team->getId(), venueMappings: [['label' => 'SALLE REPRISE RD', 'venueId' => $venue2->getId()]], decisions: [['fixtureId' => $fixtureId, 'field' => 'venue', 'choice' => 'take_file']]);

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $after = $this->em->find(Fixture::class, $fixtureId);
        self::assertInstanceOf(Fixture::class, $after);
        self::assertSame($venue2->getId(), $after->getVenueId(), 'take_file résout vers le gymnase apparié, jamais venueId null');
    }

    // ───────────────────────── Promesse 2 : terrain neutre (D-c à D-h) ───────────────────────

    /**
     * D-c — un domicile déclaré « terrain neutre » reste HOME, naît UNPLACED, sans
     * gymnase, marqué TRAITÉ (REVIEWED) et porte la raison NEUTRAL_GROUND.
     */
    public function testNeutralGroundHomeStaysHomeUnplacedAndReviewed(): void
    {
        [, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);

        $result = $this->deposit($clubId, $seasonId, [
            ['D NEUTRE', 'NEU-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::FUTURE_DATE), '15:30', 'GYMNASE ADVERSE'],
        ], $team->getId(), venueMappings: [['label' => 'GYMNASE ADVERSE', 'venueId' => 'NEUTRAL']]);
        self::assertSame(1, $result['created'] ?? null, 'un terrain neutre s\'importe sans gymnase, sans refus');

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'NEU-1']);
        self::assertInstanceOf(Fixture::class, $fixture);
        self::assertSame(FixtureHomeAway::HOME, $fixture->getHomeAway(), 'un terrain neutre reste un domicile');
        self::assertSame(FixtureStatus::UNPLACED, $fixture->getStatus());
        self::assertNull($fixture->getVenueId(), 'un terrain neutre n\'a pas de gymnase du club');
        self::assertSame(FixtureUnplacedReason::NEUTRAL_GROUND, $fixture->getUnplacedReason());
        self::assertSame(FixtureReviewState::REVIEWED, $fixture->getReviewState(), 'un terrain neutre naît traité (rien à placer)');
    }

    /**
     * D-f — seules les rencontres UNPLACED d'un libellé deviennent neutres : un domicile
     * du même libellé DÉJÀ placé (venue + heure) garde sa salle et son statut.
     */
    public function testOnlyUnplacedFixturesOfALabelBecomeNeutral(): void
    {
        [, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId, 'Gymnase Placé');

        // Un domicile DÉJÀ placé à ce libellé (apparié puis forcé PLACED), et un nouveau
        // domicile UNPLACED au même libellé que l'on déclare neutre dans le même dépôt.
        $placed = $this->seedPlacedHome($clubId, $seasonId, $team->getId(), self::FUTURE_DATE, '15:30', $venue->getId(), 'GYMNASE ADVERSE F');
        $placedId = $placed->getId();

        $this->deposit($clubId, $seasonId, [
            ['D NEUTRE F', 'NEUF-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate('2099-03-21'), '16:00', 'GYMNASE ADVERSE F'],
        ], $team->getId(), venueMappings: [['label' => 'GYMNASE ADVERSE F', 'venueId' => 'NEUTRAL']]);

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $stillPlaced = $this->em->find(Fixture::class, $placedId);
        self::assertInstanceOf(Fixture::class, $stillPlaced);
        self::assertSame(FixtureStatus::PLACED, $stillPlaced->getStatus(), 'un domicile déjà placé ne devient jamais neutre');
        self::assertSame($venue->getId(), $stillPlaced->getVenueId(), 'le placé garde son gymnase');
        self::assertNull($stillPlaced->getUnplacedReason());

        $neutral = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'NEUF-1']);
        self::assertInstanceOf(Fixture::class, $neutral);
        self::assertSame(FixtureUnplacedReason::NEUTRAL_GROUND, $neutral->getUnplacedReason(), 'seul le nouvel UNPLACED devient neutre');
    }

    /**
     * D-d — mémoire DÉRIVÉE : un libellé déjà porté par une rencontre NEUTRE de la
     * saison est « pré-coché » neutre et ne bloque plus. Un nouveau dépôt au même
     * libellé passe SANS venueMapping et le nouveau domicile naît neutre à son tour.
     */
    public function testAnAlreadyNeutralLabelIsRememberedAndDoesNotBlock(): void
    {
        [, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);

        // Premier dépôt : on déclare le libellé neutre.
        $this->deposit($clubId, $seasonId, [
            ['D MEMO', 'MEMO-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::FUTURE_DATE), '15:30', 'TERRAIN NEUTRE MEMO'],
        ], $team->getId(), venueMappings: [['label' => 'TERRAIN NEUTRE MEMO', 'venueId' => 'NEUTRAL']]);

        // Second dépôt au même libellé SANS aucun venueMapping : la mémoire dérivée le
        // couvre → pas de 422, et le nouveau domicile naît neutre.
        $result = $this->deposit($clubId, $seasonId, [
            ['D MEMO', 'MEMO-2', $needle . ' - 1', 'AS Autre', $this->fbiDate('2099-03-21'), '16:00', 'TERRAIN NEUTRE MEMO'],
        ], $team->getId());
        self::assertSame(1, $result['created'] ?? null, 'un libellé déjà neutre ne fait pas refuser le second dépôt');

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'MEMO-2']);
        self::assertInstanceOf(Fixture::class, $fixture);
        self::assertSame(FixtureUnplacedReason::NEUTRAL_GROUND, $fixture->getUnplacedReason(), 'la mémoire dérivée re-neutralise le nouveau domicile');
    }

    /**
     * D-h — un libellé qui est un alias CONFIRMÉ d'un gymnase ne peut pas être déclaré
     * neutre : la source dit « c'est chez nous », le gestionnaire ne peut pas prétendre
     * le contraire — 422 nommé.
     */
    public function testAConfirmedAliasLabelCannotBeMarkedNeutral(): void
    {
        [, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        // Le libellé est un alias confirmé d'un gymnase du club.
        $this->createVenueWithAlias($clubId, $seasonId, 'Gymnase Confirmé', 'salle confirmee');

        try {
            $this->deposit($clubId, $seasonId, [
                ['D NEUTRE H', 'NEUH-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::FUTURE_DATE), '15:30', 'SALLE CONFIRMEE'],
            ], $team->getId(), venueMappings: [['label' => 'SALLE CONFIRMEE', 'venueId' => 'NEUTRAL']]);
            self::fail('un alias confirmé ne peut pas être déclaré terrain neutre');
        } catch (ImportRejectedException $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(Fixture::class)->count(['externalRef' => 'NEUH-1']), 'le dépôt est refusé : rien n\'est écrit');
    }

    /**
     * D-e — porte de sortie : apparier le libellé (rattacher l'alias au gymnase)
     * RE-RATTACHE la rencontre neutre et EFFACE la raison NEUTRAL_GROUND — le backfill
     * d'attach repointe les domiciles sans salle, et {@see Fixture::setVenueId} éteint
     * la raison.
     */
    public function testAttachingTheLabelClearsTheNeutralReason(): void
    {
        [$token, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);

        $this->deposit($clubId, $seasonId, [
            ['D SORTIE', 'OUT-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::FUTURE_DATE), '15:30', 'ANCIEN NEUTRE'],
        ], $team->getId(), venueMappings: [['label' => 'ANCIEN NEUTRE', 'venueId' => 'NEUTRAL']]);

        $venue = $this->createVenue($clubId, $seasonId, 'Gymnase Récupéré');
        $this->client->request('POST', \sprintf('/api/venues/%s/external-labels', $venue->getId()), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['label' => 'ANCIEN NEUTRE'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'OUT-1']);
        self::assertInstanceOf(Fixture::class, $fixture);
        self::assertSame($venue->getId(), $fixture->getVenueId(), 'apparier le libellé re-rattache la rencontre');
        self::assertNull($fixture->getUnplacedReason(), 'poser un gymnase efface la raison « terrain neutre »');
    }

    /**
     * D-g — le neutre n'est JAMAIS confié au solveur comme un domicile à placer : dans
     * la charge utile `/place-matches` sa ligne porte `kind: AWAY` (elle ne sert qu'à
     * porter SA date), jamais TO_PLACE ni FIXED. Le contrat engine reste inchangé (1.5).
     */
    public function testANeutralHomeIsEmittedAsAwayInThePlacementPayload(): void
    {
        [, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);

        $this->deposit($clubId, $seasonId, [
            ['D PAYLOAD', 'PAY-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::FUTURE_DATE), '15:30', 'TERRAIN NEUTRE PAY'],
        ], $team->getId(), venueMappings: [['label' => 'TERRAIN NEUTRE PAY', 'venueId' => 'NEUTRAL']]);

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'PAY-1']);
        self::assertInstanceOf(Fixture::class, $fixture);

        $club = $this->em->find(Club::class, $clubId);
        self::assertInstanceOf(Club::class, $club);
        $builder = self::getContainer()->get(MatchPlacementPayloadBuilder::class);
        self::assertInstanceOf(MatchPlacementPayloadBuilder::class, $builder);
        $built = $builder->build($club, $seasonId)['payload'];
        self::assertSame('1.8', $built['version'] ?? null, 'le contrat engine (1.8 depuis la réservation souveraine)');

        $row = null;
        foreach ($built['matches'] as $candidate) {
            if (($candidate['id'] ?? null) === $fixture->getId()) {
                $row = $candidate;
                break;
            }
        }
        self::assertIsArray($row, 'le terrain neutre figure dans la charge utile');
        self::assertSame('AWAY', $row['kind'] ?? null, 'un terrain neutre voyage comme un extérieur (sa date), jamais à placer');
    }

    /** `FixtureResource` expose le flag : un domicile neutre a `neutralGround === true`. */
    public function testFixtureResourceExposesTheNeutralFlag(): void
    {
        [$token, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);

        $this->deposit($clubId, $seasonId, [
            ['D FLAG', 'FLAG-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::FUTURE_DATE), '15:30', 'TERRAIN NEUTRE FLAG'],
        ], $team->getId(), venueMappings: [['label' => 'TERRAIN NEUTRE FLAG', 'venueId' => 'NEUTRAL']]);

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'FLAG-1']);
        self::assertInstanceOf(Fixture::class, $fixture);

        $this->client->request('GET', \sprintf('/api/fixtures/%s', $fixture->getId()), [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($json);
        self::assertTrue($json['neutralGround'] ?? null, 'la ressource expose le flag terrain neutre');
    }

    // ───────────────────────── analyze() : la section « venues » ─────────────────────────────

    /**
     * `analyze()` agrège les salles à domicile : par libellé, le nombre de matchs, le
     * gymnase confirmé (alias) et une suggestion ; et liste à part les domiciles sans
     * salle dans le fichier.
     */
    public function testAnalyzeAggregatesHomeVenuesAndListsHomesWithoutVenue(): void
    {
        [, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $venue = $this->createVenueWithAlias($clubId, $seasonId, 'Gymnase Analyse', 'salle analysee');
        $club = $this->em->find(Club::class, $clubId);
        self::assertInstanceOf(Club::class, $club);

        $analysis = $this->importer->analyze($this->xlsx([
            ['D AN', 'AN-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::FUTURE_DATE), '15:30', 'SALLE ANALYSEE'],
            ['D AN', 'AN-2', $needle . ' - 1', 'AS Autre', $this->fbiDate('2099-03-21'), '16:00', 'SALLE ANALYSEE'],
            // Un domicile SANS salle dans le fichier.
            ['D AN', 'AN-3', $needle . ' - 1', 'AS Tierce', $this->fbiDate('2099-03-28'), '17:00', ''],
            // Un EXTÉRIEUR : jamais dans la section « salles à domicile ».
            ['D AN', 'AN-4', 'AS Dehors', $needle . ' - 1', $this->fbiDate('2099-04-04'), '18:00', 'SALLE ADVERSE'],
        ]), $club);

        self::assertArrayHasKey('venues', $analysis, 'analyze expose la section venues');
        $byLabel = [];
        foreach ($analysis['venues'] as $venueRow) {
            $byLabel[$venueRow['label']] = $venueRow;
        }
        self::assertArrayHasKey('SALLE ANALYSEE', $byLabel, 'le libellé à domicile est agrégé');
        self::assertSame(2, $byLabel['SALLE ANALYSEE']['rowCount'], 'deux matchs à ce libellé');
        self::assertSame($venue->getId(), $byLabel['SALLE ANALYSEE']['confirmedVenueId'], 'le gymnase confirmé par alias est rendu');
        self::assertArrayNotHasKey('SALLE ADVERSE', $byLabel, 'une salle d\'extérieur n\'est jamais une salle à domicile');

        self::assertArrayHasKey('homesWithoutVenue', $analysis, 'analyze liste les domiciles sans salle');
        self::assertNotSame([], $analysis['homesWithoutVenue'], 'le domicile sans salle du fichier est listé');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $importer = self::getContainer()->get(FbiFixtureImporter::class);
        self::assertInstanceOf(FbiFixtureImporter::class, $importer);
        $this->importer = $importer;

        // Horloge figée AVANT l'octobre des scénarios : un domicile de 2020 est « premier
        // match joué » (championnat commencé), un de 2099 ne l'est pas.
        self::getContainer()->get(DevClockStore::class)->set(new DateTimeImmutable('2026-09-16 10:00:00'));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(DevClockStore::class)->set(null);
        parent::tearDown();
    }

    /**
     * Dépose un fichier FBI PAR LE SERVICE (le foyer où vit l'appariement de salles).
     *
     * @param list<list<string>>                                            $rows          lignes de données (sans en-tête)
     * @param list<array{label: string, venueId: string}>                   $venueMappings libellé → gymnase (uuid) ou 'NEUTRAL'
     * @param list<array{division: string, venueId: string}>                $venueChoices  division sans salle → gymnase ou 'NEUTRAL'
     * @param list<array{fixtureId: string, field: string, choice: string}> $decisions
     *
     * @return array<string, mixed>
     */
    private function deposit(string $clubId, string $seasonId, array $rows, string $teamId, array $venueMappings = [], array $venueChoices = [], array $decisions = [], ?string $onlyMapDivision = null): array
    {
        $this->scopeGucToClub($clubId);
        $club = $this->em->find(Club::class, $clubId);
        self::assertInstanceOf(Club::class, $club);
        $divisions = array_values(array_unique(array_column($rows, 0)));
        if (null !== $onlyMapDivision) {
            $divisions = array_values(array_filter($divisions, static fn (string $d): bool => $d === $onlyMapDivision));
        }
        $mappings = array_map(
            static fn (string $division): array => ['division' => $division, 'fbiTeamLabel' => null, 'teamId' => $teamId, 'competitionId' => null],
            $divisions,
        );

        return $this->importer->import($this->xlsx($rows), $club, $mappings, $decisions, $seasonId, $venueMappings, $venueChoices);
    }

    /** Un domicile PLACÉ (venue + heure) créé directement, pour le témoin D-f. */
    private function seedPlacedHome(string $clubId, string $seasonId, string $teamId, string $date, string $kickoff, string $venueId, string $fbiLabel): Fixture
    {
        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($seasonId);
        $fixture->setTeamId($teamId);
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv Placé');
        $fixture->setExternalRef('PLACED-' . uniqid('', true));
        $fixture->setFbiVenueLabel($fbiLabel);
        $fixture->setVenueId($venueId);
        $fixture->setKickoffTime(new DateTimeImmutable($kickoff));
        $fixture->setStatus(FixtureStatus::PLACED, new DateTimeImmutable('2026-09-16 10:00:00'));
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }

    private function createVenue(string $clubId, string $seasonId, string $name): Venue
    {
        $venue = new Venue;
        $venue->setClubId($clubId);
        $venue->setSeasonId($seasonId);
        $venue->setName($name);
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function createVenueWithAlias(string $clubId, string $seasonId, string $name, string $normalizedAlias): Venue
    {
        $venue = $this->createVenue($clubId, $seasonId, $name);
        $venue->setExternalLabels([$normalizedAlias]);
        $this->em->flush();

        return $venue;
    }

    private function createTeam(string $clubId, string $seasonId): Team
    {
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
        $team->setSeasonId($seasonId);
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('SF3');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string} [token, clubId, seasonId, needle]
     */
    private function createClub(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $clubName = 'BC Salle ' . $uid;
        $club = new Club;
        $club->setName($clubName);
        $club->setSlug('bc-salle-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setIsDemo(true);
        $club->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('salle' . $uid . '@test.com');
        $user->setFirstName('Salle');
        $user->setLastName('User');
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

        $season = new Season;
        $season->setClubId($club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $this->settleSeasonPlan($season);

        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return [$token, $club->getId(), $season->getId(), strtoupper($clubName)];
    }

    private function fbiDate(string $iso): string
    {
        return date('d/m/Y', (int) strtotime($iso));
    }

    /** @param list<list<string>> $rows en-tête au format réel (« N° de match » avec son espace) */
    private function xlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(
            [['Division', 'N° de match ', 'Equipe 1', 'Equipe 2', 'Date de rencontre', 'Heure', 'Salle'], ...$rows],
            null,
            'A1',
        );
        $path = tempnam(sys_get_temp_dir(), 'fbi') . '.xlsx';
        new Xlsx($spreadsheet)->save($path);

        return $path;
    }
}
