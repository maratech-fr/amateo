<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\PriorityTier;
use App\Entity\Reservation;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\SeasonStatus;
use App\Service\ParcImport\ParcImportGate;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\Integration\Service\FfbbExcelImporterTest;
use App\Tests\TenantGucTrait;
use App\Tests\Unit\ParcImport\ParcPayloadValidatorTest;
use App\Tests\Unit\ParcImport\TeamLabelMatcherTest;
use App\Tests\Unit\ParcImport\TeamNameInterpreterTest;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot correctif import IA (« G2 ») PR1 — le DRY-RUN `POST /api/clubs/{id}/parc-import/analyze`,
 * RECALÉ sur le format `parc-v2`. Le gestionnaire colle le texte parc-v2 (une ligne par case) rendu
 * par son chatbot ; le serveur DÉRIVE équipes / séances / genre / catégorie / mutualisations /
 * réservations des cases, lui répond PAR SECTION le statut de chaque ligne dérivée (apparié /
 * suggéré / décoché / à créer / alerte) + un bloc de FIABILITÉ et des ALERTES transverses — et il
 * N'ÉCRIT RIEN.
 *
 * Contrat (défini ici, implémenté par NEO) :
 *  - MÊME gate que l'import ({@see ParcImportGate}) : 404 non-membre, 403
 *    non-gestionnaire, 409 socle EN VIGUEUR / saison archivée, 429 limiteur, bornes 256 Ko / ≤ 500 cases.
 *  - Réponse 200 :
 *      {format,
 *       fiabilite:{taux:float(0..1), anomalies:int, bandeau:bool},
 *       recap:{<section>:{total,apparie,suggere,aCreer,decoche,alerte}},
 *       sections:{<section>:[{index,libelle,statut,coche,cibleId,raison, groupe?, miseAJour?}]},
 *       alertes:[{type, message, …}]}.
 *    Réponse 422 (validateur) : {errors:[…]}.
 *  - D10 `bandeau` = résultat PEU FIABLE (jamais bloquant) : (apparié+suggéré) < 50 % OU ≥ 3 alertes.
 *  - D9 alertes transverses : une équipe dans DEUX endroits au même moment, une heure implausible
 *    (début hors [07:00, 23:30]), un gymnase DÉCLARÉ sans aucune case.
 *  - D3 contexte CORRECTEUR, jamais filtre : un gymnase connu CORRIGE le nommage, mais les gymnases
 *    détectés inconnus restent PROPOSÉS (jamais écartés).
 *  - D8 un `groupe` de case (« CEC ») nomme un bloc mutualisé — JAMAIS une équipe à créer.
 *
 * Les RÈGLES du format sont éprouvées au plus bas par {@see ParcPayloadValidatorTest} ; la déduction
 * nom → genre/catégorie par {@see TeamNameInterpreterTest} ; l'appariement
 * fuzzy par {@see TeamLabelMatcherTest}. ICI on prouve le CÂBLAGE, la
 * dérivation complète sur une sortie RÉELLE (t7), les alertes, la fiabilité, l'étanchéité tenant et
 * le NON-ÉCRIT.
 *
 * ⚠ Limiteur Redis keyé PAR UTILISATEUR : chaque test crée sa propre identité — aucun FLUSHALL.
 */
#[Group('integration')]
final class ParcImportAnalyzeTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const string FIXTURES = __DIR__ . '/../../Fixtures/parc/';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private User $user;

    private Season $season;

    private string $token;

    // ───────────────────────────── Gate ─────────────────────────────

    public function testNonMemberGets404WithTheClubNotFoundBody(): void
    {
        $stranger = '99999999-9999-4999-8999-999999999999';
        [$status, $body] = $this->analyze($this->validParc(), clubId: $stranger);

        self::assertSame(404, $status);
        self::assertSame(['error' => 'Club not found.'], $body);
    }

    public function testNonManagerIsRefused(): void
    {
        [$status, $body] = $this->analyze($this->validParc(), token: $this->viewerToken());

        self::assertSame(403, $status);
        self::assertSame(['error' => 'Forbidden.'], $body);
    }

    public function testSocleEnVigueurIsRefusedWith409(): void
    {
        $this->settleSeasonPlan($this->season);

        [$status] = $this->analyze($this->validParc());

        self::assertSame(409, $status);
    }

    public function testArchivedSeasonIsRefusedWith409(): void
    {
        $this->scopeGucToClub($this->club->getId());
        $old = (new Season)->setClubId($this->club->getId())->setName('2000-2001')
            ->setStartDate(new DateTimeImmutable('2000-07-15'))->setEndDate(new DateTimeImmutable('2001-07-14'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($old);
        $this->em->flush();

        [$status] = $this->analyze($this->validParc(), server: ['HTTP_X_SEASON_ID' => $old->getId()]);

        self::assertSame(409, $status);
    }

    // ───────────────────────────── Bornes & débit (abus) ─────────────────────────────

    public function testPayloadBeyondBoundsIsRefused(): void
    {
        // Octets : un corps > 256 Ko refusé (413) AVANT tout parse.
        $huge = str_repeat('x', 256 * 1024 + 1);
        [$oversized] = $this->analyzeRaw('{"format":"parc-v2","pad":"' . $huge . '"}');
        self::assertSame(413, $oversized, 'un corps > 256 Ko doit être refusé (413)');

        // Dénombrement : 501 cases dépasse la borne (≤ 500) → 422 nommé.
        $tooManyCases = $this->validParc();
        $tooManyCases['cases'] = array_map(
            static fn (int $i): array => ['gymnase' => 'MATEO', 'jour' => (($i % 5) + 1), 'debut' => '18:00', 'duree' => 90, 'equipes' => ['E' . $i]],
            range(1, 501),
        );
        [$casesStatus] = $this->analyze($tooManyCases);
        self::assertSame(422, $casesStatus, '501 cases doit être refusé (borne ≤ 500)');

        // Débit : le limiteur `parc_import` borne PAR UTILISATEUR — après la borne, 429.
        $accepted = 0;
        for ($i = 1; $i <= 30; ++$i) {
            [$status] = $this->analyze($this->validParc());
            if (429 === $status) {
                self::assertGreaterThan(0, $accepted, 'le limiteur doit laisser passer au moins une analyse avant de borner');

                return;
            }
            ++$accepted;
        }
        self::fail('le limiteur parc_import n\'a jamais rendu 429 en 30 analyses');
    }

    public function testBadFormatIsRefusedWith422NamingParcV2(): void
    {
        // CÂBLAGE du validateur à l'endpoint (règles au niveau unitaire). parc-v1 (l'ancien) → 422
        // nommant parc-v2, jamais un 200.
        $parc = $this->validParc();
        $parc['format'] = 'parc-v1';

        [$status, $body] = $this->analyze($parc);

        self::assertSame(422, $status);
        self::assertNotEmpty($body['errors'] ?? [], 'le 422 doit porter des erreurs nommées');
        self::assertStringContainsString('parc-v2', implode(' ', $body['errors']));
    }

    // ───────────────────────── D4 — la récupération atteint l'endpoint + payload NORMALISÉ renvoyé ─────────────────────────
    // Les RÈGLES de la passe de récupération (guillemets typographiques, bloc ```json) sont éprouvées
    // au plus bas par {@see ParcPayloadValidatorTest::testTypographicQuotesAreRecoveredBeforeParsing}
    // et {@see ParcPayloadValidatorTest::testAMarkdownFencedBlockIsUnwrappedBeforeParsing}. ICI on
    // prouve le CÂBLAGE : un collage que le serveur ne lit QUE grâce à `parse()` s'analyse (200), et la
    // réponse porte le payload NORMALISÉ (relu par `parse()`) que le front amorce + poste à l'import —
    // sans quoi le front re-parse le texte brut illisible et poste `payload: null` (défaut G2 D4).

    public function testATypographicQuotePasteAnalyzesAndReturnsTheNormalizedPayload(): void
    {
        // Le collage RÉEL d'un chatbot : le JSON parc-v2 avec des guillemets typographiques « ” » —
        // INVALIDE tel quel, relu uniquement par la passe de récupération. L'analyse aboutit (200) et
        // la réponse porte le payload NORMALISÉ (guillemets droits, cases présentes).
        $straight = json_encode($this->validParc(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        $typographic = str_replace('"', '”', (string) $straight);

        [$status, $body] = $this->analyzeRaw($typographic);

        self::assertSame(200, $status, 'un collage à guillemets typographiques, relu par la récupération, s\'analyse');
        self::assertArrayHasKey('payload', $body, 'la réponse porte le payload NORMALISÉ relu par parse()');
        self::assertSame('parc-v2', $body['payload']['format'] ?? null, 'le payload normalisé est du parc-v2');
        self::assertArrayHasKey('cases', (array) ($body['payload'] ?? []), 'le payload normalisé porte les cases');
        self::assertSame('MATEO', $body['payload']['cases'][0]['gymnase'] ?? null, 'la case normalisée a ses guillemets droits relus');
    }

    public function testAMarkdownFencedPasteAnalyzesAndReturnsTheNormalizedPayload(): void
    {
        // Un chatbot qui désobéit à « rends UNIQUEMENT le JSON » habille son parc-v2 d'un bloc ```json —
        // INVALIDE tel quel. Dé-habillé par la récupération, il s'analyse (200) et la réponse porte le
        // payload normalisé dé-habillé.
        $straight = json_encode($this->validParc(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        $fenced = "```json\n" . (string) $straight . "\n```";

        [$status, $body] = $this->analyzeRaw($fenced);

        self::assertSame(200, $status, 'un collage habillé d\'un bloc ```json, dé-habillé par la récupération, s\'analyse');
        self::assertArrayHasKey('payload', $body, 'la réponse porte le payload NORMALISÉ dé-habillé');
        self::assertSame('parc-v2', $body['payload']['format'] ?? null);
        self::assertNotEmpty($body['payload']['cases'] ?? [], 'le payload normalisé porte les cases dé-habillées');
    }

    public function testMatchingNeverReachesAnotherClub(): void
    {
        // Un AUTRE club possède un gymnase « Gymnase Mateo » et une équipe « SM1 ». L'analyse de NOTRE
        // club ne les apparie JAMAIS (filtre tenant) : la ligne ressort « à créer », jamais l'id hors tenant.
        $other = $this->seedForeignClubWithVenueAndTeam('Gymnase Mateo', 'SM1');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Gymnase Mateo', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
        ]);

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status);

        $gymLine = $body['sections']['gymnases'][0];
        self::assertSame('aCreer', $gymLine['statut'], 'un gymnase homonyme d\'un autre club ne s\'apparie pas');
        self::assertNotSame($other['venueId'], $gymLine['cibleId'] ?? null, 'jamais la cible d\'un autre club');

        $teamLine = $body['sections']['equipes'][0];
        self::assertSame('aCreer', $teamLine['statut'], 'une équipe homonyme d\'un autre club ne s\'apparie pas');
        self::assertNotSame($other['teamId'], $teamLine['cibleId'] ?? null, 'jamais la cible d\'un autre club');
    }

    public function testDryRunWritesNothing(): void
    {
        $this->scopeGucToClub($this->club->getId());
        $venuesBefore = $this->rowCount('venue');
        $teamsBefore = $this->rowCount('team');

        [$status] = $this->analyze($this->fixtureParc('t7.json'));
        self::assertSame(200, $status);

        self::assertSame($venuesBefore, $this->rowCount('venue'), 'le dry-run ne crée aucun gymnase');
        self::assertSame($teamsBefore, $this->rowCount('team'), 'le dry-run ne crée aucune équipe');
    }

    // ───────────────────────── Dérivation complète sur une sortie RÉELLE (t7) ─────────────────────────

    public function testDerivesEverySectionFromTheRealT7Output(): void
    {
        // t7 (GPT, image BCCL médiocre) : 73 cases, 9 gymnases. L'appli DÉRIVE toutes les sections —
        // chacune présente, avec un récap chiffré non vide.
        [$status, $body] = $this->analyze($this->fixtureParc('t7.json'));
        self::assertSame(200, $status, 'une sortie réelle parc-v2 s\'analyse');

        foreach (['gymnases', 'equipes', 'creneaux', 'reservations', 'mutualisations'] as $section) {
            self::assertArrayHasKey($section, $body['sections'], 'la section dérivée « ' . $section . ' » est présente');
            self::assertGreaterThan(0, $body['recap'][$section]['total'] ?? 0, 'la section « ' . $section . ' » dérive au moins une ligne');
        }

        // Une équipe au nom gendré est dérivée avec son genre (contrôle de bout en bout du dériveur).
        $teamLabels = array_map(static fn (array $l): string => (string) $l['libelle'], $body['sections']['equipes']);
        self::assertContains('SM2', $teamLabels, 'une équipe lue dans une case est dérivée');
    }

    public function testCecIsAGroupLabelNeverADerivedTeam(): void
    {
        // t7 : « CEC » ouvre deux cases du mercredi (groupes mutualisés). Il nomme le GROUPE, jamais
        // une équipe : aucune équipe dérivée « CEC », et une mutualisation porte « CEC » en label.
        [$status, $body] = $this->analyze($this->fixtureParc('t7.json'));
        self::assertSame(200, $status);

        $teamLabels = array_map(static fn (array $l): string => (string) $l['libelle'], $body['sections']['equipes']);
        self::assertNotContains('CEC', $teamLabels, '« CEC » n\'est jamais dérivé comme équipe');

        $groupeLabels = array_map(
            static fn (array $l): string => mb_strtolower((string) ($l['groupe'] ?? $l['libelle'] ?? '')),
            $body['sections']['mutualisations'],
        );
        self::assertContains('cec', $groupeLabels, '« CEC » nomme un bloc mutualisé dérivé');
    }

    public function testAMutualisationLineExposesTheResolvedVenueId(): void
    {
        // P4-363 — l'aperçu expose sur une ligne de mutualisation un `gymnaseId` résolu depuis le NOM
        // de la case (null si le gymnase est à créer) : il amorce le sélecteur de gymnase de la ligne,
        // SANS jamais confondre ce gymnase avec le `cibleId` (qui, pour une mutualisation, désigne le
        // bloc apparié). Une case pointe un gymnase EXISTANT, l'autre un gymnase inconnu.
        $venue = $this->makeVenue('Gymnase Mateo');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Gymnase Mateo', 'jour' => 3, 'debut' => '16:00', 'duree' => 90, 'equipes' => ['U9M1', 'U11F2'], 'groupe' => 'CEC'],
            ['gymnase' => 'Lieu Inconnu', 'jour' => 4, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['U13M1', 'U13M2'], 'groupe' => 'CTC'],
        ]);

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status);

        $mutualisations = $body['sections']['mutualisations'];
        self::assertCount(2, $mutualisations, 'une mutualisation par case à plusieurs équipes');
        $gymnaseIds = array_map(static fn (array $l): mixed => \array_key_exists('gymnaseId', $l) ? $l['gymnaseId'] : '__absent__', $mutualisations);
        self::assertContains($venue->getId(), $gymnaseIds, 'le gymnase résolu du nom de la case est exposé');
        self::assertContains(null, $gymnaseIds, 'un gymnase à créer → gymnaseId null (jamais absent)');
    }

    /**
     * Promesse : l'aperçu donne le VRAI nom du groupe existant (`blocLabel`), pas seulement celui du fichier,
     * pour que le champ s'amorce dessus. Jumeau : « renommage au réimport » de ParcImportModal.test.tsx.
     */
    public function testAnAppariedMutualisationCarriesTheRealNameOfItsBlock(): void
    {
        $a = $this->makeTeam('U9M1');
        $b = $this->makeTeam('U11F2');
        $a->setSessionsPerWeek(3);
        $b->setSessionsPerWeek(3);
        $this->makeBlock([$a, $b], 1)->setLabel('Loisirs');
        $this->em->flush();

        [$status, $body] = $this->analyze($this->parcWithCases([
            ['gymnase' => 'Gymnase Mateo', 'jour' => 3, 'debut' => '16:00', 'duree' => 90, 'equipes' => ['U9M1', 'U11F2'], 'groupe' => 'CEC'],
        ]));

        self::assertSame(200, $status);
        $line = $body['sections']['mutualisations'][0];
        self::assertSame('apparie', $line['statut']);
        self::assertSame('CEC', $line['groupe'], 'le nom lu reste servi tel quel');
        self::assertSame('Loisirs', $line['blocLabel'] ?? null, 'le nom réel du groupe existant');
    }

    /** Promesse : un groupe existant sans nom sert `blocLabel` null, pas le nom du fichier présenté comme le sien. */
    public function testAnAppariedMutualisationWithAnUnnamedBlockCarriesANullBlocLabel(): void
    {
        $a = $this->makeTeam('U9M1');
        $b = $this->makeTeam('U11F2');
        $a->setSessionsPerWeek(3);
        $b->setSessionsPerWeek(3);
        $this->makeBlock([$a, $b], 1)->setLabel(null);
        $this->em->flush();

        [$status, $body] = $this->analyze($this->parcWithCases([
            ['gymnase' => 'Gymnase Mateo', 'jour' => 3, 'debut' => '16:00', 'duree' => 90, 'equipes' => ['U9M1', 'U11F2'], 'groupe' => 'CEC'],
        ]));

        self::assertSame(200, $status);
        $line = $body['sections']['mutualisations'][0];
        self::assertSame('apparie', $line['statut']);
        self::assertArrayHasKey('blocLabel', $line, 'une ligne appariée porte toujours le champ');
        self::assertNull($line['blocLabel']);
    }

    /** Promesse : une mutualisation à créer n'a pas de groupe existant, donc pas de nom réel. */
    public function testAMutualisationToCreateHasNoBlocLabel(): void
    {
        [$status, $body] = $this->analyze($this->parcWithCases([
            ['gymnase' => 'Gymnase Mateo', 'jour' => 3, 'debut' => '16:00', 'duree' => 90, 'equipes' => ['U9M1', 'U11F2'], 'groupe' => 'CEC'],
        ]));

        self::assertSame(200, $status);
        $line = $body['sections']['mutualisations'][0];
        self::assertSame('aCreer', $line['statut']);
        self::assertNull($line['blocLabel'] ?? null);
    }

    // ───────────────────────── Retours fondateur 2026-10-26 — « Séances » en champ éditable (données servies) ─────────────────────────
    // Le champ « Séances » de l'aperçu est pré-rempli avec la valeur LUE dans le fichier pour TOUTE
    // équipe (pas seulement quand elle DIFFÈRE de l'actuelle, comme le fait `miseAJour`), avec une
    // indication « actuellement N » pour une équipe appariée. L'analyse doit donc servir, sur chaque
    // ligne équipe, `seances` (le nombre de séances LU = nombre de cases dérivé), et sur une ligne
    // APPARIÉE `seancesActuelles` (les séances actuelles de l'équipe visée). ROUGE aujourd'hui :
    // l'analyzer ne sert QUE `miseAJour` (et seulement quand ça diffère). Jumeaux front : les tests
    // « Séances » de ParcImportModal.test.tsx. NEO expose ces deux champs.

    public function testEveryTeamLineCarriesTheReadSessionsCount(): void
    {
        // Deux cases de la MÊME équipe « SM1 » → le dériveur compte 2 séances. L'analyse sert `seances`
        // = 2 sur la ligne équipe (le champ éditable s'en pré-remplit), équipe À CRÉER ou appariée.
        $parc = $this->parcWithCases([
            ['gymnase' => 'MATEO', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
            ['gymnase' => 'MATEO', 'jour' => 3, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
        ]);

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status);

        $teamLine = $body['sections']['equipes'][0];
        self::assertSame(2, $teamLine['seances'] ?? null, 'la ligne équipe porte le nombre de séances LU (2 cases pour SM1)');
    }

    public function testAnAppariedTeamLineAlsoCarriesItsCurrentSessionsCount(): void
    {
        // SM1 existe déjà au club (makeTeam → 2 séances/semaine). Une seule case → séances LUES = 1.
        // La ligne appariée porte `seances` = 1 (valeur du fichier) ET `seancesActuelles` = 2 (actuelle,
        // pour l'indication « actuellement N » et la comparaison du champ).
        $this->makeTeam('SM1');

        $parc = $this->parcWithCases([
            ['gymnase' => 'MATEO', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
        ]);

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status);

        $teamLine = $body['sections']['equipes'][0];
        self::assertSame('apparie', $teamLine['statut'], 'SM1 existant → ligne appariée');
        self::assertSame(1, $teamLine['seances'] ?? null, 'la valeur LUE du fichier (1 case) est servie');
        self::assertSame(2, $teamLine['seancesActuelles'] ?? null, 'les séances ACTUELLES de l\'équipe visée sont servies (« actuellement N »)');
    }

    // ───────────── PR2 (D9) — une ligne équipe « Créer » porte les suggestions rang/genre/entente ─────────────
    // Refonte import IA PR2 : l'aperçu pré-remplit les Select Rang + Genre + case Entente d'une ligne
    // « Créer » depuis la RÈGLE FBI unique ({@see \App\Service\Basketball\TeamCreationSuggester}). La
    // ligne `aCreer` porte donc `suggestedTierId` (int, résolu PAR LABEL), `suggestedGender` (?string,
    // null si aucun motif clair — « plus MIXTE ») et `suggestedCtc` (bool). ROUGE aujourd'hui :
    // l'analyzer ne sert aucun de ces trois champs sur une ligne équipe. Jumeaux : le barème au plus bas
    // {@see \App\Tests\Integration\Service\TeamCreationSuggesterTest} ; l'écran {@see ParcImportModal.test.tsx}.

    public function testACreatedTeamLineCarriesRankGenderAndCtcSuggestions(): void
    {
        $this->seedPriorityTiers();

        $parc = $this->parcWithCases([
            ['gymnase' => 'MATEO', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],            // senior n°1 → S, M, hors entente
            ['gymnase' => 'MATEO', 'jour' => 2, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['U15F CTC']],       // genre F, entente suggérée
            ['gymnase' => 'MATEO', 'jour' => 3, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['Loisir Détente']], // aucun marqueur → genre null
        ]);

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status);

        $byLabel = [];
        foreach ($body['sections']['equipes'] as $line) {
            $byLabel[$line['libelle']] = $line;
        }

        // SM1 — « Créer », rang S (senior n°1, résolu PAR LABEL), genre M, entente non suggérée.
        self::assertSame('aCreer', $byLabel['SM1']['statut'] ?? null, 'SM1 novel → ligne à créer');
        self::assertSame($this->tierIdForLabel('S'), $byLabel['SM1']['suggestedTierId'] ?? null, 'la ligne « Créer » porte le rang suggéré (senior n°1 → S)');
        self::assertSame('M', $byLabel['SM1']['suggestedGender'] ?? null, 'genre lu dans « SM1 » → M');
        self::assertFalse($byLabel['SM1']['suggestedCtc'] ?? null, 'aucun marqueur CTC → entente non suggérée');

        // « U15F CTC » — genre F lu au nom, entente suggérée par le marqueur « CTC ».
        self::assertSame('F', $byLabel['U15F CTC']['suggestedGender'] ?? null, 'genre lu dans « U15F CTC » → F');
        self::assertTrue($byLabel['U15F CTC']['suggestedCtc'] ?? null, '« CTC » dans le libellé → entente suggérée');

        // « Loisir Détente » — aucun marqueur de genre → suggestedGender null (plus MIXTE d'office) ;
        // la clé est PRÉSENTE (pré-remplit le Select « À choisir »), avec un rang suggéré entier.
        self::assertArrayHasKey('suggestedGender', $byLabel['Loisir Détente'], 'la ligne « Créer » porte toujours le champ suggestedGender');
        self::assertNull($byLabel['Loisir Détente']['suggestedGender'], 'aucun marqueur → genre null, à trancher à l\'import');
        self::assertIsInt($byLabel['Loisir Détente']['suggestedTierId'] ?? null, 'la ligne porte un rang suggéré entier (pré-remplit le Select Rang)');
    }

    public function testEveryTeamLineCarriesRankGenderAndCtcSuggestionsWhateverItsStatut(): void
    {
        // NEO signale le trou : les suggestions rang/genre/entente n'étaient servies QUE sur une ligne
        // « aCreer ». Choisir « Créer » sur une ligne AMBIGUË (ou suggérée / appariée) n'ouvrait alors
        // AUCUN Select Genre côté front (il se câble sur `suggestedTierId`), et l'executor refusait la
        // création sans genre (422) : le gestionnaire est piégé. Promesse : CHAQUE ligne équipe porte
        // les trois suggestions (une éventuelle création depuis N'IMPORTE QUEL statut est pré-remplie),
        // pas seulement « aCreer ». ROUGE aujourd'hui : {@see ParcImportAnalyzer::analyzeTeams} ne les
        // pose que si `'aCreer' === $statut`. Jumeaux : l'écran {@see ParcImportModal.test.tsx} (bloc
        // PR2, ligne ambiguë « Créer ») ; le barème au plus bas {@see TeamCreationSuggesterTest}.
        $this->seedPriorityTiers();
        $this->makeTeam('SM1');      // apparié (exact)
        $this->makeTeam('Leopards'); // suggéré (un seul candidat flou de « Leoprds »)
        $this->makeTeam('Armand');   // ambigu (avec « Armund » : deux candidats flous de « Armond »)
        $this->makeTeam('Armund');

        $parc = $this->parcWithCases([
            ['gymnase' => 'MATEO', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],      // apparie
            ['gymnase' => 'MATEO', 'jour' => 2, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['Leoprds']],  // suggere
            ['gymnase' => 'MATEO', 'jour' => 3, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['Armond']],   // ambigu
            ['gymnase' => 'MATEO', 'jour' => 4, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['U13F CTC']], // aCreer
        ]);

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status);

        $byLabel = [];
        foreach ($body['sections']['equipes'] as $line) {
            $byLabel[$line['libelle']] = $line;
        }

        // Précondition : les quatre statuts attendus sont bien produits (sinon le test ne prouverait pas
        // « quel que soit le statut »).
        self::assertSame('apparie', $byLabel['SM1']['statut'] ?? null, 'SM1 existant → apparié');
        self::assertSame('suggere', $byLabel['Leoprds']['statut'] ?? null, '« Leoprds » ≈ « Leopards » (un seul candidat flou) → suggéré');
        self::assertSame('ambigu', $byLabel['Armond']['statut'] ?? null, '« Armond » entre « Armand » et « Armund » → ambigu');
        self::assertSame('aCreer', $byLabel['U13F CTC']['statut'] ?? null, 'nouvelle équipe → à créer');

        // CHAQUE ligne équipe (pas seulement « aCreer ») porte les trois suggestions : elles
        // pré-remplissent le Select Rang + Genre + case Entente dès que le gestionnaire choisit
        // « Créer » sur CETTE ligne, quel que soit son statut d'appariement.
        foreach (['SM1', 'Leoprds', 'Armond', 'U13F CTC'] as $label) {
            $line = $byLabel[$label];
            self::assertArrayHasKey('suggestedTierId', $line, \sprintf('la ligne « %s » (%s) porte un rang suggéré', $label, (string) ($line['statut'] ?? '')));
            self::assertIsInt($line['suggestedTierId'], \sprintf('le rang suggéré de « %s » est un entier (pré-remplit le Select Rang)', $label));
            self::assertArrayHasKey('suggestedGender', $line, \sprintf('la ligne « %s » (%s) porte le champ genre suggéré (string|null)', $label, (string) ($line['statut'] ?? '')));
            self::assertArrayHasKey('suggestedCtc', $line, \sprintf('la ligne « %s » (%s) porte l\'entente suggérée', $label, (string) ($line['statut'] ?? '')));
            self::assertIsBool($line['suggestedCtc'], \sprintf('l\'entente suggérée de « %s » est un booléen', $label));
        }

        // Témoin de SENS : la ligne AMBIGUË lit son genre/entente comme une création le ferait — « Armond »
        // n'a aucun marqueur (genre null, entente false), là où « U13F CTC » (aCreer) lit F + entente.
        self::assertNull($byLabel['Armond']['suggestedGender'], '« Armond » sans marqueur → genre suggéré null (à trancher au Select)');
        self::assertFalse($byLabel['Armond']['suggestedCtc'], '« Armond » sans marqueur CTC → entente non suggérée');
        self::assertTrue($byLabel['U13F CTC']['suggestedCtc'], '« CTC » dans le libellé → entente suggérée');
    }

    // ───────────── PR1 refonte — la grille place chaque case sur SON créneau (position servie) ─────────────
    // Bug grille : la grille « qui fait foi » plaçait réservations/mutualisations d'après
    // `payload.cases[index]`, or l'`index` d'une ligne `reservations` (resp. `mutualisations`) est son
    // rang dans la liste DÉRIVÉE (`ParcCaseDeriver::deriveReservations`/`deriveMutualisations`), PAS
    // l'index de la case du parc : une case à 2 équipes (terrains séparés) donne 2 réservations, donc
    // tout décale dès la case multi-équipes suivante — la grille affichait alors les réservations au
    // mauvais jour/heure/gymnase. Le contrat : l'analyse sert sur chaque ligne `reservations` et
    // `mutualisations` une `position` {gymnase, jour, debut:"HH:MM", duree} issue de SON entrée dérivée,
    // dont le front place la case EXCLUSIVEMENT. Deux angles VOULUS (dérivation réservations ≠
    // mutualisations) sur la même promesse. Prouvé ROUGE ici, implémenté par NEO.

    public function testEachReservationLineCarriesThePositionOfItsOwnDerivedCase(): void
    {
        // Case 1 : 2 équipes sur TERRAINS SÉPARÉS (non mutualisée) → 2 réservations (index 0, 1).
        // Case 2 : 1 équipe, AUTRE gymnase / jour / heure → la 3ᵉ réservation (index 2). Cette ligne
        // doit porter la position EXACTE de la 2ᵉ case — c'est précisément le créneau que
        // `payload.cases[index]` (index 2 = aucune case, le décalage) donnait faux côté front.
        $parc = $this->parcWithCases([
            ['gymnase' => 'Gymnase Alpha', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1', 'SF1'], 'terrains_separes' => true],
            ['gymnase' => 'Gymnase Beta', 'jour' => 4, 'debut' => '20:30', 'duree' => 60, 'equipes' => ['U13M1']],
        ]);

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status);

        $reservations = $body['sections']['reservations'];
        self::assertCount(3, $reservations, '2 réservations pour la case à terrains séparés + 1 pour la case suivante');

        $third = $reservations[2];
        self::assertArrayHasKey('position', $third, 'chaque réservation porte la position de SA case dérivée');
        self::assertSame('Gymnase Beta', $third['position']['gymnase'], 'le gymnase est celui de SA case (la 2ᵉ), jamais une case décalée');
        self::assertSame(4, $third['position']['jour']);
        self::assertSame('20:30', $third['position']['debut']);
        self::assertSame(60, $third['position']['duree']);
    }

    public function testEachMutualisationLineCarriesThePositionOfItsOwnDerivedCase(): void
    {
        // Case 1 : 1 équipe (non mutualisée) → 0 mutualisation, mais elle DÉCALE le rang des cases par
        // rapport au rang des mutualisations. Case 2 : 2 équipes mutualisées, AUTRE gymnase / jour /
        // heure → la 1ʳᵉ mutualisation (index 0). Sa position doit être celle de la 2ᵉ case, PAS
        // `payload.cases[0]` (la 1ʳᵉ case) que la grille lisait.
        $parc = $this->parcWithCases([
            ['gymnase' => 'Gymnase Alpha', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
            ['gymnase' => 'Gymnase Gamma', 'jour' => 5, 'debut' => '19:15', 'duree' => 120, 'equipes' => ['U9M1', 'U9M2']],
        ]);

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status);

        $mutualisations = $body['sections']['mutualisations'];
        self::assertCount(1, $mutualisations, 'une seule case mutualisée');

        $first = $mutualisations[0];
        self::assertArrayHasKey('position', $first, 'chaque mutualisation porte la position de SA case dérivée');
        self::assertSame('Gymnase Gamma', $first['position']['gymnase'], 'le gymnase est celui de SA case (la 2ᵉ), jamais payload.cases[0]');
        self::assertSame(5, $first['position']['jour']);
        self::assertSame('19:15', $first['position']['debut']);
        self::assertSame(120, $first['position']['duree']);
    }

    public function testASameTeamInTwoPlacesAtOnceRaisesAnAlertNamingIt(): void
    {
        // t7 : SM2 est lu le JEUDI à 19:00 dans DEUX gymnases (Armand ET JDR) — impossible. L'analyse
        // lève une alerte transverse qui NOMME l'équipe, sans jamais faire un 500.
        [$status, $body] = $this->analyze($this->fixtureParc('t7.json'));
        self::assertSame(200, $status);

        $alertes = $body['alertes'] ?? [];
        $dualPlace = array_values(array_filter(
            \is_array($alertes) ? $alertes : [],
            static fn (array $a): bool => 'equipe_deux_endroits' === ($a['type'] ?? null),
        ));
        self::assertNotEmpty($dualPlace, 'une équipe dans deux gymnases au même moment est signalée');
        self::assertStringContainsString('SM2', implode(' ', array_map(static fn (array $a): string => (string) ($a['message'] ?? ''), $dualPlace)), 'l\'alerte nomme l\'équipe (SM2)');
    }

    public function testAnImplausibleHourRaisesAnAlert(): void
    {
        // D9 heure implausible — SEUIL tranché par l'orchestrateur (défaut, signalé au fondateur,
        // 2026-10-25) : alerte si DÉBUT < 07:00, OU DÉBUT > 22:00 (strict), OU FIN > 23:30.
        // Un début matinal 05:30 (< 07:00) est signalé.
        $parc = $this->parcWithCases([
            ['gymnase' => 'MATEO', 'jour' => 1, 'debut' => '05:30', 'duree' => 90, 'equipes' => ['SM1']],
        ]);

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status);

        self::assertContains('heure_implausible', $this->alertTypes($body), 'un début à 05:30 (< 07:00) est signalé');
    }

    public function testTheRealT9DeepseekLateNightCaseRaisesAnImplausibleHour(): void
    {
        // Sur la sortie RÉELLE t9-deepseek (horaires décalés en bloc), la case vendredi MATEO 22:30
        // a un DÉBUT > 22:00 → l'analyse lève une alerte d'heure implausible. Preuve terrain du seuil.
        [$status, $body] = $this->analyze($this->fixtureParc('t9-deepseek.json'));
        self::assertSame(200, $status);

        self::assertContains('heure_implausible', $this->alertTypes($body), 't9-deepseek a une case 22:30 (> 22:00) → heure implausible');
    }

    public function testARealisticLateEveningCaseIsNotFlagged(): void
    {
        // Témoin (dans l'autre sens) : une vraie case de soirée 20:30 → 22:30 (duree 120) est NORMALE
        // au basket — début ≤ 22:00 ET fin ≤ 23:30 : elle ne lève JAMAIS l'alerte d'heure implausible.
        $parc = $this->parcWithCases([
            ['gymnase' => 'MATEO', 'jour' => 5, 'debut' => '20:30', 'duree' => 120, 'equipes' => ['SM1']],
        ]);

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status);

        self::assertNotContains('heure_implausible', $this->alertTypes($body), 'une soirée 20:30-22:30 est normale, jamais signalée');
    }

    public function testADeclaredVenueWithoutAnyCaseRaisesAnAlert(): void
    {
        // D9 gymnase sans case : un gymnase DÉCLARÉ qu'aucune case n'utilise est signalé — sans bloquer.
        $parc = $this->validParc();
        $parc['gymnases'] = [['nom' => 'MATEO'], ['nom' => 'Gymnase Fantôme']];
        $parc['cases'] = [['gymnase' => 'MATEO', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']]];

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status);

        $types = array_map(static fn (array $a): string => (string) ($a['type'] ?? ''), (array) ($body['alertes'] ?? []));
        self::assertContains('gymnase_sans_case', $types, 'un gymnase déclaré sans case est signalé');
    }

    public function testFiabiliteBlockReportsRateAndAnomaliesAndRaisesTheBanner(): void
    {
        // D10 — le bloc `fiabilite` porte un taux d'appariement, un compte d'anomalies et un drapeau
        // `bandeau` (jamais bloquant). t7 accumule ≥ 3 anomalies (doublon SM2, etc.) → bandeau levé.
        [$status, $body] = $this->analyze($this->fixtureParc('t7.json'));
        self::assertSame(200, $status);

        self::assertArrayHasKey('fiabilite', $body, 'la réponse porte un bloc de fiabilité');
        self::assertIsNumeric($body['fiabilite']['taux'] ?? null, 'le taux d\'appariement est chiffré');
        self::assertIsInt($body['fiabilite']['anomalies'] ?? null);
        self::assertIsBool($body['fiabilite']['bandeau'] ?? null);
        self::assertTrue($body['fiabilite']['bandeau'], 't7 cumule ≥ 3 anomalies → bandeau « peu fiable » levé');
    }

    public function testAKnownVenueCorrectsNamingButNeverFiltersDetectedOnes(): void
    {
        // D3 contexte correcteur jamais filtre : « Armand » existe au club ; t7 détecte 9 gymnases.
        // L'existant CORRIGE le nommage (Armand apparié) mais les 8 autres restent PROPOSÉS — aucun écarté.
        $this->makeVenue('Armand');

        $body = $this->analyze($this->fixtureParc('t7.json'))[1];

        $gymStatuts = array_column($body['sections']['gymnases'], 'statut');
        self::assertContains('apparie', $gymStatuts, '« Armand » connu est apparié (nommage corrigé)');
        self::assertGreaterThanOrEqual(9, \count($body['sections']['gymnases']), 'les 9 gymnases détectés restent tous proposés — aucun filtré par l\'existant');
        self::assertContains('aCreer', $gymStatuts, 'les gymnases détectés inconnus restent proposés en création');
    }

    // ───────────────────────── PR1 — l'aperçu refondu sert « ambigu » + les candidats (D5) ─────────────────────────
    // Avant PR1, une ambiguïté (≥ 2 candidats) tombait en « decoche » SANS candidats : le gestionnaire
    // ne pouvait pas trancher. L'aperçu refondu sert le statut « ambigu » ET la liste des candidats
    // (équipes ET gymnases), pour le sélecteur « À trancher… » de la ligne. Règle au plus bas :
    // {@see TeamLabelMatcherTest::testAnAmbiguousMatchExposesEveryCandidateToChooseFrom} ; ICI le CÂBLAGE.

    public function testAnAmbiguousTeamLabelIsServedAsAmbiguWithItsCandidates(): void
    {
        // Deux équipes du club tombent dans le seuil flou de « Armond » (distance 1 de « Armand » ET
        // de « Armund ») : la ligne ressort « ambigu », DÉCOCHÉE, sans cible devinée, mais porte les
        // DEUX candidats (à trancher à la main).
        $this->makeTeam('Armand');
        $this->makeTeam('Armund');

        $parc = $this->parcWithCases([
            ['gymnase' => 'MATEO', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['Armond']],
        ]);

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status);

        $teamLine = $body['sections']['equipes'][0];
        self::assertSame('ambigu', $teamLine['statut'], 'deux candidats dans le seuil → ambigu, jamais deviné');
        self::assertFalse($teamLine['coche'], 'un ambigu n\'est jamais coché par défaut');
        self::assertNull($teamLine['cibleId'], 'un ambigu ne pointe aucune cible');
        $noms = array_map(static fn (array $c): string => (string) $c['nom'], $teamLine['candidats']);
        self::assertEqualsCanonicalizing(['Armand', 'Armund'], $noms, 'les deux équipes candidates sont offertes au choix');
    }

    public function testAnAmbiguousVenueLabelIsServedAsAmbiguWithItsCandidates(): void
    {
        // Même promesse côté GYMNASES : deux gymnases du club se rapprochent (containment par mot) du
        // libellé « Gymnase Mateo » collé. La ligne ressort « ambigu » + ses deux candidats — jamais
        // un pari, jamais un simple « decoche » muet.
        $a = $this->makeVenue('Gymnase Mateo A');
        $b = $this->makeVenue('Gymnase Mateo B');

        $parc = $this->parcWithCases([
            ['gymnase' => 'Gymnase Mateo', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
        ]);

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status);

        $gymLine = $body['sections']['gymnases'][0];
        self::assertSame('ambigu', $gymLine['statut'], 'deux gymnases candidats → ambigu');
        self::assertFalse($gymLine['coche']);
        self::assertNull($gymLine['cibleId']);
        $noms = array_map(static fn (array $c): string => (string) $c['nom'], $gymLine['candidats']);
        self::assertEqualsCanonicalizing([$a->getName(), $b->getName()], $noms, 'les deux gymnases candidats sont offerts au choix');
    }

    // ───────────────────────── PR1 — revalidation : /analyze rejoue avec les corrections (D4 étendu) ─────────────────────────
    // Le bouton « Revalider » reposte `{payload, decisions}` : l'analyse REJOUE sur le parc en
    // APPLIQUANT les corrections de l'aperçu (jour/heure/durée d'un créneau), si bien qu'une alerte de
    // chevauchement peut TOMBER (correction qui sépare deux créneaux) ou NAÎTRE (correction qui les
    // superpose). Deux angles VOULUS d'une même promesse sensible (pipeline d'import) :
    // `testRevalidationMakesAnOverlapAlertFall` ⇄ `testRevalidationMakesAnOverlapAlertBorn`.

    public function testRevalidationMakesAnOverlapAlertFall(): void
    {
        // Deux cases du MÊME gymnase le MÊME jour se chevauchent (18:00 et 19:00, 90 min) → l'analyse
        // BRUTE lève une alerte de chevauchement sur le créneau. Le gestionnaire CORRIGE l'heure du 2e
        // (20:30) et REVALIDE : l'alerte tombe (plus de chevauchement).
        $parc = $this->parcWithCases([
            ['gymnase' => 'MATEO', 'jour' => 2, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
            ['gymnase' => 'MATEO', 'jour' => 2, 'debut' => '19:00', 'duree' => 90, 'equipes' => ['SF1']],
        ]);

        // Précondition : l'analyse brute voit bien le chevauchement.
        [$rawStatus, $raw] = $this->analyze($parc);
        self::assertSame(200, $rawStatus);
        self::assertSame('alerte', $raw['sections']['creneaux'][1]['statut'], 'précondition : deux créneaux superposés → alerte');

        // Revalidation : la correction d'heure du 2e créneau sépare les deux cases.
        $decisions = [['section' => 'creneaux', 'index' => 1, 'coche' => true, 'corrections' => ['debut' => '20:30']]];
        [$status, $body] = $this->revalidate($parc, $decisions);

        self::assertSame(200, $status, 'revalider reposte {payload, decisions} et rejoue l\'analyse');
        self::assertNotSame('alerte', $body['sections']['creneaux'][1]['statut'], 'la correction d\'heure lève le chevauchement');
        self::assertNotSame('alerte', $body['sections']['creneaux'][0]['statut'], 'le premier créneau n\'est plus en conflit non plus');
    }

    public function testRevalidationMakesAnOverlapAlertBorn(): void
    {
        // L'autre sens (jumeau du test ci-dessus) : deux cases NE se chevauchent PAS (18:00 et 20:00) →
        // aucune alerte à l'analyse brute. Le gestionnaire CORRIGE l'heure du 2e (18:30) et REVALIDE :
        // l'alerte de chevauchement NAÎT de la correction.
        $parc = $this->parcWithCases([
            ['gymnase' => 'MATEO', 'jour' => 2, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
            ['gymnase' => 'MATEO', 'jour' => 2, 'debut' => '20:00', 'duree' => 90, 'equipes' => ['SF1']],
        ]);

        [$rawStatus, $raw] = $this->analyze($parc);
        self::assertSame(200, $rawStatus);
        self::assertNotSame('alerte', $raw['sections']['creneaux'][1]['statut'], 'précondition : deux créneaux séparés → aucune alerte');

        $decisions = [['section' => 'creneaux', 'index' => 1, 'coche' => true, 'corrections' => ['debut' => '18:30']]];
        [$status, $body] = $this->revalidate($parc, $decisions);

        self::assertSame(200, $status);
        self::assertSame('alerte', $body['sections']['creneaux'][1]['statut'], 'la correction d\'heure crée un chevauchement');
    }

    // ───────────────────── PR1 refonte — l'appariement ne confond jamais le GENRE (défaut D, câblage) ─────────────────────
    // Règle au plus bas : {@see TeamLabelMatcherTest::testSpacesDoNotCountAndTheRightGenderIsMatchedNeverTheOpposite}
    // (+ ses témoins numéro/catégorie). ICI, le CÂBLAGE de bout en bout : un libellé de case espacé
    // apparié au bon GENRE à travers l'analyzer réel.

    public function testASpacedTeamLabelMatchesTheRightGenderNeverTheOpposite(): void
    {
        // Capture Rillieux : le club a « U15M1 » ET « U15F1 ». Une case porte « U15M 1 » (espace) →
        // l'analyse l'apparie au MASCULIN, jamais au féminin, jamais « ambigu ». Avant correctif :
        // la clé canonique effaçait le marqueur de genre → ambigu entre les deux (voire apparié au
        // mauvais genre pour « U15 M2 »).
        $male = $this->makeTeam('U15M1');
        $this->makeTeam('U15F1');

        $parc = $this->parcWithCases([
            ['gymnase' => 'MATEO', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['U15M 1']],
        ]);

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status);

        $teamLine = $body['sections']['equipes'][0];
        self::assertSame('apparie', $teamLine['statut'], '« U15M 1 » ≡ « U15M1 » : apparié, jamais ambigu entre les genres');
        self::assertSame($male->getId(), $teamLine['cibleId'], 'vise le MASCULIN U15M1, jamais le féminin U15F1');
    }

    // ───────────────────────── PR A (incident d'un club pilote) — une équipe IGNORÉE/introuvable bloque sa séance commune/réservation DÈS l'analyse (D1/D2) ─────────────────────────
    // Incident d'un club pilote 2026-10-29 : la case « SF1 + SM shooting » cite « SM shooting », que le
    // gestionnaire a mise en « Ignorer » à l'étape Équipes. Aujourd'hui l'analyse classe la séance
    // commune `aCreer` COCHÉE sans alerte (ParcImportAnalyzer::analyzeMutualisations ne lit pas les
    // décisions Équipes) ; l'import renvoie alors un 422 opaque. Promesse PR A : une séance commune
    // (ou une réservation) qui cite une équipe IGNORÉE (décision `coche:false`) ou introuvable sort
    // en `alerte` DÉCOCHÉE, avec une `raison` qui NOMME l'équipe ET un champ MACHINE (contrat TANK :
    // `alerteCode`, string non vide — nom libre documenté ici) que la grille lit pour bloquer/orienter
    // (D13). Prouvé dès l'analyse. Deux angles VOULUS (séance commune ≠ réservation) sur le même
    // domaine sensible (pipeline d'import) : ce test ⇄ testIgnoringATeamMakesTheCitingReservationAnAlerte.
    // Jumeau IMPORT (le 422 agrégé + le libellé « séance commune ») : ParcImportExecuteTest.

    public function testIgnoringATeamAtTeamsStepDrivesTheCitingMutualisationAlerteNamingItWithACode(): void
    {
        // Case d'un club pilote : « SF1 + SM shooting », groupe « SM Shooting ». Les deux noms dérivent en lignes
        // Équipes (SF1 index 0, « SM shooting » index 1) ET en UNE séance commune (index 0).
        $parc = $this->parcWithCases([
            ['gymnase' => 'Léon Jouhaux', 'jour' => 1, 'debut' => '20:30', 'duree' => 90, 'equipes' => ['SF1', 'SM shooting'], 'groupe' => 'SM Shooting'],
        ]);

        // Précondition — PREMIÈRE analyse (sans décisions) : tout est gardé par défaut, la séance
        // commune est `aCreer` cochée, aucune alerte de bloc. (Sinon le born ci-dessous ne prouverait rien.)
        [$rawStatus, $raw] = $this->analyze($parc);
        self::assertSame(200, $rawStatus);
        self::assertSame('aCreer', $raw['sections']['mutualisations'][0]['statut'], 'précondition : sans décision, la séance commune est à créer, cochée');

        // BORN — le gestionnaire IGNORE « SM shooting » à l'étape Équipes (décision coche=false) puis
        // revalide : la séance commune qui la cite devient une alerte DÉCOCHÉE nommant l'équipe + un code.
        $born = $this->revalidate($parc, [['section' => 'equipes', 'index' => 1, 'coche' => false]])[1];
        $mutLine = $born['sections']['mutualisations'][0];
        self::assertSame('alerte', $mutLine['statut'], 'citer une équipe ignorée → la séance commune passe en alerte');
        self::assertFalse($mutLine['coche'], 'une alerte de séance commune n\'est jamais cochée');
        self::assertStringContainsString('SM shooting', (string) ($mutLine['raison'] ?? ''), 'la raison NOMME l\'équipe en cause');
        self::assertArrayHasKey('alerteCode', $mutLine, 'un champ MACHINE (alerteCode) accompagne la raison humaine (D13)');
        self::assertIsString($mutLine['alerteCode']);
        self::assertNotSame('', $mutLine['alerteCode'], 'le code machine est non vide');

        // FALL — le gestionnaire REMET « SM shooting » en Garder (coche=true) : l'alerte tombe.
        $fall = $this->revalidate($parc, [['section' => 'equipes', 'index' => 1, 'coche' => true]])[1];
        self::assertNotSame('alerte', $fall['sections']['mutualisations'][0]['statut'], 'remettre l\'équipe en « Garder » lève l\'alerte');
    }

    public function testIgnoringATeamMakesTheCitingReservationAnAlerte(): void
    {
        // Jumeau RÉSERVATION du test ci-dessus (ParcImportExecutor.php:536-539 a le même trou) : une
        // case à UNE équipe dérive une réservation individuelle. Ignorer l'équipe à l'étape Équipes
        // fait passer SA réservation en alerte décochée nommant l'équipe.
        $parc = $this->parcWithCases([
            ['gymnase' => 'Léon Jouhaux', 'jour' => 2, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['U13M1']],
        ]);

        [$rawStatus, $raw] = $this->analyze($parc);
        self::assertSame(200, $rawStatus);
        self::assertSame('aCreer', $raw['sections']['reservations'][0]['statut'], 'précondition : sans décision, la réservation est à créer, cochée');

        $born = $this->revalidate($parc, [['section' => 'equipes', 'index' => 0, 'coche' => false]])[1];
        $resLine = $born['sections']['reservations'][0];
        self::assertSame('alerte', $resLine['statut'], 'citer une équipe ignorée → la réservation passe en alerte');
        self::assertFalse($resLine['coche'], 'une alerte de réservation n\'est jamais cochée');
        self::assertStringContainsString('U13M1', (string) ($resLine['raison'] ?? ''), 'la raison NOMME l\'équipe en cause');
    }

    // ───────────────────────── PR A (D3 analyse) — /analyze rejoue corrections.equipes ─────────────────────────
    // Le canal `corrections.equipes` d'une décision de séance commune (redéfinition de la composition :
    // liste ≥ 2 d'UUID d'équipe du club) n'est pas que pour l'import — il est REJOUÉ par /analyze à la
    // revalidation : la séance commune corrigée voit son alerte TOMBER (l'équipe ignorée disparaît de la
    // composition), son `libelle` refléter la composition CORRIGÉE, et `seancesCommunes` est RECALCULÉ
    // selon cette composition (compte des cases portant le MÊME ensemble corrigé). ROUGE sur b938c993c :
    // {@see ParcImportAnalyzer::applyTimeCorrections} ne lit que jour/heure/durée, jamais `equipes`.
    // Contrat (défini ici, implémenté par NEO) : la ligne mutualisation expose `seancesCommunes` (int) et
    // son `libelle` est reconstruit depuis la composition corrigée. Jumeau IMPORT : ParcImportExecuteTest.

    public function testAnalyzeReplaysTeamCompositionCorrectionDroppingTheAlerteAndRecountingCommonSessions(): void
    {
        $sf1 = $this->makeTeam('SF1');
        $sm1 = $this->makeTeam('SM1');
        $sm2 = $this->makeTeam('SM2');

        // Deux cases : la case 0 cite « SM shooting » (le nom du groupe, lu comme équipe) ; la case 1
        // porte déjà la vraie composition SF1 + SM1 + SM2. Dérivation : séances communes index 0 (set
        // {SF1, SM shooting}, seances_communes 1) et index 1 (set {SF1, SM1, SM2}, seances_communes 1).
        $parc = $this->parcWithCases([
            ['gymnase' => 'Léon Jouhaux', 'jour' => 1, 'debut' => '20:30', 'duree' => 90, 'equipes' => ['SF1', 'SM shooting'], 'groupe' => 'SM Shooting'],
            ['gymnase' => 'Léon Jouhaux', 'jour' => 3, 'debut' => '20:30', 'duree' => 90, 'equipes' => ['SF1', 'SM1', 'SM2'], 'groupe' => 'SM Shooting'],
        ]);

        // Précondition — « SM shooting » (ligne Équipes index 1) IGNORÉE, SANS correction : la séance
        // commune 0 qui la cite est en alerte.
        $ignoreOnly = [['section' => 'equipes', 'index' => 1, 'coche' => false]];
        self::assertSame('alerte', $this->revalidate($parc, $ignoreOnly)[1]['sections']['mutualisations'][0]['statut'], 'précondition : citer l\'équipe ignorée → alerte');

        // Revalidation avec la composition corrigée (SF1 + SM1 + SM2) sur la séance commune 0.
        $decisions = [
            ['section' => 'equipes', 'index' => 1, 'coche' => false],
            ['section' => 'mutualisations', 'index' => 0, 'coche' => true, 'corrections' => ['equipes' => [$sf1->getId(), $sm1->getId(), $sm2->getId()]]],
        ];
        $body = $this->revalidate($parc, $decisions)[1];
        $mut = $body['sections']['mutualisations'][0];

        self::assertNotSame('alerte', $mut['statut'], 'la composition corrigée lève l\'alerte (« SM shooting » sort de la composition)');
        self::assertStringContainsString('SM1', $mut['libelle'], 'le libellé reflète la composition CORRIGÉE');
        self::assertStringContainsString('SM2', $mut['libelle'], 'le libellé reflète la composition CORRIGÉE');
        self::assertStringNotContainsString('shooting', $mut['libelle'], '« SM shooting » n\'est plus dans la composition');
        self::assertSame(2, $mut['seancesCommunes'] ?? null, 'seancesCommunes recalculé : les deux cases portent désormais le même ensemble corrigé');
    }

    // ───────────────────────── PR A (D8 + P4-366) — bandeau de fiabilité & champ miseAJour ─────────────────────────

    public function testAVirginClubFirstImportDoesNotRaiseTheUnreliableBanner(): void
    {
        // D8 — sur un club VIERGE (aucun gymnase/équipe préexistant : rien À APPARIER), un premier
        // import n'apparie RIEN par construction ; ce n'est pas un signe de lecture peu fiable. Le
        // bandeau « peu fiable » doit donc rester BAISSÉ. ROUGE aujourd'hui : le taux vaut 0/ N → < 50 %
        // → bandeau levé (ParcImportAnalyzer::fiabilite).
        [$status, $body] = $this->analyze($this->validParc());
        self::assertSame(200, $status);

        self::assertFalse($body['fiabilite']['bandeau'] ?? true, 'un club vierge (rien à apparier) ne lève pas le bandeau « peu fiable »');
    }

    public function testTheMiseAJourFieldIsGoneFromTeamLines(): void
    {
        // P4-366 — le champ `miseAJour` d'une ligne équipe disparaît de la réponse (le champ éditable
        // « Séances » + `seancesActuelles` suffisent à l'écran). SM1 existe (2 séances), le parc en lit
        // 1 : aujourd'hui l'analyzer pose `miseAJour` sur cette ligne appariée → ROUGE.
        $this->makeTeam('SM1');

        $parc = $this->parcWithCases([
            ['gymnase' => 'MATEO', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
        ]);

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status);

        $teamLine = $body['sections']['equipes'][0];
        self::assertSame('apparie', $teamLine['statut'], 'précondition : SM1 existant → ligne appariée');
        self::assertArrayNotHasKey('miseAJour', $teamLine, 'le champ miseAJour a disparu de la réponse (P4-366)');
    }

    // ───────────────────────── PR A lot 2 (E1 analyse) — corrections.equipes recompte les séances ─────────────────────────

    public function testCompositionCorrectionRecomputesAddedAndRemovedTeamSessionsAtAnalyze(): void
    {
        // E1 — redéfinir la composition d'une séance commune (`corrections.equipes`) RECOMPTE les
        // `seances` des équipes AJOUTÉES (+1) et RETIRÉES (−1) dès /analyze. SM1/SM2 gagnent la séance
        // commune (1 indiv + 1 commune = 2), « SM shooting » sort (0). ROUGE sur fc13679db :
        // {@see ParcImportAnalyzer::applyTimeCorrections} ne lit pas `corrections.equipes`.
        $sf1 = $this->makeTeam('SF1');
        $sm1 = $this->makeTeam('SM1');
        $sm2 = $this->makeTeam('SM2');

        $parc = $this->parcWithCases([
            ['gymnase' => 'MATEO', 'jour' => 2, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],                                                    // ligne Équipes 0
            ['gymnase' => 'MATEO', 'jour' => 3, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM2']],                                                    // ligne Équipes 1
            ['gymnase' => 'Léon Jouhaux', 'jour' => 1, 'debut' => '20:30', 'duree' => 90, 'equipes' => ['SF1', 'SM shooting'], 'groupe' => 'SM Shooting'],  // SF1 (2), SM shooting (3) ; séance commune 0
        ]);
        $decisions = [
            ['section' => 'equipes', 'index' => 3, 'coche' => false], // on ignore « SM shooting »
            ['section' => 'mutualisations', 'index' => 0, 'coche' => true, 'corrections' => ['equipes' => [$sf1->getId(), $sm1->getId(), $sm2->getId()]]],
        ];

        $body = $this->revalidate($parc, $decisions)[1];
        $byLabel = [];
        foreach ($body['sections']['equipes'] as $line) {
            $byLabel[(string) $line['libelle']] = $line;
        }

        self::assertSame(2, $byLabel['SM1']['seances'] ?? null, 'SM1 ajoutée à la séance commune : 1 indiv + 1 commune = 2');
        self::assertSame(2, $byLabel['SM2']['seances'] ?? null, 'SM2 ajoutée : 2 séances recomptées');
        self::assertSame(1, $byLabel['SF1']['seances'] ?? null, 'SF1 déjà membre : inchangée');
        self::assertSame(0, $byLabel['SM shooting']['seances'] ?? 0, '« SM shooting » retirée de la composition : 0 (ou absente)');
    }

    // ───────────────────────── PR A lot 2 (E2 analyse) — la garde de budget individuel joue dès /analyze ─────────────────────────

    /**
     * L'aperçu dit ce que l'exécution refuserait. Deux équipes déjà réservées ensemble sur deux cases, un
     * groupe à une seule séance commune : la ligne sort en alerte DÉCOCHÉE avec le message du refus, au lieu
     * d'un `aCreer` qui finit en 422 après le clic sur Importer. Jumeau exécution :
     * `ParcImportExecuteTest::testImportNeverCreatesABlockWithFewerSessionsThanItsReservedCases`.
     * TÉMOIN : réservées sur une seule case, la ligne reste importable.
     */
    public function testAnalyzeFlagsAGroupWithFewerCommonSessionsThanItsReservedCases(): void
    {
        $a = $this->makeTeam('U9M1');
        $b = $this->makeTeam('U11F2');
        $a->setSessionsPerWeek(3);
        $b->setSessionsPerWeek(3);
        $venue = $this->makeVenue('Gymnase Platane');
        $this->scopeGucToClub($this->club->getId());
        $reservation = null;
        foreach ([$a, $b] as $team) {
            foreach ([3, 4] as $day) {
                $reservation = (new Reservation)
                    ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
                    ->setTeamId($team->getId())->setVenueId($venue->getId())->setDayOfWeek($day)
                    ->setStartTime(new DateTimeImmutable('1970-01-01 16:00:00'))->setDurationMinutes(90);
                $this->em->persist($reservation);
            }
        }
        $this->em->flush();
        $parc = $this->parcWithCases([
            ['gymnase' => 'Gymnase Platane', 'jour' => 3, 'debut' => '16:00', 'duree' => 90, 'equipes' => ['U9M1', 'U11F2'], 'groupe' => 'Groupe X'],
        ]);

        [$status, $body] = $this->analyze($parc);

        self::assertSame(200, $status);
        $line = $body['sections']['mutualisations'][0];
        self::assertSame('alerte', $line['statut'], 'ce que l\'exécution refuserait se voit dans l\'aperçu');
        self::assertFalse($line['coche'], 'une alerte n\'est jamais cochée');
        self::assertStringContainsString('2 séances communes sont réservées pour ce groupe', (string) ($line['raison'] ?? ''));

        // TÉMOIN : plus qu'une case réservée en commun, le groupe à 1 séance tient.
        $this->em->createQueryBuilder()->delete(Reservation::class, 'r')->where('r.dayOfWeek = 4')->getQuery()->execute();
        [, $witness] = $this->analyze($parc);
        self::assertNotSame('alerte', $witness['sections']['mutualisations'][0]['statut']);
    }

    public function testTheIndividualBudgetGuardFlagsAReservationAtAnalyze(): void
    {
        // E2 — une équipe dont tout le budget de séances est déjà pris par ses groupes (bloc socle) ne
        // peut pas poser de réservation INDIVIDUELLE : la garde (ReservationGroupOccupancy /
        // SoloReservationBudget) est jouée DÈS /analyze → la réservation sort en `alerte` DÉCOCHÉE,
        // nommant l'équipe, avec un `alerteCode` DÉDIÉ (contrat : `budget_groupe`, string non vide) que
        // la grille lit pour bloquer Importer. ROUGE sur fc13679db : `analyzeReservations` ne joue pas
        // la garde de budget → la réservation est `aCreer`.
        $sm1 = $this->makeTeam('SM1');
        $sm2 = $this->makeTeam('SM2');
        $sm1->setSessionsPerWeek(1);
        $sm2->setSessionsPerWeek(1);
        $this->em->flush();
        $this->makeBlock([$sm1, $sm2], 1); // le bloc socle consomme l'unique séance de SM1

        $parc = $this->parcWithCases([
            ['gymnase' => 'Léon Jouhaux', 'jour' => 2, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']],
        ]);

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status);

        $resLine = $body['sections']['reservations'][0];
        self::assertSame('alerte', $resLine['statut'], 'SM1 est group-only : sa réservation individuelle sort en alerte dès l\'analyse');
        self::assertFalse($resLine['coche'], 'une alerte de budget n\'est jamais cochée');
        self::assertArrayHasKey('alerteCode', $resLine, 'un code machine dédié accompagne la raison');
        self::assertSame('budget_groupe', $resLine['alerteCode'] ?? null, 'alerteCode dédié à la garde de budget individuel');
        self::assertStringContainsString('SM1', (string) ($resLine['raison'] ?? ''), 'la raison NOMME l\'équipe');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = (new Club)
            ->setName('Parc Club ' . $uid)->setSlug('parc-club-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('parc' . $uid . '@test.com');
        $this->user->setFirstName('Parc');
        $this->user->setLastName('Tester');
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
     * @param list<Team> $teams
     */
    private function makeBlock(array $teams, int $commonSessions): SharedTrainingBlock
    {
        $this->scopeGucToClub($this->club->getId());
        $block = (new SharedTrainingBlock)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId(null)->setCommonSessions($commonSessions)->setLabel('Groupe');
        $this->em->persist($block);
        foreach ($teams as $team) {
            $member = (new SharedTrainingBlockTeam)
                ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
                ->setSchedulePlanId(null)->setBlockId($block->getId())->setTeamId($team->getId());
            $this->em->persist($member);
        }
        $this->em->flush();

        return $block;
    }

    /**
     * Un parc-v2 minimal recevable — chaque test l'adapte.
     *
     * @return array<string, mixed>
     */
    private function validParc(): array
    {
        return $this->parcWithCases([['gymnase' => 'MATEO', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1']]]);
    }

    /**
     * @param list<array<string, mixed>> $cases
     *
     * @return array<string, mixed>
     */
    private function parcWithCases(array $cases): array
    {
        $gyms = [];
        foreach ($cases as $case) {
            $gyms[(string) $case['gymnase']] = true;
        }

        return [
            'format' => 'parc-v2',
            'gymnases' => array_map(static fn (string $nom): array => ['nom' => $nom], array_keys($gyms)),
            'cases' => $cases,
        ];
    }

    /**
     * Les `type` des alertes transverses de la réponse d'analyse.
     *
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function alertTypes(array $body): array
    {
        return array_map(static fn (array $a): string => (string) ($a['type'] ?? ''), (array) ($body['alertes'] ?? []));
    }

    /**
     * @return array<string, mixed>
     */
    private function fixtureParc(string $name): array
    {
        $decoded = json_decode((string) file_get_contents(self::FIXTURES . $name), true);
        self::assertIsArray($decoded, 'le fixture ' . $name . ' doit décoder en objet');

        return $decoded;
    }

    /**
     * @param array<string, mixed>  $parc
     * @param array<string, string> $server
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function analyze(array $parc, ?string $clubId = null, ?string $token = null, array $server = []): array
    {
        return $this->analyzeRaw((string) json_encode($parc, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), $clubId, $token, $server);
    }

    /**
     * @param array<string, string> $server
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function analyzeRaw(string $rawBody, ?string $clubId = null, ?string $token = null, array $server = []): array
    {
        $this->client->request(
            'POST',
            '/api/clubs/' . ($clubId ?? $this->club->getId()) . '/parc-import/analyze',
            [],
            [],
            $server + ['HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->token), 'CONTENT_TYPE' => 'application/json', 'HTTP_X_SEASON_ID' => $this->season->getId()],
            $rawBody,
        );
        $response = $this->client->getResponse();
        $decoded = json_decode((string) $response->getContent(), true);

        return [$response->getStatusCode(), \is_array($decoded) ? $decoded : []];
    }

    private function makeVenue(string $name): Venue
    {
        $this->scopeGucToClub($this->club->getId());
        $venue = (new Venue)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName($name)->setSource('manual')->setIsActive(true)->setCanSplit(false);
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function makeTeam(string $name): Team
    {
        $this->scopeGucToClub($this->club->getId());
        $team = (new Team)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId($this->uuid())->setPriorityTierId(3)
            ->setName($name)->setSessionsPerWeek(2)->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
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

    /**
     * Revalidation (PR1) : reposte l'enveloppe `{payload, decisions}` sur /analyze — le serveur rejoue
     * l'analyse sur le parc EN APPLIQUANT les corrections de l'aperçu (distinct du collage brut, que
     * le front envoie à la PREMIÈRE analyse).
     *
     * @param array<string, mixed>       $parc
     * @param list<array<string, mixed>> $decisions
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function revalidate(array $parc, array $decisions): array
    {
        return $this->analyzeRaw((string) json_encode(['payload' => $parc, 'decisions' => $decisions], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array{venueId: string, teamId: string}
     */
    private function seedForeignClubWithVenueAndTeam(string $venueName, string $teamName): array
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
        $team = (new Team)
            ->setClubId($otherClub->getId())->setSeasonId($otherSeason->getId())
            ->setSportCategoryId($this->uuid())->setPriorityTierId(3)
            ->setName($teamName)->setSessionsPerWeek(3)->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        return ['venueId' => $venue->getId(), 'teamId' => $team->getId()];
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
