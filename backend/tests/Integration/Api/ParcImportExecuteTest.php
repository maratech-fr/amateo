<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\Gender;
use App\Enum\SeasonStatus;
use App\Service\ParcImport\ParcImportGate;
use App\Service\ParcImport\TeamNameInterpreter;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\CrossStack\ParcImportReservationSemanticsGateTest;
use App\Tests\Integration\Service\FfbbExcelImporterTest;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot correctif import IA (« G2 ») PR1 — l'IMPORT RÉEL transactionnel `POST /api/clubs/{id}/parc-import`,
 * RECALÉ sur le format `parc-v2`. Le gestionnaire a analysé son parc (dry-run {@see ParcImportAnalyzeTest}),
 * ajusté l'aperçu puis clique « Importer » ; le serveur bascule en écriture TOUT-OU-RIEN les lignes
 * DÉRIVÉES des cases qu'il a cochées.
 *
 * Ce qui change vs parc-v1 (jamais déployé) : le `payload` est un parc-v2 (`{format, gymnases?, cases[]}`),
 * et l'appli DÉRIVE des cases les équipes (genre/catégorie par {@see TeamNameInterpreter}),
 * les gymnases, les créneaux, les blocs mutualisés (label = `groupe`) et les réservations. Le CONTRAT de
 * décisions est inchangé : `decisions = [{section, index, coche, cibleId?, corrections?}]` sur les sections
 * DÉRIVÉES ; seules les lignes cochées sont écrites.
 *
 * Contrat (défini ici, implémenté par NEO) :
 *  - MÊME gate que l'analyse (patron {@see ParcImportGate}) : 404 non-membre,
 *    403 non-gestionnaire, 409 socle EN VIGUEUR (geste d'ONBOARDING), 429 limiteur, bornes ≤ 500 cases.
 *  - Ordre d'écriture : gymnases → équipes → créneaux → blocs → réservations ; une ligne cochée invalide
 *    AU MOMENT DE L'ÉCRITURE (chevauchement, Σ bloc, capacité, cap payant) → 422 nommant la ligne, RIEN écrit.
 *  - `cibleId` d'un AUTRE club/saison → 422, RIEN écrit (patron A1/A10).
 *  - La `duree` d'une case est TRANSPORTÉE au créneau créé ; un bloc mutualisé porte le `groupe` en LABEL.
 *  - Une section `coachs` d'un vieux chatbot est IGNORÉE en silence (coachs retirés du format, D11).
 *  - Idempotence : rejouer le même import ne crée AUCUN doublon.
 *  - Réponse 200 : `{recap:{<section>:{crees,apparies,misAJour,ignores}}}` ; 422 : `{errors:[…]}`.
 *
 * La SÉMANTIQUE de bout en bout (case parc-v2 → dérivation → réservation HARD → vrai moteur) est le NR
 * BLOQUANT {@see ParcImportReservationSemanticsGateTest} ; ici on prouve le CÂBLAGE, la dérivation, la
 * transaction tout-ou-rien, le périmètre tenant, l'idempotence et le retrait des coachs.
 *
 * ⚠ Limiteur Redis keyé PAR UTILISATEUR : chaque test crée sa propre identité — aucun FLUSHALL, aucune
 * fuite entre suites (mémoire backend-test-gotchas). APP_ENV=test.
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

    /**
     * Un `corrections.tierId` de TYPE aberrant (jamais un id de rang valide) : chaque forme doit être
     * refusée 422 AVANT persist, jamais repliée en silence sur la suggestion.
     *
     * @return iterable<string, array{0: mixed}>
     */
    public static function aberrantTierIdProvider(): iterable
    {
        yield 'un flottant (5.5) n\'est pas un id de rang' => [5.5];
        yield 'un entier négatif (-1) n\'est pas un id de rang' => [-1];
        yield 'une chaîne non numérique (« abc ») n\'est pas un id de rang' => ['abc'];
    }

    // ───────────────────────────── Gate (domaine sensible) ─────────────────────────────

    public function testNonManagerIsRefusedWithoutWriting(): void
    {
        $this->scopeGucToClub($this->club->getId());
        $before = $this->rowCount('team');

        [$status] = $this->import($this->oneTeamCaseParc(), [['section' => 'equipes', 'index' => 0, 'coche' => true]], token: $this->viewerToken());

        self::assertSame(403, $status, 'un membre non gestionnaire ne peut pas importer');
        self::assertSame($before, $this->rowCount('team'), 'un refus 403 n\'écrit rien');
    }

    public function testImportRefusedWhenSeasonPlanChosen(): void
    {
        // Planning lifecycle (ADR-0002) : socle EN VIGUEUR → l'import d'onboarding est refusé 409.
        $this->settleSeasonPlan($this->season);
        $this->scopeGucToClub($this->club->getId());
        $before = $this->rowCount('team');

        [$status] = $this->import($this->oneTeamCaseParc(), [['section' => 'equipes', 'index' => 0, 'coche' => true]]);

        self::assertSame(409, $status, 'socle en vigueur → 409');
        self::assertSame($before, $this->rowCount('team'), 'un refus 409 n\'écrit rien');
    }

    // ───────────────────────── Dérivation d'une case → entités (promesse centrale) ─────────────────────────

    public function testADerivedCaseCreatesVenueTeamSlotAndTransportsItsDuration(): void
    {
        // UNE CASE « SM1 lundi 18:00, 75 min » dans un gymnase neuf. L'appli DÉRIVE : le gymnase, l'équipe
        // SM1 (genre M / seniors déduits du nom), le créneau et sa réservation. La DURÉE de la case (75)
        // est TRANSPORTÉE au créneau. Toutes les lignes dérivées sont cochées.
        $this->scopeGucToClub($this->club->getId());
        $venuesBefore = $this->rowCount('venue');
        $teamsBefore = $this->rowCount('team');
        $slotsBefore = $this->rowCount('venue_training_slot');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Palais Neuf', 'jour' => 1, 'debut' => '18:00', 'duree' => 75, 'equipes' => ['SM1']],
        ]);
        $decisions = [
            ['section' => 'gymnases', 'index' => 0, 'coche' => true],
            ['section' => 'equipes', 'index' => 0, 'coche' => true],
            ['section' => 'creneaux', 'index' => 0, 'coche' => true],
            ['section' => 'reservations', 'index' => 0, 'coche' => true],
        ];

        [$status] = $this->import($parc, $decisions);
        self::assertSame(200, $status);

        self::assertSame($venuesBefore + 1, $this->rowCount('venue'), 'le gymnase dérivé est créé');
        self::assertSame($teamsBefore + 1, $this->rowCount('team'), 'l\'équipe dérivée est créée');
        self::assertSame($slotsBefore + 1, $this->rowCount('venue_training_slot'), 'le créneau dérivé est créé');

        $team = $this->em->getRepository(Team::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'SM1']);
        self::assertInstanceOf(Team::class, $team);
        self::assertSame(Gender::M, $team->getGender(), 'le genre est déduit du nom « SM1 »');

        $venue = $this->em->getRepository(Venue::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'Palais Neuf']);
        self::assertInstanceOf(Venue::class, $venue);
        $slot = $this->em->getRepository(VenueTrainingSlot::class)->findOneBy(['clubId' => $this->club->getId(), 'venueId' => $venue->getId()]);
        self::assertInstanceOf(VenueTrainingSlot::class, $slot);
        self::assertSame(75, $slot->getDurationMinutes(), 'la durée de la case est transportée au créneau');
        self::assertSame(1, $slot->getDayOfWeek());
        self::assertSame('18:00', $slot->getStartTime()->format('H:i'));
    }

    public function testUncheckedDerivedLineIsNotWritten(): void
    {
        // Cochage : deux cases, deux équipes dérivées, une seule cochée. L'autre équipe n'est PAS écrite.
        $this->scopeGucToClub($this->club->getId());
        $teamsBefore = $this->rowCount('team');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Palais Neuf', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
            ['gymnase' => 'Palais Neuf', 'jour' => 2, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SF1']],
        ]);
        $decisions = [
            ['section' => 'equipes', 'index' => 0, 'coche' => true],
            ['section' => 'equipes', 'index' => 1, 'coche' => false],
        ];

        [$status] = $this->import($parc, $decisions);
        self::assertSame(200, $status);

        self::assertSame($teamsBefore + 1, $this->rowCount('team'), 'seule l\'équipe cochée est écrite');
        self::assertNotNull($this->em->getRepository(Team::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'SM1']));
        self::assertNull($this->em->getRepository(Team::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'SF1']), 'la ligne décochée n\'est jamais créée');
    }

    public function testACreatedTeamHonorsTheCorrectedSessionsCount(): void
    {
        // Retours fondateur 2026-10-26 — le champ « Séances » d'une ligne « Créer » voyage dans
        // `corrections.seances` ; l'équipe CRÉÉE naît avec ce nombre, jamais le défaut. FILET VERT (pas
        // un rouge inventé) : {@see ParcImportExecutor::sessionsOf} (backend/src/Service/ParcImport/
        // ParcImportExecutor.php:909-918) lit DÉJÀ `corrections.seances` à la création (précédence sur
        // le `seances` dérivé). Ce test CITE et VERROUILLE cet honneur à la création — le jumeau front
        // est « 2b — ligne Créer » (ParcImportModal.test.tsx).
        $this->scopeGucToClub($this->club->getId());

        $parc = $this->parcWithCases([
            ['gymnase' => 'Palais Neuf', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
        ]);
        $decisions = [['section' => 'equipes', 'index' => 0, 'coche' => true, 'corrections' => ['seances' => 3]]];

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(200, $status, 'une création avec corrections.seances s\'importe : ' . json_encode($body['errors'] ?? [], \JSON_UNESCAPED_UNICODE));

        $team = $this->em->getRepository(Team::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'SM1']);
        self::assertInstanceOf(Team::class, $team);
        self::assertSame(3, $team->getSessionsPerWeek(), 'l\'équipe créée porte le nombre de séances corrigé (3), jamais le défaut');
    }

    // ───────────────────────── PR2 (D9) — rang/genre/entente d'une équipe CRÉÉE (corrections) ─────────────────────────
    // Refonte import IA PR2 : l'aperçu porte sur une ligne équipe « Créer » des SUGGESTIONS (rang,
    // genre, entente) que le gestionnaire ajuste ; l'executor honore `corrections.{tierId, genre, ctc}`
    // à la CRÉATION SEULEMENT. Le genre est OBLIGATOIRE (§0 point 1 AMENDÉ, « plus MIXTE ») : une
    // création sans genre connu (ni dérivé, ni corrigé) refuse l'import. Abus : tierId/genre forgés →
    // 422 AVANT persist. L'invariant « appariée = séances seules » interdit que ces corrections
    // touchent une équipe appariée. Aperçu jumeau (suggestions servies) : {@see ParcImportAnalyzeTest}.

    public function testACreatedTeamHonorsCorrectionsForTierGenderAndCtc(): void
    {
        // Ligne « Créer » : le gestionnaire force le rang A, CORRIGE le genre (de M dérivé vers F) et
        // coche l'entente. L'équipe créée porte les TROIS. ROUGE aujourd'hui : l'executor fige le rang
        // à 5 en dur, lit le genre DÉRIVÉ (M) et n'a pas d'entente. Rangs résolus PAR LABEL (jamais un
        // id en dur).
        $this->seedPriorityTiers();
        $this->scopeGucToClub($this->club->getId());

        $parc = $this->parcWithCases([
            ['gymnase' => 'Palais Neuf', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
        ]);
        $decisions = [['section' => 'equipes', 'index' => 0, 'coche' => true, 'corrections' => ['tierId' => $this->tierIdForLabel('A'), 'genre' => 'F', 'ctc' => true]]];

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(200, $status, 'une création avec corrections rang/genre/entente s\'importe : ' . json_encode($body['errors'] ?? [], \JSON_UNESCAPED_UNICODE));

        $team = $this->em->getRepository(Team::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'SM1']);
        self::assertInstanceOf(Team::class, $team);
        self::assertSame($this->tierIdForLabel('A'), $team->getPriorityTierId(), 'le rang corrigé (A) est appliqué, jamais le 5 en dur');
        self::assertSame(Gender::F, $team->getGender(), 'le genre CORRIGÉ (F) l\'emporte sur le genre dérivé du nom (M)');
        self::assertTrue($team->getIsCtc(), 'l\'entente cochée à la création naît true');
    }

    public function testACreatedTeamHonorsTheCtcPartnerAtCreation(): void
    {
        // Refonte PR2 (D9) — une équipe CRÉÉE en entente (ctc=true) naît avec le CLUB PARTENAIRE saisi
        // (corrections.ctcPartner), tel qu'il voyage depuis le champ « club partenaire » de l'étape
        // Ententes. VERROUILLE l'honneur du partenaire à la création (falsifiable : si l'executor
        // oubliait corrections.ctcPartner, le partenaire resterait null → rouge). Jumeau front : « étape
        // Ententes » ({@see ParcImportModal.test.tsx}) ; jumeau rang/genre/entente :
        // testACreatedTeamHonorsCorrectionsForTierGenderAndCtc.
        $this->seedPriorityTiers();
        $this->scopeGucToClub($this->club->getId());

        $parc = $this->parcWithCases([
            ['gymnase' => 'Palais Neuf', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
        ]);
        $decisions = [['section' => 'equipes', 'index' => 0, 'coche' => true, 'corrections' => ['genre' => 'M', 'ctc' => true, 'ctcPartner' => 'Basket Club Partenaire']]];

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(200, $status, 'une création en entente avec partenaire s\'importe : ' . json_encode($body['errors'] ?? [], \JSON_UNESCAPED_UNICODE));

        $team = $this->em->getRepository(Team::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'SM1']);
        self::assertInstanceOf(Team::class, $team);
        self::assertTrue($team->getIsCtc(), 'l\'équipe créée est bien en entente');
        self::assertSame('Basket Club Partenaire', $team->getCtcPartner(), 'le club partenaire saisi est honoré à la création');
    }

    public function testTheCtcPartnerIsIgnoredOnAnAppariedTeam(): void
    {
        // Invariant « appariée = séances seules » étendu au club partenaire : sur une équipe EXISTANTE
        // (appariée), corrections.ctcPartner est IGNORÉ — l'import ne touche JAMAIS l'entente de
        // l'existant (ni le drapeau ctc, ni le partenaire). Filet qui VERROUILLE l'invariant
        // (falsifiable : s'il fuyait au branche appariée, le partenaire changerait → rouge). Jumeau de la
        // CRÉATION : testACreatedTeamHonorsTheCtcPartnerAtCreation.
        $this->seedPriorityTiers();
        $existing = $this->makeTeam('SM1', sessionsPerWeek: 2); // ctcPartner null, hors entente
        $this->scopeGucToClub($this->club->getId());

        $parc = $this->parcWithCases([
            ['gymnase' => 'Palais Neuf', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
        ]);
        $decisions = [['section' => 'equipes', 'index' => 0, 'coche' => true, 'corrections' => ['ctc' => true, 'ctcPartner' => 'Club Intrus', 'seances' => 3]]];

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(200, $status, 'un appariement avec corrections s\'importe : ' . json_encode($body['errors'] ?? [], \JSON_UNESCAPED_UNICODE));

        $this->em->clear();
        $reloaded = $this->em->getRepository(Team::class)->find($existing->getId());
        self::assertInstanceOf(Team::class, $reloaded);
        self::assertNull($reloaded->getCtcPartner(), 'appariée : le club partenaire ne bouge PAS (corrections.ctcPartner ignorée)');
        self::assertFalse($reloaded->getIsCtc(), 'appariée : l\'entente ne bouge PAS (corrections.ctc ignorée)');
        self::assertSame(3, $reloaded->getSessionsPerWeek(), 'SEULES les séances bougent sur une appariée');
    }

    public function testACreatedTeamWithoutAKnownGenderIsRefusedNamingTheLineWritingNothing(): void
    {
        // §0 point 1 AMENDÉ — genre OBLIGATOIRE. Une ligne « Créer » dont le nom ne porte AUCUN
        // marqueur de genre (« Loisir Détente ») et qui n'a PAS de `corrections.genre` ne doit jamais
        // créer une équipe sans genre en silence : 422 nommant la ligne équipe, RIEN écrit. ROUGE
        // aujourd'hui : le dériveur replie en MIXTE et l'équipe naît (200).
        $this->seedPriorityTiers();
        $this->scopeGucToClub($this->club->getId());
        $teamsBefore = $this->rowCount('team');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Palais Neuf', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['Loisir Détente']],
        ]);
        $decisions = [['section' => 'equipes', 'index' => 0, 'coche' => true]]; // aucune corrections.genre

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(422, $status, 'une création sans genre connu est refusée (genre obligatoire)');
        self::assertStringContainsString('équipe', implode(' ', $body['errors'] ?? []), 'le 422 nomme la ligne équipe fautive');
        self::assertSame($teamsBefore, $this->rowCount('team'), 'tout-ou-rien : aucune équipe sans genre créée');
    }

    public function testCorrectionsTierGenderAndCtcAreIgnoredOnAnAppariedTeamOnlySessionsMove(): void
    {
        // Invariant « appariée = séances seules » : sur une équipe EXISTANTE (appariée), les corrections
        // rang/genre/entente sont IGNORÉES — seules les séances bougent. Un `corrections` hostile/erroné
        // ne doit JAMAIS re-rank, re-genrer ou mettre en entente une équipe déjà en base. Filet VERT
        // qui VERROUILLE l'invariant (falsifiable : s'il fuyait au branche appariée, rang/genre/entente
        // changeraient → rouge). Jumeau de la CRÉATION : testACreatedTeamHonorsCorrectionsForTierGenderAndCtc.
        $this->seedPriorityTiers();
        $existing = $this->makeTeam('SM1', sessionsPerWeek: 2); // rang 3, genre null, hors entente
        $this->scopeGucToClub($this->club->getId());

        $parc = $this->parcWithCases([
            ['gymnase' => 'Palais Neuf', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
        ]);
        $decisions = [['section' => 'equipes', 'index' => 0, 'coche' => true, 'corrections' => ['tierId' => $this->tierIdForLabel('S'), 'genre' => 'F', 'ctc' => true, 'seances' => 4]]];

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(200, $status, 'un appariement avec corrections s\'importe : ' . json_encode($body['errors'] ?? [], \JSON_UNESCAPED_UNICODE));

        $this->em->clear();
        $reloaded = $this->em->getRepository(Team::class)->find($existing->getId());
        self::assertInstanceOf(Team::class, $reloaded);
        self::assertSame(3, $reloaded->getPriorityTierId(), 'appariée : le rang ne bouge PAS (corrections.tierId ignorée)');
        self::assertNull($reloaded->getGender(), 'appariée : le genre ne bouge PAS (corrections.genre ignorée)');
        self::assertFalse($reloaded->getIsCtc(), 'appariée : l\'entente ne bouge PAS (corrections.ctc ignorée)');
        self::assertSame(4, $reloaded->getSessionsPerWeek(), 'SEULES les séances bougent sur une appariée');
    }

    public function testACreatedTeamWithAForgedTierIdIsRefusedBeforePersist(): void
    {
        // Abus — un `corrections.tierId` inconnu (forgé) ne doit jamais devenir un rang en base :
        // 422 AVANT tout persist, rien écrit. ROUGE aujourd'hui : l'executor ne valide pas le tierId
        // des corrections (rang figé à 5 en dur).
        $this->seedPriorityTiers();
        $this->scopeGucToClub($this->club->getId());
        $teamsBefore = $this->rowCount('team');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Palais Neuf', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
        ]);
        $decisions = [['section' => 'equipes', 'index' => 0, 'coche' => true, 'corrections' => ['tierId' => 999999, 'genre' => 'M']]];

        [$status] = $this->import($parc, $decisions);
        self::assertSame(422, $status, 'un tierId forgé est refusé');
        self::assertSame($teamsBefore, $this->rowCount('team'), 'rien écrit avec un tierId forgé (tout-ou-rien)');
    }

    public function testACreatedTeamWithAGenderOutsideTheEnumIsRefusedBeforePersist(): void
    {
        // Abus — un `corrections.genre` hors enum (« Z ») ne doit jamais devenir un genre en base :
        // 422 AVANT tout persist, rien écrit. ROUGE aujourd'hui : l'executor ignore corrections.genre
        // (lit le genre dérivé) et ne valide rien.
        $this->seedPriorityTiers();
        $this->scopeGucToClub($this->club->getId());
        $teamsBefore = $this->rowCount('team');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Palais Neuf', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
        ]);
        $decisions = [['section' => 'equipes', 'index' => 0, 'coche' => true, 'corrections' => ['genre' => 'Z']]];

        [$status] = $this->import($parc, $decisions);
        self::assertSame(422, $status, 'un genre hors enum est refusé');
        self::assertSame($teamsBefore, $this->rowCount('team'), 'rien écrit avec un genre forgé (tout-ou-rien)');
    }

    public function testACreatedTeamWithAnOverlongCtcPartnerIsRefusedNamingTheLineWritingNothing(): void
    {
        // Revue sécurité PR2 — le club partenaire d'une entente est un varchar(120) ({@see Team::$ctcPartner}).
        // Un `corrections.ctcPartner` > 120 caractères ne doit jamais atteindre la colonne : 422 nommant la
        // ligne équipe, RIEN écrit (tout-ou-rien). ROUGE aujourd'hui : l'executor pose le partenaire sans le
        // borner ({@see ParcImportExecutor} branche création) → la base tronque/explose (PostgreSQL 22001,
        // 500). Jumeau front (défense de surface, maxLength 120) : « étape Ententes » ({@see ParcImportModal.test.tsx}).
        $this->seedPriorityTiers();
        $this->scopeGucToClub($this->club->getId());
        $teamsBefore = $this->rowCount('team');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Palais Neuf', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
        ]);
        $decisions = [['section' => 'equipes', 'index' => 0, 'coche' => true, 'corrections' => ['genre' => 'M', 'ctc' => true, 'ctcPartner' => str_repeat('é', 121)]]];

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(422, $status, 'un club partenaire > 120 caractères est refusé (jamais un 500 de troncature)');
        self::assertStringContainsString('équipe', implode(' ', $body['errors'] ?? []), 'le 422 nomme la ligne équipe fautive');
        self::assertSame($teamsBefore, $this->rowCount('team'), 'tout-ou-rien : aucune équipe créée avec un partenaire trop long');
    }

    #[DataProvider('aberrantTierIdProvider')]
    public function testACreatedTeamWithAnAberrantTierIdTypeIsRefusedBeforePersist(mixed $tierId): void
    {
        // Abus — un `corrections.tierId` qui n'est PAS un id de rang entier non négatif (flottant,
        // négatif, chaîne non numérique) ne doit jamais devenir un rang en base : 422 AVANT tout persist,
        // rien écrit. ROUGE aujourd'hui pour le flottant et la chaîne : {@see ParcImportExecutor::createdTeamTierId}
        // n'accepte que `int`/chaîne de chiffres et REPLIE SILENCIEUSEMENT toute autre forme sur la
        // suggestion de rang (l'équipe naît avec un rang deviné, 200) — une valeur forgée doit être
        // REJETÉE, pas devinée. Le genre est fourni pour isoler la cause (seul le tierId est fautif).
        $this->seedPriorityTiers();
        $this->scopeGucToClub($this->club->getId());
        $teamsBefore = $this->rowCount('team');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Palais Neuf', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
        ]);
        $decisions = [['section' => 'equipes', 'index' => 0, 'coche' => true, 'corrections' => ['tierId' => $tierId, 'genre' => 'M']]];

        [$status] = $this->import($parc, $decisions);
        self::assertSame(422, $status, 'un tierId de type aberrant est refusé avant persist');
        self::assertSame($teamsBefore, $this->rowCount('team'), 'rien écrit avec un tierId aberrant (tout-ou-rien)');
    }

    // ───────────────────────── Bloc mutualisé : label = groupe (promesse « CEC ») ─────────────────────────

    public function testACaseWithAGroupeCreatesABlockLabelledByThatGroupe(): void
    {
        // Une case parc-v2 portant deux équipes ET un `groupe` « CEC » (3 groupes CEC au BCCL, le
        // mercredi) : l'appli crée un SharedTrainingBlock de mutualisation dont le LABEL est « CEC »
        // — le groupe nomme le bloc, il n'est JAMAIS une équipe à créer.
        $u9m1 = $this->makeTeam('U9M1', sessionsPerWeek: 1);
        $u11f2 = $this->makeTeam('U11F2', sessionsPerWeek: 1);
        $venue = $this->makeVenue('Gymnase Mateo', canSplit: true);
        $this->makeSlot($venue->getId(), 3, '16:00', capacity: 2);
        $this->scopeGucToClub($this->club->getId());
        $blocksBefore = $this->rowCount('shared_training_block');
        $teamsBefore = $this->rowCount('team');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Gymnase Mateo', 'jour' => 3, 'debut' => '16:00', 'duree' => 90, 'equipes' => ['U9M1', 'U11F2'], 'groupe' => 'CEC'],
        ]);
        $decisions = [['section' => 'mutualisations', 'index' => 0, 'coche' => true]];

        [$status] = $this->import($parc, $decisions);
        self::assertSame(200, $status);

        self::assertSame($blocksBefore + 1, $this->rowCount('shared_training_block'), 'le bloc mutualisé est créé');
        self::assertSame($teamsBefore, $this->rowCount('team'), '« CEC » n\'est jamais créée comme équipe — c\'est le label du groupe');

        $block = $this->em->getRepository(SharedTrainingBlock::class)->findOneBy(['clubId' => $this->club->getId()], ['createdAt' => 'DESC']);
        self::assertInstanceOf(SharedTrainingBlock::class, $block);
        self::assertSame('CEC', $block->getLabel(), 'le groupe de la case nomme le bloc mutualisé');

        // Les deux membres existants sont rattachés, aucun 3e membre « CEC ».
        $members = $this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['clubId' => $this->club->getId(), 'blockId' => $block->getId()]);
        $memberTeamIds = array_map(static fn (SharedTrainingBlockTeam $m): string => $m->getTeamId(), $members);
        self::assertContains($u9m1->getId(), $memberTeamIds);
        self::assertContains($u11f2->getId(), $memberTeamIds);
        self::assertCount(2, $members, 'le bloc regroupe exactement les deux équipes de la case');
    }

    public function testACorrectedGroupeOverridesTheBlockLabel(): void
    {
        // Lot correctif import IA « G2 » PR2 (D8, requalification du groupe) — le gestionnaire voit le
        // `groupe` DÉRIVÉ de la case (« CEC ») à l'aperçu et le CORRIGE (« Section Loisirs ») avant
        // d'importer. Le libellé corrigé voyage dans `corrections.groupe` de la décision (canal des
        // corrections, comme `seances`/`jour`/`debut`/`duree`) et nomme le bloc — le `groupe` brut de
        // la case ne doit JAMAIS l'emporter sur la correction. Le `payload` collé reste intact
        // (le serveur réécrit depuis la source + les corrections, jamais depuis l'aperçu reconstruit).
        $this->makeTeam('U9M1', sessionsPerWeek: 1);
        $this->makeTeam('U11F2', sessionsPerWeek: 1);
        $venue = $this->makeVenue('Gymnase Mateo', canSplit: true);
        $this->makeSlot($venue->getId(), 3, '16:00', capacity: 2);
        $this->scopeGucToClub($this->club->getId());

        $parc = $this->parcWithCases([
            ['gymnase' => 'Gymnase Mateo', 'jour' => 3, 'debut' => '16:00', 'duree' => 90, 'equipes' => ['U9M1', 'U11F2'], 'groupe' => 'CEC'],
        ]);
        $decisions = [['section' => 'mutualisations', 'index' => 0, 'coche' => true, 'corrections' => ['groupe' => 'Section Loisirs']]];

        [$status] = $this->import($parc, $decisions);
        self::assertSame(200, $status);

        $block = $this->em->getRepository(SharedTrainingBlock::class)->findOneBy(['clubId' => $this->club->getId()], ['createdAt' => 'DESC']);
        self::assertInstanceOf(SharedTrainingBlock::class, $block);
        self::assertSame('Section Loisirs', $block->getLabel(), 'le groupe CORRIGÉ nomme le bloc, jamais le groupe brut de la case');
    }

    // ───────────────────────── Coachs retirés (D11) ─────────────────────────

    public function testAStaleCoachsSectionFromAnOldChatbotIsIgnoredSilently(): void
    {
        // parc-v2 ne porte PLUS de coachs (D11). Un vieux chatbot qui en émet une section : IGNORÉE en
        // SILENCE — aucun coach créé, aucune erreur, l'import de la case réussit quand même.
        $this->scopeGucToClub($this->club->getId());
        $coachesBefore = $this->rowCount('coach');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Palais Neuf', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
        ]);
        $parc['coachs'] = [['nom' => 'Alice Martin', 'equipe' => 'SM1']]; // section morte d'un ancien chatbot

        $decisions = [['section' => 'equipes', 'index' => 0, 'coche' => true]];

        [$status] = $this->import($parc, $decisions);
        self::assertSame(200, $status, 'une section coachs morte ne fait pas échouer l\'import');
        self::assertSame($coachesBefore, $this->rowCount('coach'), 'aucun coach n\'est créé — le format ne les porte plus');
    }

    // ───────────────────────── Tout-ou-rien & tenant (domaine sensible) ─────────────────────────

    public function testAnInvalidCheckedLineRollsBackEverythingAndNamesTheLine(): void
    {
        // Deux cases du MÊME gymnase le MÊME jour se chevauchent (18:00-19:30 et 19:00-20:30). La garde
        // de chevauchement MORD à l'écriture : RIEN n'est écrit (tout-ou-rien), le 422 nomme la ligne.
        $venue = $this->makeVenue('Palais Neuf');
        $this->scopeGucToClub($this->club->getId());
        $slotsBefore = $this->rowCount('venue_training_slot');
        $teamsBefore = $this->rowCount('team');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Palais Neuf', 'jour' => 2, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
            ['gymnase' => 'Palais Neuf', 'jour' => 2, 'debut' => '19:00', 'duree' => 90, 'equipes' => ['SF1']],
        ]);
        $decisions = [
            ['section' => 'equipes', 'index' => 0, 'coche' => true],
            ['section' => 'equipes', 'index' => 1, 'coche' => true],
            ['section' => 'creneaux', 'index' => 0, 'coche' => true, 'cibleId' => $venue->getId()],
            ['section' => 'creneaux', 'index' => 1, 'coche' => true, 'cibleId' => $venue->getId()],
        ];

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(422, $status, 'une ligne cochée qui viole une garde refuse l\'import');
        self::assertNotEmpty($body['errors'] ?? [], 'le 422 nomme la ligne fautive');

        self::assertSame($slotsBefore, $this->rowCount('venue_training_slot'), 'tout-ou-rien : aucun créneau écrit');
        self::assertSame($teamsBefore, $this->rowCount('team'), 'tout-ou-rien : aucune équipe écrite non plus');
    }

    public function testCibleIdFromAnotherClubIsRefusedWithoutWriting(): void
    {
        // Patron A1 — un `cibleId` d'un AUTRE club ne doit JAMAIS être écrit : re-vérification tenant
        // côté serveur, 422, RIEN écrit.
        $foreign = $this->seedForeignClubWithVenue('Palais Étranger');
        $this->scopeGucToClub($this->club->getId());
        $reservationsBefore = $this->rowCount('reservation');
        $venuesBefore = $this->rowCount('venue');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Palais', 'jour' => 2, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
        ]);
        // Le gestionnaire ré-apparie la ligne gymnase à une cible d'un AUTRE club (hostile / erreur).
        $decisions = [
            ['section' => 'gymnases', 'index' => 0, 'coche' => true, 'cibleId' => $foreign['venueId']],
            ['section' => 'equipes', 'index' => 0, 'coche' => true],
            ['section' => 'reservations', 'index' => 0, 'coche' => true],
        ];

        [$status] = $this->import($parc, $decisions);
        self::assertSame(422, $status, 'un cibleId hors tenant est refusé');
        self::assertSame($reservationsBefore, $this->rowCount('reservation'), 'rien n\'est écrit avec une cible hors tenant');
        self::assertSame($venuesBefore, $this->rowCount('venue'), 'aucun gymnase écrit non plus (tout-ou-rien)');
    }

    // ───────────────────────── Idempotence (domaine sensible) ─────────────────────────

    public function testReplayingTheSameImportCreatesNoDuplicate(): void
    {
        $this->scopeGucToClub($this->club->getId());

        $parc = $this->parcWithCases([
            ['gymnase' => 'Palais Neuf', 'jour' => 2, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
        ]);
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

        [$second] = $this->import($parc, $decisions);
        self::assertSame(200, $second);

        self::assertSame($venues, $this->rowCount('venue'), 'aucun gymnase en double');
        self::assertSame($teams, $this->rowCount('team'), 'aucune équipe en double');
        self::assertSame($reservations, $this->rowCount('reservation'), 'aucune réservation en double');
    }

    public function testImportingAWholeRealChatbotParcSucceeds(): void
    {
        // Preuve de bout en bout sur une sortie RÉELLE : rillieux-gpt (18 cases, gymnases omis). Toutes
        // les lignes dérivées « à créer » cochées — l'import entier aboutit en une transaction.
        $this->scopeGucToClub($this->club->getId());
        $parc = $this->fixtureParc('rillieux-gpt.json');

        [$status, $body] = $this->importAllCreatable($parc);

        self::assertSame(200, $status, 'un parc réel entier s\'importe : ' . json_encode($body['errors'] ?? [], \JSON_UNESCAPED_UNICODE));
        self::assertGreaterThan(0, $this->rowCount('team'), 'des équipes sont dérivées et créées');
    }

    // ───────────────────────── Gymnase de la réservation de groupe (P4-363) ─────────────────────────

    public function testGroupReservationUsesDecisionVenueId(): void
    {
        // Le gymnase de la réservation de groupe d'une mutualisation est choisi par le NOUVEAU champ
        // `venueId` de la décision (sélecteur de gymnase sur la ligne), JAMAIS par le `cibleId`
        // (réservé au BLOC apparié). Le nom de la case pointe « Gymnase A » mais le gestionnaire a
        // choisi « Gymnase B » : la réservation de groupe naît sur B, jamais sur A.
        $this->makeTeam('U9M1', sessionsPerWeek: 2);
        $this->makeTeam('U11F2', sessionsPerWeek: 2);
        $venueA = $this->makeVenue('Gymnase A', canSplit: true);
        $venueB = $this->makeVenue('Gymnase B', canSplit: true);
        $this->makeSlot($venueA->getId(), 3, '16:00', capacity: 2);
        $this->makeSlot($venueB->getId(), 3, '16:00', capacity: 2);
        $this->scopeGucToClub($this->club->getId());

        $parc = $this->parcWithCases([
            ['gymnase' => 'Gymnase A', 'jour' => 3, 'debut' => '16:00', 'duree' => 90, 'equipes' => ['U9M1', 'U11F2'], 'groupe' => 'CEC'],
        ]);
        $decisions = [['section' => 'mutualisations', 'index' => 0, 'coche' => true, 'venueId' => $venueB->getId()]];

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(200, $status, 'l\'import aboutit : ' . json_encode($body['errors'] ?? [], \JSON_UNESCAPED_UNICODE));

        self::assertSame(2, $this->reservationCountOnVenue($venueB->getId()), 'la réservation de groupe naît sur le gymnase CHOISI (venueId)');
        self::assertSame(0, $this->reservationCountOnVenue($venueA->getId()), 'jamais sur le gymnase du nom de la case quand un venueId est choisi');
    }

    public function testAppariedMutualisationReimportSucceeds(): void
    {
        // BUG RÉEL — réimporter une mutualisation APPARIÉE à un bloc existant : la décision porte le
        // `cibleId` du BLOC (ce que l'aperçu émet pour un appariement). Aujourd'hui l'executor lit ce
        // `cibleId` comme un GYMNASE (`ownedVenue`) → 422 « Gymnase introuvable pour cet appariement ».
        // Le réimport idempotent doit ABOUTIR (le gymnase se résout par le nom de la case, le `cibleId`
        // ne désigne QUE le bloc).
        $this->makeTeam('U9M1', sessionsPerWeek: 2);
        $this->makeTeam('U11F2', sessionsPerWeek: 2);
        $venue = $this->makeVenue('Gymnase Mateo', canSplit: true);
        $this->makeSlot($venue->getId(), 3, '16:00', capacity: 2);
        $this->scopeGucToClub($this->club->getId());

        $parc = $this->parcWithCases([
            ['gymnase' => 'Gymnase Mateo', 'jour' => 3, 'debut' => '16:00', 'duree' => 90, 'equipes' => ['U9M1', 'U11F2'], 'groupe' => 'CEC'],
        ]);

        // 1er import (à créer, cibleId null) : le bloc et sa réservation de groupe naissent.
        [$first] = $this->import($parc, [['section' => 'mutualisations', 'index' => 0, 'coche' => true]]);
        self::assertSame(200, $first);

        $block = $this->em->getRepository(SharedTrainingBlock::class)->findOneBy(['clubId' => $this->club->getId()], ['createdAt' => 'DESC']);
        self::assertInstanceOf(SharedTrainingBlock::class, $block);

        // Réimport : l'aperçu apparie désormais la ligne au bloc existant → `cibleId` = id du BLOC.
        $this->scopeGucToClub($this->club->getId());
        [$second, $body] = $this->import($parc, [['section' => 'mutualisations', 'index' => 0, 'coche' => true, 'cibleId' => $block->getId()]]);
        self::assertSame(200, $second, 'réimporter une mutualisation appariée ne doit pas 422 « Gymnase introuvable » : ' . json_encode($body['errors'] ?? [], \JSON_UNESCAPED_UNICODE));
    }

    public function testGroupReservationVenueIdFromAnotherClubIsRefusedWithoutWriting(): void
    {
        // Patron A1 étendu au nouveau canal `venueId` : un gymnase d'un AUTRE club ne doit JAMAIS
        // être écrit — re-vérification tenant côté serveur, 422, RIEN écrit (tout-ou-rien).
        $this->makeTeam('U9M1', sessionsPerWeek: 2);
        $this->makeTeam('U11F2', sessionsPerWeek: 2);
        $venue = $this->makeVenue('Gymnase Mateo', canSplit: true);
        $this->makeSlot($venue->getId(), 3, '16:00', capacity: 2);
        $foreign = $this->seedForeignClubWithVenue('Palais Étranger');
        $this->scopeGucToClub($this->club->getId());
        $reservationsBefore = $this->rowCount('reservation');
        $blocksBefore = $this->rowCount('shared_training_block');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Gymnase Mateo', 'jour' => 3, 'debut' => '16:00', 'duree' => 90, 'equipes' => ['U9M1', 'U11F2'], 'groupe' => 'CEC'],
        ]);
        $decisions = [['section' => 'mutualisations', 'index' => 0, 'coche' => true, 'venueId' => $foreign['venueId']]];

        [$status] = $this->import($parc, $decisions);
        self::assertSame(422, $status, 'un venueId hors tenant est refusé');
        self::assertSame($reservationsBefore, $this->rowCount('reservation'), 'rien écrit avec un gymnase hors tenant');
        self::assertSame($blocksBefore, $this->rowCount('shared_training_block'), 'aucun bloc écrit non plus (tout-ou-rien)');
    }

    public function testBlankGroupReservationVenueIdFallsBackToNameResolution(): void
    {
        // Narrowing du nouveau canal : un `venueId` vide (ou non-chaîne) vaut `null` — la réservation
        // de groupe retombe sur la résolution par le NOM de la case, comme avant le sélecteur.
        $this->makeTeam('U9M1', sessionsPerWeek: 2);
        $this->makeTeam('U11F2', sessionsPerWeek: 2);
        $venue = $this->makeVenue('Gymnase Mateo', canSplit: true);
        $this->makeSlot($venue->getId(), 3, '16:00', capacity: 2);
        $this->scopeGucToClub($this->club->getId());

        $parc = $this->parcWithCases([
            ['gymnase' => 'Gymnase Mateo', 'jour' => 3, 'debut' => '16:00', 'duree' => 90, 'equipes' => ['U9M1', 'U11F2'], 'groupe' => 'CEC'],
        ]);
        $decisions = [['section' => 'mutualisations', 'index' => 0, 'coche' => true, 'venueId' => '']];

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(200, $status, 'un venueId vide n\'empêche pas l\'import : ' . json_encode($body['errors'] ?? [], \JSON_UNESCAPED_UNICODE));
        self::assertSame(2, $this->reservationCountOnVenue($venue->getId()), 'venueId vide → résolution par le nom de la case');
    }

    // ───────────────────────── PR1 — appariement ambigu : refus sans choix, apparié avec choix (D5) ─────────────────────────
    // L'aperçu refondu sert une ligne « ambigu » (≥ 2 candidats). Côté écriture, une telle ligne
    // COCHÉE sans cible choisie ne doit JAMAIS créer un doublon en silence : refus 422 nommant la
    // ligne, RIEN écrit. Tranchée (cibleId = un candidat), elle s'importe en APPARIEMENT, séances seules.
    // Aperçu jumeau : {@see ParcImportAnalyzeTest::testAnAmbiguousTeamLabelIsServedAsAmbiguWithItsCandidates}.

    public function testACheckedTeamLineWithAnAmbiguousLabelAndNoChoiceIsRefusedWritingNothing(): void
    {
        // « Armond » tombe dans le seuil flou de « Armand » ET « Armund » → ambigu. Cochée SANS cible,
        // la ligne est REFUSÉE (422 nommant l'équipe), rien écrit — avant PR1, l'import créait « Armond »
        // en 3e équipe en silence.
        $this->makeTeam('Armand', sessionsPerWeek: 2);
        $this->makeTeam('Armund', sessionsPerWeek: 2);
        $this->scopeGucToClub($this->club->getId());
        $teamsBefore = $this->rowCount('team');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Palais Neuf', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['Armond']],
        ]);
        $decisions = [['section' => 'equipes', 'index' => 0, 'coche' => true]];

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(422, $status, 'un libellé ambigu coché sans choix est refusé');
        self::assertStringContainsString('équipe', implode(' ', $body['errors'] ?? []), 'le 422 nomme la ligne équipe fautive');
        self::assertSame($teamsBefore, $this->rowCount('team'), 'rien écrit : aucun doublon « Armond » créé');
    }

    public function testAnAmbiguousTeamLineResolvedToAChosenCandidateImportsAsAMatchMovingOnlySessions(): void
    {
        // Le gestionnaire TRANCHE : il choisit « Armand » parmi les candidats (cibleId) et corrige les
        // séances. La ligne s'importe en APPARIEMENT — aucune création, SEULES les séances bougent.
        $armand = $this->makeTeam('Armand', sessionsPerWeek: 2);
        $this->makeTeam('Armund', sessionsPerWeek: 2);
        $this->scopeGucToClub($this->club->getId());
        $teamsBefore = $this->rowCount('team');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Palais Neuf', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['Armond']],
        ]);
        $decisions = [['section' => 'equipes', 'index' => 0, 'coche' => true, 'cibleId' => $armand->getId(), 'corrections' => ['seances' => 3]]];

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(200, $status, 'un ambigu tranché s\'importe : ' . json_encode($body['errors'] ?? [], \JSON_UNESCAPED_UNICODE));
        self::assertSame($teamsBefore, $this->rowCount('team'), 'appariement : aucune équipe créée');

        $this->em->clear();
        $reloaded = $this->em->getRepository(Team::class)->find($armand->getId());
        self::assertInstanceOf(Team::class, $reloaded);
        self::assertSame(3, $reloaded->getSessionsPerWeek(), 'seules les séances de l\'équipe choisie bougent');
    }

    public function testACibleIdFromAnotherClubOnAnAmbiguousTeamLineIsRefusedWritingNothing(): void
    {
        // Abus (patron A1 étendu à la ligne équipe) — sur une ligne ambiguë, un cibleId pointant
        // l'équipe d'un AUTRE club ne doit JAMAIS être écrit : re-vérification tenant (ownedTeam),
        // 422, rien écrit.
        $this->makeTeam('Armand', sessionsPerWeek: 2);
        $this->makeTeam('Armund', sessionsPerWeek: 2);
        $foreign = $this->seedForeignClubWithTeam('Armand');
        $this->scopeGucToClub($this->club->getId());
        $teamsBefore = $this->rowCount('team');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Palais Neuf', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['Armond']],
        ]);
        $decisions = [['section' => 'equipes', 'index' => 0, 'coche' => true, 'cibleId' => $foreign['teamId']]];

        [$status] = $this->import($parc, $decisions);
        self::assertSame(422, $status, 'un cibleId d\'équipe hors tenant est refusé');
        self::assertSame($teamsBefore, $this->rowCount('team'), 'rien écrit avec une cible hors tenant');
    }

    // ───────────────────────── Écart au plan (contrat NEO, 3a) — « Créer » explicite sur un ambigu ─────────────────────────
    // L'aperçu refusait un libellé AMBIGU coché sans cible (doublon silencieux interdit). Il manque le
    // geste symétrique : le gestionnaire ASSUME la création (« Créer « X » » dans le sélecteur). Signal
    // de création EXPLICITE nommé `creer: true` (top-level de la décision, frère de `coche`/`cibleId`) :
    // il lève le refus d'ambiguïté et crée l'entité. Pour les ÉQUIPES la garde d'ambiguïté existe déjà
    // (→ ce test est ROUGE tant que NEO n'honore pas `creer`) ; pour les GYMNASES NEO doit AUSSI poser la
    // garde (refus sans signal), sans quoi `creer` serait un no-op silencieux.

    public function testACheckedTeamLineWithAnAmbiguousLabelAndAnExplicitCreateDecisionCreatesANewTeam(): void
    {
        // « Armond » est ambigu (seuil flou de « Armand » ET « Armund »). Décoché/sans cible il serait
        // refusé (cf. testACheckedTeamLineWithAnAmbiguousLabelAndNoChoiceIsRefusedWritingNothing). Le
        // gestionnaire a choisi « Créer « Armond » » → `creer: true` : l'équipe est CRÉÉE (3e équipe),
        // jamais fondue dans un candidat, sans 422.
        $this->makeTeam('Armand', sessionsPerWeek: 2);
        $this->makeTeam('Armund', sessionsPerWeek: 2);
        $this->scopeGucToClub($this->club->getId());
        $teamsBefore = $this->rowCount('team');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Palais Neuf', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['Armond']],
        ]);
        // PR2 — « Armond » ne porte aucun marqueur de genre : la création explicite exige un genre
        // (obligatoire). On tranche MIXTE ; le test porte sur « Créer » explicite, pas sur le genre.
        $decisions = [['section' => 'equipes', 'index' => 0, 'coche' => true, 'creer' => true, 'corrections' => ['genre' => 'MIXTE']]];

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(200, $status, 'un ambigu avec « Créer » explicite s\'importe : ' . json_encode($body['errors'] ?? [], \JSON_UNESCAPED_UNICODE));
        self::assertSame($teamsBefore + 1, $this->rowCount('team'), '« Créer » explicite crée la nouvelle équipe');
        self::assertNotNull(
            $this->em->getRepository(Team::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'Armond']),
            'l\'équipe « Armond » naît telle quelle (jamais appariée à un candidat)',
        );
    }

    public function testACheckedGymnaseLineWithAnAmbiguousLabelAndNoChoiceIsRefusedWritingNothing(): void
    {
        // Symétrie gymnase du refus d'ambiguïté : « Mateo » tombe dans le seuil flou de « Mateo Un » ET
        // « Mateo Deux » (deux candidats) → coché SANS cible ni signal, il ne doit JAMAIS créer un
        // doublon en silence (422, RIEN écrit). ROUGE aujourd'hui : l'executor n'a aucune garde
        // d'ambiguïté côté gymnases, il crée « Mateo » en silence (NEO doit poser `labelIsVenueAmbiguous`).
        $this->makeVenue('Mateo Un');
        $this->makeVenue('Mateo Deux');
        $this->scopeGucToClub($this->club->getId());
        $venuesBefore = $this->rowCount('venue');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Mateo', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => []],
        ]);
        $decisions = [['section' => 'gymnases', 'index' => 0, 'coche' => true]];

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(422, $status, 'un gymnase ambigu coché sans choix est refusé');
        self::assertNotEmpty($body['errors'] ?? [], 'le 422 nomme la ligne gymnase fautive');
        self::assertSame($venuesBefore, $this->rowCount('venue'), 'rien écrit : aucun doublon « Mateo » créé');
    }

    public function testACheckedGymnaseLineWithAnAmbiguousLabelAndAnExplicitCreateDecisionCreatesANewVenue(): void
    {
        // L'échappatoire assumée côté gymnase : « Mateo » ambigu, mais le gestionnaire a choisi
        // « Créer « Mateo » » → `creer: true` crée le gymnase, sans 422. Jumeau de falsification de
        // testACheckedGymnaseLineWithAnAmbiguousLabelAndNoChoiceIsRefusedWritingNothing (sans la garde
        // d'ambiguïté que NEO pose, ce test est vert vacuement ; il PINCE l'échappatoire une fois la
        // garde en place — une garde trop zélée qui refuserait aussi `creer` le ferait ROUGIR).
        $this->makeVenue('Mateo Un');
        $this->makeVenue('Mateo Deux');
        $this->scopeGucToClub($this->club->getId());
        $venuesBefore = $this->rowCount('venue');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Mateo', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => []],
        ]);
        $decisions = [['section' => 'gymnases', 'index' => 0, 'coche' => true, 'creer' => true]];

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(200, $status, 'un gymnase ambigu avec « Créer » explicite s\'importe : ' . json_encode($body['errors'] ?? [], \JSON_UNESCAPED_UNICODE));
        self::assertSame($venuesBefore + 1, $this->rowCount('venue'), '« Créer » explicite crée le nouveau gymnase');
        self::assertNotNull(
            $this->em->getRepository(Venue::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'Mateo']),
            'le gymnase « Mateo » naît tel quel (jamais apparié à un candidat)',
        );
    }

    // ───────────────────────── Abus — noms hostiles & bornes du contrôleur d'exécution ─────────────────────────

    public function testHostileFormulaNamesAreNeutralizedBeforeBeingWritten(): void
    {
        // Abus export XLSX — un nom qui COMMENCE par =/+/-/@ (ou tab/CRLF) deviendrait une FORMULE
        // vivante à l'ouverture de l'export. L'import NEUTRALISE le préfixe avant d'écrire : l'équipe
        // ET le gymnase créés portent le nom DÉSAMORCÉ, jamais le littéral hostile.
        $this->scopeGucToClub($this->club->getId());

        $parc = $this->parcWithCases([
            ['gymnase' => '+Gymnase Piège', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['=SOMME(A1:A2)']],
        ]);
        // PR2 — le nom neutralisé « SOMME(A1:A2) » ne porte aucun marqueur de genre : la création
        // exige un genre (obligatoire). On tranche MIXTE à l'aperçu ; le test porte sur la NEUTRALISATION,
        // pas sur le genre.
        $decisions = [
            ['section' => 'gymnases', 'index' => 0, 'coche' => true],
            ['section' => 'equipes', 'index' => 0, 'coche' => true, 'corrections' => ['genre' => 'MIXTE']],
        ];

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(200, $status, 'l\'import aboutit : ' . json_encode($body['errors'] ?? [], \JSON_UNESCAPED_UNICODE));

        self::assertNull($this->em->getRepository(Team::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => '=SOMME(A1:A2)']), 'jamais le littéral hostile comme équipe');
        self::assertNotNull($this->em->getRepository(Team::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'SOMME(A1:A2)']), 'l\'équipe désamorcée est stockée');
        self::assertNull($this->em->getRepository(Venue::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => '+Gymnase Piège']), 'jamais le littéral hostile comme gymnase');
        self::assertNotNull($this->em->getRepository(Venue::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'Gymnase Piège']), 'le gymnase désamorcé est stocké');
    }

    public function testAnOversizedExecutionBodyIsRefusedBeforeParsing(): void
    {
        // Abus (parité avec l'analyse) — un corps d'IMPORT > 256 Ko est refusé (413) AVANT tout parse,
        // rien écrit.
        $this->scopeGucToClub($this->club->getId());
        $teamsBefore = $this->rowCount('team');

        $huge = str_repeat('x', 256 * 1024 + 1);
        $body = '{"payload":{"format":"parc-v2","gymnases":[],"cases":[],"pad":"' . $huge . '"},"decisions":[]}';

        [$status] = $this->importRaw($body);
        self::assertSame(413, $status, 'un corps d\'import > 256 Ko doit être refusé (413)');
        self::assertSame($teamsBefore, $this->rowCount('team'), 'rien écrit');
    }

    public function testExecutionRefuses501CasesAtTheBound(): void
    {
        // Abus (parité avec l'analyse) — 501 cases à l'import dépasse la borne (≤ 500) → 422 nommé.
        $this->scopeGucToClub($this->club->getId());

        $cases = array_map(
            static fn (int $i): array => ['gymnase' => 'MATEO', 'jour' => (($i % 5) + 1), 'debut' => '18:00', 'duree' => 90, 'equipes' => ['E' . $i]],
            range(1, 501),
        );
        $parc = ['format' => 'parc-v2', 'gymnases' => [['nom' => 'MATEO']], 'cases' => $cases];

        [$status] = $this->import($parc, []);
        self::assertSame(422, $status, '501 cases à l\'import doit être refusé (borne ≤ 500)');
    }

    // ───────────────────────── PR A (incident ALAP) — redéfinir les équipes d'une séance commune (corrections.equipes) ─────────────────────────
    // Incident ALAP 2026-10-29 : la case « SF1 + SM shooting » cite « SM shooting » (en fait le NOM du
    // groupe, lu comme une équipe). Le gestionnaire redéfinit la composition réelle via le nouveau
    // canal `corrections.equipes` d'une décision de séance commune : une liste ≥ 2 d'entrées, chacune
    // un UUID d'équipe EXISTANTE du club OU `import:<index>` vers une ligne Équipes gardée (créée dans
    // ce run). Il est honoré à l'import : le bloc est créé avec sa réservation de groupe. ROUGE
    // aujourd'hui : {@see ParcImportExecutor::importBlocks} résout les membres depuis `entry['equipes']`
    // SEULEMENT → « SM shooting » introuvable → 422 (le défaut de l'incident).

    public function testAMutualisationCorrectedWithTeamUuidsCreatesTheBlockWithItsGroupReservation(): void
    {
        $sf1 = $this->makeTeam('SF1', sessionsPerWeek: 2);
        $sm1 = $this->makeTeam('SM1', sessionsPerWeek: 2);
        $sm2 = $this->makeTeam('SM2', sessionsPerWeek: 2);
        $venue = $this->makeVenue('Léon Jouhaux', canSplit: true);
        $this->makeSlot($venue->getId(), 1, '20:30', capacity: 3);
        $this->scopeGucToClub($this->club->getId());
        $blocksBefore = $this->rowCount('shared_training_block');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Léon Jouhaux', 'jour' => 1, 'debut' => '20:30', 'duree' => 90, 'equipes' => ['SF1', 'SM shooting'], 'groupe' => 'SM Shooting'],
        ]);
        // La composition réelle redéfinie à la main (SF1 + SM1 + SM2) remplace les équipes lues de la case.
        $decisions = [['section' => 'mutualisations', 'index' => 0, 'coche' => true, 'corrections' => ['equipes' => [$sf1->getId(), $sm1->getId(), $sm2->getId()]]]];

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(200, $status, 'une séance commune à composition corrigée s\'importe : ' . json_encode($body['errors'] ?? [], \JSON_UNESCAPED_UNICODE));
        self::assertSame($blocksBefore + 1, $this->rowCount('shared_training_block'), 'le bloc de la composition corrigée est créé');

        $block = $this->em->getRepository(SharedTrainingBlock::class)->findOneBy(['clubId' => $this->club->getId()], ['createdAt' => 'DESC']);
        self::assertInstanceOf(SharedTrainingBlock::class, $block);
        self::assertSame('SM Shooting', $block->getLabel(), 'le groupe de la case nomme le bloc');

        $memberIds = array_map(
            static fn (SharedTrainingBlockTeam $m): string => $m->getTeamId(),
            $this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['clubId' => $this->club->getId(), 'blockId' => $block->getId()]),
        );
        self::assertEqualsCanonicalizing([$sf1->getId(), $sm1->getId(), $sm2->getId()], $memberIds, 'le bloc regroupe EXACTEMENT les 3 équipes corrigées, jamais « SM shooting »');
        self::assertSame(3, $this->reservationCountOnVenue($venue->getId()), 'la réservation de groupe des 3 membres est posée');
    }

    public function testAMutualisationCorrectionCanReferenceAKeptTeamLineByImportIndex(): void
    {
        // Jumeau du test ci-dessus avec des membres CRÉÉS dans le même import : SF1 existe, SM1 & SM2
        // naissent de leurs propres cases (lignes Équipes 0 et 1 gardées) et sont référencées par
        // `import:0` / `import:1`. Le bloc regroupe les trois. ROUGE aujourd'hui (canal inexistant).
        $sf1 = $this->makeTeam('SF1', sessionsPerWeek: 2);
        $venue = $this->makeVenue('Léon Jouhaux', canSplit: true);
        $this->makeSlot($venue->getId(), 1, '20:30', capacity: 3);
        $this->scopeGucToClub($this->club->getId());

        $parc = $this->parcWithCases([
            ['gymnase' => 'MATEO', 'jour' => 2, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],                                               // ligne Équipes 0
            ['gymnase' => 'MATEO', 'jour' => 3, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM2']],                                               // ligne Équipes 1
            ['gymnase' => 'Léon Jouhaux', 'jour' => 1, 'debut' => '20:30', 'duree' => 90, 'equipes' => ['SF1', 'SM shooting'], 'groupe' => 'SM Shooting'], // séance commune 0
        ]);
        $decisions = [
            ['section' => 'equipes', 'index' => 0, 'coche' => true], // crée SM1 (genre M dérivé)
            ['section' => 'equipes', 'index' => 1, 'coche' => true], // crée SM2
            ['section' => 'mutualisations', 'index' => 0, 'coche' => true, 'corrections' => ['equipes' => [$sf1->getId(), 'import:0', 'import:1']]],
        ];

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(200, $status, 'une composition référençant des lignes Équipes gardées s\'importe : ' . json_encode($body['errors'] ?? [], \JSON_UNESCAPED_UNICODE));

        $sm1 = $this->em->getRepository(Team::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'SM1']);
        $sm2 = $this->em->getRepository(Team::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'SM2']);
        self::assertInstanceOf(Team::class, $sm1);
        self::assertInstanceOf(Team::class, $sm2);

        $block = $this->em->getRepository(SharedTrainingBlock::class)->findOneBy(['clubId' => $this->club->getId()], ['createdAt' => 'DESC']);
        self::assertInstanceOf(SharedTrainingBlock::class, $block);
        $memberIds = array_map(
            static fn (SharedTrainingBlockTeam $m): string => $m->getTeamId(),
            $this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['clubId' => $this->club->getId(), 'blockId' => $block->getId()]),
        );
        self::assertEqualsCanonicalizing([$sf1->getId(), $sm1->getId(), $sm2->getId()], $memberIds, '`import:<index>` résout vers les équipes créées dans ce run');
    }

    // ───────────────────────── PR A — NR TENANT (axe §7.1) sur le canal corrections.equipes ─────────────────────────

    public function testAMutualisationCorrectionWithATeamFromAnotherClubIsRefusedWritingNothing(): void
    {
        // Axe §7.1 (tenant isolation) — patron A1 étendu au nouveau canal `corrections.equipes` : un
        // UUID d'équipe d'un AUTRE club ne doit JAMAIS entrer dans un bloc. La garde est `ownedTeam`
        // ({@see ParcImportExecutor::ownedTeam}, :740-748) : 422, RIEN écrit. ROUGE AUJOURD'HUI pour la
        // BONNE raison : le canal n'existe pas, donc `corrections.equipes` est ignoré et l'import
        // RÉUSSIT en résolvant les équipes de la case (SF1 + SM1 existantes) → un bloc EST écrit (200).
        // ⚠ Falsification à jouer APRÈS implémentation (option A) : désactiver `ownedTeam` doit faire
        // ROUGIR ce test (l'équipe étrangère entrerait alors dans un bloc). FALSIFIÉ par NEO 2026-10-30 :
        // garde tenant de `ownedTeam` neutralisée → ce test ROUGIT (200 au lieu de 422, le bloc naît avec
        // l'équipe étrangère) ; garde restaurée → VERT. La garde est donc atteinte et efficace. Patron des NR existants :
        // testCibleIdFromAnotherClubIsRefusedWithoutWriting (:540-563), testACibleIdFromAnotherClubOnAnAmbiguousTeamLine (:763-782).
        $sf1 = $this->makeTeam('SF1', sessionsPerWeek: 2);
        $this->makeTeam('SM1', sessionsPerWeek: 2);
        $foreign = $this->seedForeignClubWithTeam('Étrangère');
        $venue = $this->makeVenue('Léon Jouhaux', canSplit: true);
        $this->makeSlot($venue->getId(), 1, '20:30', capacity: 2);
        $this->scopeGucToClub($this->club->getId());
        $blocksBefore = $this->rowCount('shared_training_block');
        $reservationsBefore = $this->rowCount('reservation');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Léon Jouhaux', 'jour' => 1, 'debut' => '20:30', 'duree' => 90, 'equipes' => ['SF1', 'SM1'], 'groupe' => 'SM Shooting'],
        ]);
        $decisions = [['section' => 'mutualisations', 'index' => 0, 'coche' => true, 'corrections' => ['equipes' => [$foreign['teamId'], $sf1->getId()]]]];

        [$status] = $this->import($parc, $decisions);
        self::assertSame(422, $status, 'une composition corrigée citant une équipe hors tenant est refusée');
        self::assertSame($blocksBefore, $this->rowCount('shared_training_block'), 'rien écrit : aucun bloc avec une équipe étrangère');
        self::assertSame($reservationsBefore, $this->rowCount('reservation'), 'aucune réservation écrite non plus (tout-ou-rien)');
    }

    // ───────────────────────── PR A — abus du canal corrections.equipes ─────────────────────────

    public function testInvalidTeamCorrectionsAreRefusedWritingNothing(): void
    {
        // Abus — une composition corrigée invalide ne doit JAMAIS écrire : (a) moins de 2 entrées ;
        // (b) un id forgé (aucune équipe) ; (c) un `import:<index>` hors bornes. Chacune → 422, rien
        // écrit. ROUGE aujourd'hui : le canal n'existe pas, `corrections.equipes` est ignoré et l'import
        // RÉUSSIT (200) en créant le bloc des équipes de la case.
        $sf1 = $this->makeTeam('SF1', sessionsPerWeek: 2);
        $this->makeTeam('SM1', sessionsPerWeek: 2);
        $venue = $this->makeVenue('Léon Jouhaux', canSplit: true);
        $this->makeSlot($venue->getId(), 1, '20:30', capacity: 2);
        $this->scopeGucToClub($this->club->getId());
        $blocksBefore = $this->rowCount('shared_training_block');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Léon Jouhaux', 'jour' => 1, 'debut' => '20:30', 'duree' => 90, 'equipes' => ['SF1', 'SM1'], 'groupe' => 'SM Shooting'],
        ]);

        $invalidCompositions = [
            'moins de 2 entrées' => [$sf1->getId()],
            'un id forgé (aucune équipe)' => [$sf1->getId(), $this->uuid()],
            'un import:<index> hors bornes' => [$sf1->getId(), 'import:99'],
        ];

        foreach ($invalidCompositions as $why => $equipes) {
            $this->scopeGucToClub($this->club->getId());
            $decisions = [['section' => 'mutualisations', 'index' => 0, 'coche' => true, 'corrections' => ['equipes' => $equipes]]];
            [$status] = $this->import($parc, $decisions);
            self::assertSame(422, $status, \sprintf('composition « %s » refusée', $why));
            self::assertSame($blocksBefore, $this->rowCount('shared_training_block'), \sprintf('composition « %s » : rien écrit', $why));
        }
    }

    // ───────────────────────── PR A (D4 + D7) — 422 de résolution AGRÉGÉ, « séance commune » ─────────────────────────
    // Le refus de RÉSOLUTION (équipe/gymnase introuvable des lignes cochées, toutes sections) agrège
    // TOUTES les fautes en UNE passe AVANT toute écriture, chaque message nommant l'équipe ET la case.
    // (Les gardes d'ÉCRITURE — occupation, cap, chevauchement — restent fail-fast, non agrégées.)
    // Et le libellé « séance commune n°X » remplace « mutualisation n°X » (les CLÉS du contrat restent
    // `mutualisations`). Deux angles VOULUS du même domaine sensible (pipeline d'import) : l'agrégation
    // (testTheResolutionRefusalAggregatesFaultsAcrossSectionsNamingTeamAndCase) ⇄ le nommage des lignes
    // (testSeanceCommuneFaultNamesItsLineNeverByNumber, testReservationFaultNamesItsLineNeverByNumber,
    // E3). Jumeau ANALYSE : ParcImportAnalyzeTest.

    public function testTheResolutionRefusalAggregatesFaultsAcrossSectionsNamingTeamAndCase(): void
    {
        // SF1 existe ; « SM shooting » (cité par la séance commune) et « U13M1 » (cité par une
        // réservation) sont IGNORÉS à l'étape Équipes (lignes non cochées) → introuvables à l'écriture.
        // DEUX fautes, deux sections → `errors` à 2 entrées, chacune nommant équipe + case ; rien écrit.
        // (La case 0 est une séance commune : elle ne dérive AUCUNE réservation ; la seule réservation
        // dérivée est celle de la case 1, « U13M1 », à l'index 0.)
        $this->makeTeam('SF1', sessionsPerWeek: 2);
        $venue = $this->makeVenue('Léon Jouhaux', canSplit: true);
        $this->makeSlot($venue->getId(), 1, '20:30', capacity: 2);
        $this->scopeGucToClub($this->club->getId());
        $teamsBefore = $this->rowCount('team');
        $blocksBefore = $this->rowCount('shared_training_block');
        $reservationsBefore = $this->rowCount('reservation');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Léon Jouhaux', 'jour' => 1, 'debut' => '20:30', 'duree' => 90, 'equipes' => ['SF1', 'SM shooting'], 'groupe' => 'SM Shooting'],
            ['gymnase' => 'MATEO', 'jour' => 2, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['U13M1']],
        ]);
        // On coche la séance commune ET la réservation, mais PAS les équipes (« SM shooting »/« U13M1 »
        // restent ignorées → introuvables à l'écriture).
        $decisions = [
            ['section' => 'mutualisations', 'index' => 0, 'coche' => true],
            ['section' => 'reservations', 'index' => 0, 'coche' => true],
        ];

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(422, $status);
        $errors = $body['errors'] ?? [];
        self::assertCount(2, $errors, 'les fautes de résolution des deux sections sont agrégées en UNE passe');
        $joined = implode(' || ', $errors);
        self::assertStringContainsString('SM shooting', $joined, 'une faute nomme l\'équipe de la séance commune');
        self::assertStringContainsString('U13M1', $joined, 'l\'autre faute nomme l\'équipe de la réservation');
        // E3 — chaque faute agrégée NOMME sa ligne (type + case + gymnase), jamais « n°X ».
        self::assertStringNotContainsString('n°', $joined, 'E3 : plus jamais « n°X » dans un message agrégé');
        self::assertStringContainsString('Séance commune', $joined, 'E3 : la faute de bloc se nomme « Séance commune … »');
        self::assertStringContainsString('Réservation', $joined, 'E3 : la faute de réservation se nomme « Réservation … »');
        self::assertStringContainsString('Léon Jouhaux', $joined, 'E3 : le gymnase de la case est nommé');

        self::assertSame($teamsBefore, $this->rowCount('team'), 'tout-ou-rien : aucune équipe écrite');
        self::assertSame($blocksBefore, $this->rowCount('shared_training_block'), 'tout-ou-rien : aucun bloc écrit');
        self::assertSame($reservationsBefore, $this->rowCount('reservation'), 'tout-ou-rien : aucune réservation écrite');
    }

    public function testSeanceCommuneFaultNamesItsLineNeverByNumber(): void
    {
        // E3 (ex-D7, ADAPTÉ — je suis propriétaire de ce test) — une faute de séance commune se NOMME
        // « Séance commune SF1 + SM shooting · lundi 20:30 · Léon Jouhaux », jamais « séance commune n°X »
        // ni « Mutualisation n°X ». Heure au format HH:MM. Les CLÉS du contrat (`mutualisations`) ne
        // changent pas. ROUGE sur fc13679db : {@see ParcImportExecutor::fail} préfixe encore « %s n°%d ».
        $this->makeTeam('SF1', sessionsPerWeek: 2);
        $this->scopeGucToClub($this->club->getId());

        $parc = $this->parcWithCases([
            ['gymnase' => 'Léon Jouhaux', 'jour' => 1, 'debut' => '20:30', 'duree' => 90, 'equipes' => ['SF1', 'SM shooting'], 'groupe' => 'SM Shooting'],
        ]);
        $decisions = [['section' => 'mutualisations', 'index' => 0, 'coche' => true]]; // « SM shooting » non cochée → introuvable

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(422, $status);
        $joined = implode(' || ', $body['errors'] ?? []);
        self::assertStringContainsString('Séance commune', $joined, 'E3 : la ligne se nomme « Séance commune … »');
        self::assertStringContainsString('SM shooting', $joined, 'le message NOMME l\'équipe en cause (dans la composition)');
        self::assertStringContainsString('Léon Jouhaux', $joined, 'E3 : le gymnase de la case est nommé');
        self::assertStringContainsString('20:30', $joined, 'E3 : l\'heure est au format HH:MM');
        self::assertStringNotContainsString('n°', $joined, 'E3 : plus jamais « n°X » dans un message serveur');
    }

    public function testReservationFaultNamesItsLineNeverByNumber(): void
    {
        // E3 — une faute de réservation (équipe introuvable) se NOMME « Réservation U13M1 · … · Léon
        // Jouhaux », jamais « réservation n°X ». ROUGE sur fc13679db : {@see ParcImportExecutor::fail}.
        $this->scopeGucToClub($this->club->getId());

        $parc = $this->parcWithCases([
            ['gymnase' => 'Léon Jouhaux', 'jour' => 2, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['U13M1']],
        ]);
        // « U13M1 » non cochée à l'étape Équipes → introuvable à l'écriture de SA réservation (cochée).
        $decisions = [['section' => 'reservations', 'index' => 0, 'coche' => true]];

        [$status, $body] = $this->import($parc, $decisions);
        self::assertSame(422, $status);
        $joined = implode(' || ', $body['errors'] ?? []);
        self::assertStringContainsString('Réservation', $joined, 'E3 : la ligne se nomme « Réservation … »');
        self::assertStringContainsString('U13M1', $joined, 'E3 : l\'équipe est nommée');
        self::assertStringContainsString('Léon Jouhaux', $joined, 'E3 : le gymnase est nommé');
        self::assertStringContainsString('18:00', $joined, 'E3 : l\'heure est au format HH:MM');
        self::assertStringNotContainsString('n°', $joined, 'E3 : plus jamais « n°X » dans un message serveur');
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
     * Un parc-v2 vide recevable — chaque test remplit ses cases.
     *
     * @return array<string, mixed>
     */
    private function emptyParc(): array
    {
        return ['format' => 'parc-v2', 'gymnases' => [], 'cases' => []];
    }

    /**
     * @param list<array<string, mixed>> $cases
     *
     * @return array<string, mixed>
     */
    private function parcWithCases(array $cases): array
    {
        $parc = $this->emptyParc();
        $parc['cases'] = $cases;
        $gyms = [];
        foreach ($cases as $case) {
            $gyms[(string) $case['gymnase']] = true;
        }
        $parc['gymnases'] = array_map(static fn (string $nom): array => ['nom' => $nom], array_keys($gyms));

        return $parc;
    }

    /**
     * @return array<string, mixed>
     */
    private function oneTeamCaseParc(): array
    {
        return $this->parcWithCases([['gymnase' => 'Palais Neuf', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']]]);
    }

    /**
     * Charge une sortie de chatbot réelle (fixtures/parc) comme payload parc-v2.
     *
     * @return array<string, mixed>
     */
    private function fixtureParc(string $name): array
    {
        $raw = (string) file_get_contents(__DIR__ . '/../../Fixtures/parc/' . $name);
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'le fixture ' . $name . ' doit décoder en objet');

        return $decoded;
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
            ['HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->token), 'CONTENT_TYPE' => 'application/json', 'HTTP_X_SEASON_ID' => $this->season->getId()],
            $body,
        );
        $response = $this->client->getResponse();
        $decoded = json_decode((string) $response->getContent(), true);

        return [$response->getStatusCode(), \is_array($decoded) ? $decoded : []];
    }

    /**
     * POST d'un corps BRUT sur l'endpoint d'import (pour les bornes octets : le contrôleur refuse le
     * corps AVANT tout parse).
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function importRaw(string $body): array
    {
        $this->client->request(
            'POST',
            '/api/clubs/' . $this->club->getId() . '/parc-import',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token, 'CONTENT_TYPE' => 'application/json', 'HTTP_X_SEASON_ID' => $this->season->getId()],
            $body,
        );
        $response = $this->client->getResponse();
        $decoded = json_decode((string) $response->getContent(), true);

        return [$response->getStatusCode(), \is_array($decoded) ? $decoded : []];
    }

    /**
     * Analyse d'abord le parc pour connaître les lignes dérivées, coche toutes celles « à créer », puis
     * importe — le geste réel du gestionnaire sur un parc entièrement neuf.
     *
     * @param array<string, mixed> $parc
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function importAllCreatable(array $parc): array
    {
        // On passe par l'analyse HTTP pour obtenir les statuts dérivés et cocher les « à créer ».
        $this->client->request(
            'POST',
            '/api/clubs/' . $this->club->getId() . '/parc-import/analyze',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token, 'CONTENT_TYPE' => 'application/json', 'HTTP_X_SEASON_ID' => $this->season->getId()],
            (string) json_encode($parc, \JSON_THROW_ON_ERROR),
        );
        $analyze = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($analyze);

        $decisions = [];
        foreach ((array) ($analyze['sections'] ?? []) as $section => $lines) {
            foreach (\is_array($lines) ? $lines : [] as $i => $line) {
                if (!\is_array($line) || 'aCreer' !== ($line['statut'] ?? null)) {
                    continue;
                }
                $decision = ['section' => $section, 'index' => $i, 'coche' => true];
                // PR2 — le genre est OBLIGATOIRE à la création d'une équipe : on tranche l'aperçu comme
                // le gestionnaire (le genre suggéré s'il y en a un, sinon MIXTE à défaut de motif clair).
                if ('equipes' === $section) {
                    $genre = \is_string($line['suggestedGender'] ?? null) ? $line['suggestedGender'] : 'MIXTE';
                    $decision['corrections'] = ['genre' => $genre];
                }
                $decisions[] = $decision;
            }
        }

        return $this->import($parc, $decisions);
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

    private function makeSlot(string $venueId, int $day, string $start, int $capacity = 1): void
    {
        $this->scopeGucToClub($this->club->getId());
        $slot = (new VenueTrainingSlot)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setVenueId($venueId)
            ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($start))->setDurationMinutes(90)->setCapacity($capacity);
        $this->em->persist($slot);
        $this->em->flush();
    }

    private function makeTeam(string $name, int $sessionsPerWeek): Team
    {
        $this->scopeGucToClub($this->club->getId());
        $team = (new Team)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId($this->uuid())->setPriorityTierId(3)
            ->setName($name)->setSessionsPerWeek($sessionsPerWeek)->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
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

    /**
     * Un club TIERS avec une équipe — pour prouver qu'un `cibleId` d'équipe hors tenant est refusé.
     *
     * @return array{teamId: string}
     */
    private function seedForeignClubWithTeam(string $teamName): array
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

        $team = (new Team)
            ->setClubId($otherClub->getId())->setSeasonId($otherSeason->getId())
            ->setSportCategoryId($this->uuid())->setPriorityTierId(3)
            ->setName($teamName)->setSessionsPerWeek(2)->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        return ['teamId' => $team->getId()];
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

    private function reservationCountOnVenue(string $venueId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM reservation WHERE club_id = :c AND venue_id = :v',
            ['c' => $this->club->getId(), 'v' => $venueId],
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

    /**
     * Les 5 rangs de référence (mapping canonique label→id), idempotents : `priority_tier` est GLOBAL.
     * Patron {@see FfbbExcelImporterTest::seedPriorityTiers}.
     */
    private function seedPriorityTiers(): void
    {
        foreach ([[1, 'S', 'Elite'], [2, 'A', 'Régional+'], [3, 'B', 'Régional'], [4, 'C', 'Départemental'], [5, 'D', 'Loisir']] as [$id, $label, $name]) {
            if ($this->em->getRepository(PriorityTier::class)->find($id) instanceof PriorityTier) {
                continue;
            }
            $tier = (new PriorityTier)->setId($id)->setLabel($label)->setName($name)
                ->setColor('#000000')->setOrToolsWeight(1)->setDefaultMinSessions(1);
            $this->em->persist($tier);
        }
        $this->em->flush();
    }

    private function tierIdForLabel(string $label): int
    {
        $tier = $this->em->getRepository(PriorityTier::class)->findOneBy(['label' => $label]);
        self::assertInstanceOf(PriorityTier::class, $tier, \sprintf('le rang « %s » doit être seedé', $label));

        return $tier->getId();
    }
}
