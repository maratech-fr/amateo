<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Fixture;
use App\Entity\Reservation;
use App\Entity\Season;
use App\Entity\SubscriptionPlan;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\Gender;
use App\Enum\SeasonStatus;
use App\Enum\TeamLevel;
use App\Service\ParcImport\ParcImportAnalyzer;
use App\Service\ParcImport\ParcImportGate;
use App\Service\PlanEntitlements;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\CrossStack\ParcImportReservationSemanticsGateTest;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Chantier G PR2 — l'IMPORT RÉEL transactionnel `POST /api/clubs/{id}/parc-import` : le gestionnaire
 * a analysé son parc (PR1, {@see ParcImportAnalyzeTest}), ajusté l'aperçu (cocher/décocher, ré-apparier,
 * corriger quelques champs) et clique « Importer » ; le serveur bascule les cases cochées en écriture
 * TOUT-OU-RIEN. L'aperçu (dry-run) est le métier de {@see ParcImportAnalyzer} ;
 * ICI c'est l'écriture.
 *
 * Contrat de l'endpoint (DÉFINI ici, implémenté par NEO) :
 *  - Opération Post custom sur ClubResource (patron `parc-import/analyze`),
 *    `App\Controller\ParcImportExecuteController`, `read:false`, name `parc_import`. MÊME gate
 *    ({@see ParcImportGate}) et MÊMES bornes que l'analyse (256 Ko, ≤60 équipes, ≤20 gymnases,
 *    ≤500 créneaux+réservations) — l'import de parc est un geste d'ONBOARDING, refusé 409 tant que
 *    le socle est EN VIGUEUR, 403 au non-gestionnaire, 404 au non-membre.
 *  - Le corps est un objet JSON : `{payload, decisions}`.
 *      · `payload` : l'objet parc-v1, les MÊMES octets que l'analyse (re-validé en forme + bornes).
 *      · `decisions` : la liste des choix de l'aperçu, `[{section, index, coche, cibleId?, corrections?}]`.
 *        — Seules les lignes dont une décision porte `coche:true` sont écrites ; une ligne sans décision,
 *          ou `coche:false`, est IGNORÉE (cochage).
 *        — `cibleId` (option) apparie la ligne à une entité EXISTANTE (confirmation d'un suggéré /
 *          ré-appariement manuel). RE-VÉRIFIÉ tenant côté serveur : un id d'un autre club/saison →
 *          422, RIEN écrit (patron A10).
 *        — `corrections` (option) remplace des champs SIMPLES avant écriture — équipes : `seances` ;
 *          créneaux/réservations : `jour`, `debut`, `duree` (décision fondateur Q2 = b). Sur une équipe
 *          APPARIÉE, `corrections.seances` présent = la mise à jour des séances est ACCEPTÉE ; absent =
 *          séances inchangées (Q3 = a, décochable).
 *  - UNE SEULE transaction, dans l'ordre : gymnases → équipes → créneaux → blocs → coachs +
 *    `TeamCoach` MAIN → réservations (unitaires et de groupe). Un gymnase naît SANS adresse ; son
 *    `canSplit` est DÉRIVÉ (vrai si un créneau du parc y demande ≥ 2 équipes en parallèle). Une ligne
 *    cochée invalide AU MOMENT DE L'ÉCRITURE (chevauchement, Σ séances d'un bloc, capacité, cap payant)
 *    → 422 NOMMANT la ligne, RIEN n'est écrit (les gardes des processors rejouées, jamais contournées).
 *  - Sur une équipe APPARIÉE, seul le nombre de séances/semaine peut bouger : genre, catégorie et
 *    niveau ne bougent JAMAIS — et sur une équipe ENGAGÉE le niveau reste intact (axe périmètre engagé).
 *  - Idempotence : rejouer le même import ne crée AUCUN doublon.
 *  - Noms hostiles (`=HYPERLINK(...)`, tête `=`/`+`/`-`/`@`) NEUTRALISÉS à l'import — le nom stocké ne
 *    commence jamais par un déclencheur de formule ; l'export XLSX (`SpreadsheetGenerator`,
 *    StringValueBinder ~l.122) rend alors du TEXTE, pas une formule vivante.
 *  - Réponse 200 : `{recap:{<section>:{crees,apparies,misAJour,ignores}}}`. Réponse 422 : `{errors:[…]}`.
 *
 * ⚠ Limiteur Redis keyé PAR UTILISATEUR (patron analyse) : chaque test crée sa propre identité — aucun
 * FLUSHALL, aucune fuite entre suites (mémoire backend-test-gotchas). APP_ENV=test.
 *
 * La SÉMANTIQUE de bout en bout (parc-v1 → import → vrai builder → vrai moteur, une réservation
 * importée verrouille la séance HARD) est le NR BLOQUANT
 * {@see ParcImportReservationSemanticsGateTest} ; ici on prouve le CÂBLAGE, la
 * transaction tout-ou-rien, le contrat de décisions, le périmètre engagé, l'idempotence et les abus.
 */
#[Group('integration')]
final class ParcImportExecuteTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private User $user;

    private Season $season;

    private string $token;

    // ───────────────────────────── Gate (réutilisée, domaine sensible) ─────────────────────────────

    public function testNonManagerIsRefusedWithoutWriting(): void
    {
        $this->scopeGucToClub($this->club->getId());
        $before = $this->rowCount('team');

        [$status] = $this->import($this->newTeamParc(), [['section' => 'equipes', 'index' => 0, 'coche' => true]], token: $this->viewerToken());

        self::assertSame(403, $status, 'un membre non gestionnaire ne peut pas importer');
        self::assertSame($before, $this->rowCount('team'), 'un refus 403 n\'écrit rien');
    }

    public function testImportRefusedWhenSeasonPlanChosen(): void
    {
        // Planning lifecycle (ADR-0002) : un plan de saison POINTE une version = socle EN VIGUEUR.
        // L'import de parc est un geste d'ONBOARDING, refusé 409 tant que le socle n'est pas rouvert.
        $this->settleSeasonPlan($this->season);
        $this->scopeGucToClub($this->club->getId());
        $before = $this->rowCount('team');

        [$status] = $this->import($this->newTeamParc(), [['section' => 'equipes', 'index' => 0, 'coche' => true]]);

        self::assertSame(409, $status, 'socle en vigueur → 409');
        self::assertSame($before, $this->rowCount('team'), 'un refus 409 n\'écrit rien');
    }

    // ───────────────────────────── Contrat de décisions (promesse 1) ─────────────────────────────

    public function testCheckedLinesAreImportedInOneTransaction(): void
    {
        // Un parc entièrement « à créer » — gymnase (divisible, créneau à 2 en parallèle), équipe,
        // créneau, réservation — toutes cases cochées. Tout est saisi d'un coup.
        $this->scopeGucToClub($this->club->getId());
        $venuesBefore = $this->rowCount('venue');
        $teamsBefore = $this->rowCount('team');
        $slotsBefore = $this->rowCount('venue_training_slot');
        $reservationsBefore = $this->rowCount('reservation');

        $parc = [
            'format' => 'parc-v1',
            'gymnases' => [['nom' => 'Palais Neuf']],
            'equipes' => [['nom' => 'SM1', 'genre' => 'M', 'seances' => 2]],
            'creneaux' => [['gymnase' => 'Palais Neuf', 'jour' => 2, 'debut' => '18:00', 'duree' => 90, 'equipes_en_parallele' => 2]],
            'mutualisations' => [],
            'reservations' => [['equipe' => 'SM1', 'gymnase' => 'Palais Neuf', 'jour' => 2, 'debut' => '18:00']],
        ];
        $decisions = [
            ['section' => 'gymnases', 'index' => 0, 'coche' => true],
            ['section' => 'equipes', 'index' => 0, 'coche' => true],
            ['section' => 'creneaux', 'index' => 0, 'coche' => true],
            ['section' => 'reservations', 'index' => 0, 'coche' => true],
        ];

        [$status] = $this->import($parc, $decisions);
        self::assertSame(200, $status);

        self::assertSame($venuesBefore + 1, $this->rowCount('venue'), 'le gymnase est créé');
        self::assertSame($teamsBefore + 1, $this->rowCount('team'), 'l\'équipe est créée');
        self::assertSame($slotsBefore + 1, $this->rowCount('venue_training_slot'), 'le créneau est créé');
        self::assertSame($reservationsBefore + 1, $this->rowCount('reservation'), 'la réservation est créée');

        // Gymnase créé SANS adresse ; canSplit DÉRIVÉ du créneau à 2 en parallèle.
        $venue = $this->em->getRepository(Venue::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'Palais Neuf']);
        self::assertInstanceOf(Venue::class, $venue);
        self::assertTrue($venue->getCanSplit(), 'un créneau à ≥ 2 en parallèle rend le gymnase créé divisible');
        self::assertTrue(null === $venue->getAddress() || '' === $venue->getAddress(), 'le gymnase importé naît sans adresse');

        $slot = $this->em->getRepository(VenueTrainingSlot::class)->findOneBy(['clubId' => $this->club->getId(), 'venueId' => $venue->getId()]);
        self::assertInstanceOf(VenueTrainingSlot::class, $slot);
        self::assertSame(2, $slot->getCapacity(), 'la capacité du créneau suit les équipes en parallèle');
    }

    public function testUncheckedLinesAreNotWritten(): void
    {
        // Cochage : deux équipes « à créer », une seule cochée. L'autre n'est PAS écrite.
        $this->scopeGucToClub($this->club->getId());
        $teamsBefore = $this->rowCount('team');

        $parc = $this->emptyParc();
        $parc['equipes'] = [['nom' => 'SM1', 'genre' => 'M'], ['nom' => 'SF1', 'genre' => 'F']];
        $decisions = [
            ['section' => 'equipes', 'index' => 0, 'coche' => true],
            ['section' => 'equipes', 'index' => 1, 'coche' => false],
        ];

        [$status] = $this->import($parc, $decisions);
        self::assertSame(200, $status);

        self::assertSame($teamsBefore + 1, $this->rowCount('team'), 'seule la ligne cochée est écrite');
        self::assertNotNull($this->em->getRepository(Team::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'SM1']));
        self::assertNull($this->em->getRepository(Team::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'SF1']), 'la ligne décochée n\'est jamais créée');
    }

    public function testReAppairingByCibleIdTargetsTheChosenExistingEntity(): void
    {
        // Ré-appariement : la ligne « Palais » ressemble à deux gymnases ; le gestionnaire a tranché
        // dans l'aperçu en posant `cibleId` sur le bon. L'import N'EN CRÉE PAS un nouveau, il attache
        // la réservation au gymnase choisi.
        $chosen = $this->makeVenue('Palais des Sports Nord');
        $this->makeVenue('Palais des Sports Sud');
        $team = $this->makeTeam('SM1', sessionsPerWeek: 2);
        $this->makeSlot($chosen->getId(), 3, '18:00');
        $this->scopeGucToClub($this->club->getId());
        $venuesBefore = $this->rowCount('venue');

        $parc = $this->emptyParc();
        $parc['reservations'] = [['equipe' => 'SM1', 'gymnase' => 'Palais', 'jour' => 3, 'debut' => '18:00']];
        $decisions = [['section' => 'reservations', 'index' => 0, 'coche' => true, 'cibleId' => $chosen->getId()]];

        [$status] = $this->import($parc, $decisions);
        self::assertSame(200, $status);

        self::assertSame($venuesBefore, $this->rowCount('venue'), 'un ré-appariement ne crée aucun gymnase');
        $reservation = $this->em->getRepository(Reservation::class)->findOneBy(['clubId' => $this->club->getId(), 'teamId' => $team->getId()]);
        self::assertInstanceOf(Reservation::class, $reservation);
        self::assertSame($chosen->getId(), $reservation->getVenueId(), 'la réservation vise le gymnase ré-apparié, jamais un nouveau');
    }

    public function testSimpleFieldCorrectionsAreAppliedBeforeWrite(): void
    {
        // Correction sur place (Q2 = b) : le gestionnaire a corrigé jour/heure/durée du créneau dans
        // l'aperçu. L'import écrit les valeurs CORRIGÉES, pas celles du texte brut.
        $venue = $this->makeVenue('Palais Neuf');
        $this->scopeGucToClub($this->club->getId());

        $parc = $this->emptyParc();
        $parc['creneaux'] = [['gymnase' => 'Palais Neuf', 'jour' => 1, 'debut' => '17:00', 'duree' => 60, 'equipes_en_parallele' => 1]];
        $decisions = [[
            'section' => 'creneaux', 'index' => 0, 'coche' => true,
            'cibleId' => $venue->getId(),
            'corrections' => ['jour' => 4, 'debut' => '19:30', 'duree' => 90],
        ]];

        [$status] = $this->import($parc, $decisions);
        self::assertSame(200, $status);

        $slot = $this->em->getRepository(VenueTrainingSlot::class)->findOneBy(['clubId' => $this->club->getId(), 'venueId' => $venue->getId()]);
        self::assertInstanceOf(VenueTrainingSlot::class, $slot);
        self::assertSame(4, $slot->getDayOfWeek(), 'le jour corrigé est écrit');
        self::assertSame('19:30', $slot->getStartTime()->format('H:i'), 'l\'heure corrigée est écrite');
        self::assertSame(90, $slot->getDurationMinutes(), 'la durée corrigée est écrite');
    }

    // ───────────────────────── Équipe appariée : séances SEULES (promesse 3) ─────────────────────────

    public function testMatchedEngagedTeamSessionsUpdateTouchesSessionsOnlyAndKeepsItsLevel(): void
    {
        // Une équipe ENGAGÉE (elle porte au moins un match) appariée : la mise à jour du nombre de
        // séances (acceptée via corrections.seances) est appliquée, mais genre, catégorie et NIVEAU
        // ne bougent JAMAIS — le niveau d'une équipe engagée est intouchable (axe périmètre engagé).
        $team = $this->makeTeam('SM1', sessionsPerWeek: 2, gender: Gender::M, level: TeamLevel::REGIONAL);
        $categoryBefore = $team->getSportCategoryId();
        $this->engage($team);

        $parc = $this->emptyParc();
        // Le texte déclare un autre genre et plus de séances — seules les séances ont le droit de bouger.
        $parc['equipes'] = [['nom' => 'SM1', 'genre' => 'F', 'seances' => 5]];
        $decisions = [['section' => 'equipes', 'index' => 0, 'coche' => true, 'corrections' => ['seances' => 5]]];

        [$status] = $this->import($parc, $decisions);
        self::assertSame(200, $status);

        $fresh = $this->em->getRepository(Team::class)->find($team->getId());
        self::assertInstanceOf(Team::class, $fresh);
        self::assertSame(5, $fresh->getSessionsPerWeek(), 'la mise à jour des séances est appliquée');
        self::assertSame(Gender::M, $fresh->getGender(), 'le genre d\'une équipe appariée ne bouge jamais');
        self::assertSame($categoryBefore, $fresh->getSportCategoryId(), 'la catégorie ne bouge jamais');
        self::assertSame(TeamLevel::REGIONAL, $fresh->getLevel(), 'le niveau d\'une équipe engagée reste intact');
    }

    public function testSessionsUpdateDeclinedLeavesTheMatchedTeamUntouched(): void
    {
        // Q3 décochable : la ligne appariée est cochée mais le gestionnaire REFUSE la mise à jour des
        // séances (aucune `corrections.seances`). Les séances restent ce qu'elles étaient.
        $team = $this->makeTeam('SM1', sessionsPerWeek: 2);

        $parc = $this->emptyParc();
        $parc['equipes'] = [['nom' => 'SM1', 'genre' => 'M', 'seances' => 4]];
        $decisions = [['section' => 'equipes', 'index' => 0, 'coche' => true]];

        [$status] = $this->import($parc, $decisions);
        self::assertSame(200, $status);

        $fresh = $this->em->getRepository(Team::class)->find($team->getId());
        self::assertInstanceOf(Team::class, $fresh);
        self::assertSame(2, $fresh->getSessionsPerWeek(), 'sans acceptation, les séances ne bougent pas');
    }

    // ───────────────────────── Tout-ou-rien (promesse 2) ─────────────────────────

    public function testAnInvalidCheckedLineRollsBackEverythingAndNamesTheLine(): void
    {
        // Deux créneaux cochés du MÊME gymnase le MÊME jour se chevauchent (18:00-19:30 et 19:00-20:30).
        // La garde de chevauchement (VenueTrainingSlotStateProcessor) MORD à l'écriture : RIEN n'est
        // écrit (tout-ou-rien), et le 422 NOMME la ligne fautive. Une équipe cochée dans le même import
        // ne survit pas non plus.
        $venue = $this->makeVenue('Palais Neuf');
        $this->scopeGucToClub($this->club->getId());
        $slotsBefore = $this->rowCount('venue_training_slot');
        $teamsBefore = $this->rowCount('team');

        $parc = $this->emptyParc();
        $parc['equipes'] = [['nom' => 'SM1', 'genre' => 'M']];
        $parc['creneaux'] = [
            ['gymnase' => 'Palais Neuf', 'jour' => 2, 'debut' => '18:00', 'duree' => 90, 'equipes_en_parallele' => 1],
            ['gymnase' => 'Palais Neuf', 'jour' => 2, 'debut' => '19:00', 'duree' => 90, 'equipes_en_parallele' => 1],
        ];
        $decisions = [
            ['section' => 'equipes', 'index' => 0, 'coche' => true],
            ['section' => 'creneaux', 'index' => 0, 'coche' => true, 'cibleId' => $venue->getId()],
            ['section' => 'creneaux', 'index' => 1, 'coche' => true, 'cibleId' => $venue->getId()],
        ];

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(422, $status, 'une ligne cochée qui viole une garde refuse l\'import');
        self::assertNotEmpty($body['errors'] ?? [], 'le 422 nomme la ligne fautive');
        self::assertStringContainsString('créneau', mb_strtolower(implode(' ', $body['errors'])), 'l\'erreur nomme la ligne (sa section)');

        self::assertSame($slotsBefore, $this->rowCount('venue_training_slot'), 'tout-ou-rien : aucun créneau écrit');
        self::assertSame($teamsBefore, $this->rowCount('team'), 'tout-ou-rien : l\'équipe cochée du même import n\'est pas écrite non plus');
    }

    public function testTeamCapIsEnforcedAllOrNothing(): void
    {
        // Cap payant (PlanEntitlements) : au-delà des places restantes de l'offre, l'import est REFUSÉ
        // avant tout persist — tout-ou-rien, jamais « les premières passent ».
        $remaining = $this->capClubAtEssentiel();
        self::assertIsInt($remaining, 'l\'offre Essentiel doit porter un cap fini');
        $this->scopeGucToClub($this->club->getId());
        $teamsBefore = $this->rowCount('team');

        $parc = $this->emptyParc();
        $parc['equipes'] = array_map(static fn (int $i): array => ['nom' => 'Neuve ' . $i, 'genre' => 'M'], range(1, $remaining + 1));
        $decisions = array_map(static fn (int $i): array => ['section' => 'equipes', 'index' => $i, 'coche' => true], range(0, $remaining));

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(422, $status, 'dépasser le cap refuse l\'import');
        self::assertNotEmpty($body['errors'] ?? []);
        self::assertSame($teamsBefore, $this->rowCount('team'), 'cap dépassé : aucune équipe écrite (pas même les premières)');
    }

    public function testCibleIdFromAnotherClubIsRefusedWithoutWriting(): void
    {
        // Patron A10 — un `cibleId` d'un AUTRE club (ou d'une autre saison) ne doit JAMAIS être écrit :
        // re-vérification tenant côté serveur, 422, RIEN écrit.
        $foreign = $this->seedForeignClubWithVenue('Palais Étranger');
        $team = $this->makeTeam('SM1', sessionsPerWeek: 2);
        $this->scopeGucToClub($this->club->getId());
        $reservationsBefore = $this->rowCount('reservation');

        $parc = $this->emptyParc();
        $parc['reservations'] = [['equipe' => 'SM1', 'gymnase' => 'Palais', 'jour' => 2, 'debut' => '18:00']];
        $decisions = [['section' => 'reservations', 'index' => 0, 'coche' => true, 'cibleId' => $foreign['venueId']]];

        [$status] = $this->import($parc, $decisions);
        self::assertSame(422, $status, 'un cibleId hors tenant est refusé');
        self::assertSame($reservationsBefore, $this->rowCount('reservation'), 'rien n\'est écrit avec une cible hors tenant');
        self::assertNull($this->em->getRepository(Reservation::class)->findOneBy(['clubId' => $this->club->getId(), 'teamId' => $team->getId()]));

        unset($team);
    }

    // ───────────────────────── Idempotence (promesse 4) ─────────────────────────

    public function testReplayingTheSameImportCreatesNoDuplicate(): void
    {
        // Rejouer le MÊME import (mêmes décisions) ne crée AUCUN doublon : le second passage n'ajoute
        // ni gymnase, ni équipe, ni réservation déjà présents.
        $this->scopeGucToClub($this->club->getId());

        $parc = [
            'format' => 'parc-v1',
            'gymnases' => [['nom' => 'Palais Neuf']],
            'equipes' => [['nom' => 'SM1', 'genre' => 'M', 'seances' => 2]],
            'creneaux' => [['gymnase' => 'Palais Neuf', 'jour' => 2, 'debut' => '18:00', 'duree' => 90, 'equipes_en_parallele' => 1]],
            'mutualisations' => [],
            'reservations' => [['equipe' => 'SM1', 'gymnase' => 'Palais Neuf', 'jour' => 2, 'debut' => '18:00']],
        ];
        $decisions = [
            ['section' => 'gymnases', 'index' => 0, 'coche' => true],
            ['section' => 'equipes', 'index' => 0, 'coche' => true],
            ['section' => 'creneaux', 'index' => 0, 'coche' => true],
            ['section' => 'reservations', 'index' => 0, 'coche' => true],
        ];

        [$first] = $this->import($parc, $decisions);
        self::assertSame(200, $first);

        $this->scopeGucToClub($this->club->getId());
        $venues = $this->rowCount('venue');
        $teams = $this->rowCount('team');
        $reservations = $this->rowCount('reservation');

        // Second passage : le même parc, ré-apparié aux entités que le premier a créées (idempotence).
        [$second] = $this->import($parc, $decisions);
        self::assertSame(200, $second);

        self::assertSame($venues, $this->rowCount('venue'), 'aucun gymnase en double');
        self::assertSame($teams, $this->rowCount('team'), 'aucune équipe en double');
        self::assertSame($reservations, $this->rowCount('reservation'), 'aucune réservation en double');
    }

    // ───────────────────────── Abus : nom hostile (promesse 7) ─────────────────────────

    public function testHostileNameIsNeutralizedAtImport(): void
    {
        // Injection de formule : une équipe nommée « =HYPERLINK(...) » saisie telle quelle deviendrait
        // une formule vivante à l'ouverture de l'export XLSX dans Excel. Neutralisation la plus simple
        // (choisie ici) : à l'import, on retire le déclencheur de formule en tête du nom — le nom
        // stocké ne commence JAMAIS par `=`/`+`/`-`/`@`. Couplé au StringValueBinder de
        // SpreadsheetGenerator (toute cellule forcée en chaîne), l'export rend du texte de bout en bout.
        $this->scopeGucToClub($this->club->getId());

        $parc = $this->emptyParc();
        $parc['equipes'] = [['nom' => '=HYPERLINK("http://evil","SM1")', 'genre' => 'M']];
        $decisions = [['section' => 'equipes', 'index' => 0, 'coche' => true]];

        [$status] = $this->import($parc, $decisions);
        self::assertSame(200, $status);

        $team = $this->em->getRepository(Team::class)->findOneBy(['clubId' => $this->club->getId()], ['createdAt' => 'DESC']);
        self::assertInstanceOf(Team::class, $team);
        $name = $team->getName();
        self::assertNotSame('', $name, 'le nom neutralisé n\'est pas vidé');
        self::assertNotContains($name[0], ['=', '+', '-', '@'], 'le nom stocké ne commence jamais par un déclencheur de formule');
        self::assertStringContainsString('HYPERLINK', $name, 'la substance du nom survit, seul le déclencheur part');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = (new Club)
            ->setName('Parc Exec Club ' . $uid)->setSlug('parc-exec-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('parcexec' . $uid . '@test.com');
        $this->user->setFirstName('Parc');
        $this->user->setLastName('Exec');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'Password123!'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $cu = new ClubUser;
        $cu->setClubId($this->club->getId())->setUserId($this->user->getId())->setRole('admin')->setIsActive(true);
        $this->em->persist($cu);

        $this->season = new Season;
        $this->season->setClubId($this->club->getId())->setName('2026-2027')
            ->setStartDate(new DateTimeImmutable('2026-07-01'))->setEndDate(new DateTimeImmutable('2027-06-30'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($this->season);
        $this->em->flush();

        $this->provisionSeasonPlan($this->season);

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($this->user);
    }

    /**
     * Un parc-v1 vide recevable — chaque test remplit les sections qu'il éprouve.
     *
     * @return array<string, mixed>
     */
    private function emptyParc(): array
    {
        return ['format' => 'parc-v1', 'gymnases' => [], 'equipes' => [], 'creneaux' => [], 'mutualisations' => [], 'reservations' => []];
    }

    /**
     * @return array<string, mixed>
     */
    private function newTeamParc(): array
    {
        $parc = $this->emptyParc();
        $parc['equipes'] = [['nom' => 'SM1', 'genre' => 'M']];

        return $parc;
    }

    /**
     * POST `{payload, decisions}` sur l'endpoint d'import.
     *
     * @param array<string, mixed>       $parc
     * @param list<array<string, mixed>> $decisions
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function import(array $parc, array $decisions, ?string $token = null): array
    {
        $body = (string) json_encode(['payload' => $parc, 'decisions' => $decisions], \JSON_THROW_ON_ERROR);
        $this->client->request(
            'POST',
            '/api/clubs/' . $this->club->getId() . '/parc-import',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->token), 'CONTENT_TYPE' => 'application/json'],
            $body,
        );
        $response = $this->client->getResponse();
        $decoded = json_decode((string) $response->getContent(), true);

        return [$response->getStatusCode(), \is_array($decoded) ? $decoded : []];
    }

    private function makeVenue(string $name, bool $canSplit = false): Venue
    {
        $this->scopeGucToClub($this->club->getId());
        $venue = (new Venue)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName($name)->setSource('manual')->setIsActive(true)->setCanSplit($canSplit);
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function makeSlot(string $venueId, int $day, string $start): void
    {
        $this->scopeGucToClub($this->club->getId());
        $slot = (new VenueTrainingSlot)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setVenueId($venueId)
            ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($start))->setDurationMinutes(90)->setCapacity(1);
        $this->em->persist($slot);
        $this->em->flush();
    }

    private function makeTeam(string $name, int $sessionsPerWeek, ?Gender $gender = null, ?TeamLevel $level = null): Team
    {
        $this->scopeGucToClub($this->club->getId());
        $team = (new Team)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId($this->uuid())->setPriorityTierId(3)
            ->setName($name)->setSessionsPerWeek($sessionsPerWeek)->setIsActive(true)
            ->setGender($gender)->setLevel($level);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    /** Engage une équipe en lui attachant un match (l'engagement est dérivé de l'EXISTENCE d'un fixture). */
    private function engage(Team $team): void
    {
        $this->scopeGucToClub($this->club->getId());
        $fixture = (new Fixture)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setTeamId($team->getId())
            ->setMatchDate(new DateTimeImmutable('2026-10-04'))->setHomeAway(FixtureHomeAway::HOME)
            ->setOpponentLabel('AS Voisins');
        $fixture->setStatus(FixtureStatus::UNPLACED, new DateTimeImmutable);
        $this->em->persist($fixture);
        $this->em->flush();
    }

    /**
     * Attache le club à l'offre Essentiel (cap d'équipes fini) et rend les places restantes.
     */
    private function capClubAtEssentiel(): ?int
    {
        $plan = $this->em->getRepository(SubscriptionPlan::class)->findOneBy(['code' => 'essentiel']);
        \assert($plan instanceof SubscriptionPlan);

        $this->club->setPlanId($plan->getId())->setPaidSeasonYear(SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        $this->em->flush();

        return self::getContainer()->get(PlanEntitlements::class)->remainingTeamSlots($this->club, $this->season);
    }

    /**
     * Un club TIERS avec un gymnase — pour prouver qu'un `cibleId` hors tenant est refusé.
     *
     * @return array{venueId: string}
     */
    private function seedForeignClubWithVenue(string $venueName): array
    {
        $uid = uniqid('', true);
        $otherClub = (new Club)
            ->setName('Autre ' . $uid)->setSlug('autre-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($otherClub);
        $this->em->flush();

        $this->scopeGucToClub($otherClub->getId());
        $otherSeason = (new Season)
            ->setClubId($otherClub->getId())->setName('2026-2027')
            ->setStartDate(new DateTimeImmutable('2026-07-01'))->setEndDate(new DateTimeImmutable('2027-06-30'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($otherSeason);
        $this->em->flush();

        $venue = (new Venue)
            ->setClubId($otherClub->getId())->setSeasonId($otherSeason->getId())
            ->setName($venueName)->setSource('manual')->setIsActive(true);
        $this->em->persist($venue);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        return ['venueId' => $venue->getId()];
    }

    private function viewerToken(): string
    {
        $container = self::getContainer();
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $viewer = new User;
        $viewer->setEmail('viewer' . $uid . '@test.com');
        $viewer->setFirstName('View');
        $viewer->setLastName('Only');
        $viewer->setPasswordHash($hasher->hashPassword($viewer, 'Password123!'));
        $this->em->persist($viewer);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $cu = new ClubUser;
        $cu->setClubId($this->club->getId())->setUserId($viewer->getId())->setRole('viewer')->setIsActive(true);
        $this->em->persist($cu);
        $this->em->flush();

        return $container->get(JWTTokenManagerInterface::class)->create($viewer);
    }

    private function rowCount(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE club_id = :c',
            ['c' => $this->club->getId()],
        );
    }

    private function uuid(): string
    {
        return \sprintf(
            '%04x%04x-%04x-4%03x-8%03x-%04x%04x%04x',
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFF),
            random_int(0, 0xFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
        );
    }
}
