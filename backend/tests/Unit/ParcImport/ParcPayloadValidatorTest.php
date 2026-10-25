<?php

declare(strict_types=1);

namespace App\Tests\Unit\ParcImport;

use App\Service\ParcImport\ParcPayloadValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Lot correctif import IA (« G2 ») PR1 — LE validateur du format `parc-v2` : le texte brut qu'un
 * chatbot (GPT / Gemini / Claude / Copilot) rend à partir de l'Excel/image/PDF du club et que le
 * gestionnaire colle. parc-v2 REMPLACE parc-v1 (jamais déployé) : plus de sections `equipes`/
 * `creneaux`/`mutualisations`/`reservations` structurées — UNE LIGNE PAR CASE
 * (`{gymnase, jour, debut, duree?, equipes[], groupe?, terrains_separes?}`), et l'appli DÉDUIT
 * équipes / séances / genre / catégorie / mutualisations / réservations (cadrage G2 D1).
 *
 * Décisions tranchées (cadrage G2, 2026-10-25) encodées ici :
 *  - `format` = VERROU de version : seul « parc-v2 » est lu — « parc-v1 » (l'ancien, jamais
 *    déployé) et toute autre version sont REFUSÉS en nommant « parc-v2 », jamais lus « au mieux » ;
 *  - `cases` est REQUISE (liste) ; `gymnases` est OPTIONNELLE — plusieurs chatbots réels l'omettent
 *    (t9-deepseek, t10-gemini, rillieux-gpt), l'appli déduit alors les gymnases des cases ;
 *  - D4 normalisation de RÉCUPÉRATION guillemets typographiques « “ ” ‘ ’ » + dé-habillage d'un
 *    bloc Markdown ```json SEULEMENT si le parse BRUT échoue : un JSON déjà valide n'est JAMAIS
 *    retouché (ses valeurs — un nom d'équipe contenant un vrai guillemet typographique — survivent) ;
 *  - plafond de 6 équipes par case (défaut tranché) : au-delà → 422 nommant la case (bombe) ;
 *  - champs PAR CASE validés (gymnase, jour ISO, HH:MM, durée optionnelle) ; `duree` absente =
 *    recevable (défaut 90 déduit en aval) ; `equipes` vide = recevable (case = créneau vide) ;
 *  - champs/sections HORS PÉRIMÈTRE (une vieille section `coachs`, une clé inconnue) : IGNORÉS,
 *    jamais une erreur — le chatbot n'est pas sous notre contrôle.
 *
 * Contrat : {@see ParcPayloadValidator::errors()} prend le JSON BRUT (il possède le décodage +
 * la récupération) et rend la liste des motifs de refus, VIDE si le parc est recevable.
 * {@see ParcPayloadValidator::parse()} rend le payload décodé (après récupération si besoin) ou
 * null — c'est par lui que « JSON valide jamais retouché » est observable.
 */
#[Group('phase1')]
final class ParcPayloadValidatorTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../Fixtures/parc/';

    private ParcPayloadValidator $validator;

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function acceptedProvider(): iterable
    {
        yield 'baseline parc-v2' => [self::validParcV2()];

        // `gymnases` optionnelle : plusieurs chatbots réels l'omettent, l'appli la déduit des cases.
        $noGyms = self::validParcV2();
        unset($noGyms['gymnases']);
        yield 'gymnases omise (déduite des cases)' => [$noGyms];

        // `duree` absente = recevable (défaut 90 déduit en aval, cadrage G2).
        $noDuration = self::validParcV2();
        unset($noDuration['cases'][0]['duree']);
        yield 'durée absente (défaut en aval)' => [$noDuration];

        // `equipes` vide = case = créneau vide du gymnase.
        $emptyTeams = self::validParcV2();
        $emptyTeams['cases'][0]['equipes'] = [];
        yield 'case sans équipe (créneau vide)' => [$emptyTeams];

        // Exactement 6 équipes sur une case = le plafond, recevable (7 = refusé plus bas).
        $sixTeams = self::validParcV2();
        $sixTeams['cases'][0]['equipes'] = ['A', 'B', 'C', 'D', 'E', 'F'];
        yield 'six équipes sur une case (plafond atteint)' => [$sixTeams];

        // `groupe` (label d'un bloc mutualisé, ex. « CEC ») et `terrains_separes` : optionnels.
        $group = self::validParcV2();
        $group['cases'][0] += ['groupe' => 'CEC', 'terrains_separes' => true];
        yield 'groupe et terrains_separes (optionnels)' => [$group];

        // Champs / sections hors périmètre : une vieille section `coachs` d'un ancien chatbot, une
        // clé inconnue par case — IGNORÉS, jamais une erreur.
        $extra = self::validParcV2();
        $extra['coachs'] = [['nom' => 'Alice Martin']]; // section morte, ignorée
        $extra['cases'][0] += ['couleur' => 'bleu', 'niveau' => 'R2'];
        yield 'champs et section hors périmètre ignorés' => [$extra];
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusedProvider(): iterable
    {
        // `format` — le verrou de version (parc-v2 désormais).
        $noFormat = self::validParcV2();
        unset($noFormat['format']);
        yield 'format absent' => [$noFormat, 'parc-v2'];

        $legacy = self::validParcV2();
        $legacy['format'] = 'parc-v1';
        yield 'ancien parc-v1 refusé nommément' => [$legacy, 'parc-v2'];

        $unknown = self::validParcV2();
        $unknown['format'] = 'socle-v1';
        yield 'version inconnue' => [$unknown, 'parc-v2'];

        // `cases` requise.
        $noCases = self::validParcV2();
        unset($noCases['cases']);
        yield 'section cases absente' => [$noCases, 'cases'];

        $casesNotList = self::validParcV2();
        $casesNotList['cases'] = 'MATEO';
        yield 'cases en chaîne, pas une liste' => [$casesNotList, 'cases'];

        // Champs PAR CASE.
        $noVenue = self::validParcV2();
        unset($noVenue['cases'][0]['gymnase']);
        yield 'case sans gymnase' => [$noVenue, 'gymnase'];

        $dayHigh = self::validParcV2();
        $dayHigh['cases'][0]['jour'] = 8;
        yield 'jour hors semaine (8)' => [$dayHigh, 'jour'];

        $dayString = self::validParcV2();
        $dayString['cases'][0]['jour'] = '2';
        yield 'jour en chaîne' => [$dayString, 'jour'];

        $badHour = self::validParcV2();
        $badHour['cases'][0]['debut'] = '25:00';
        yield 'heure impossible' => [$badHour, 'HH:MM'];

        $durationString = self::validParcV2();
        $durationString['cases'][0]['duree'] = '90';
        yield 'durée en chaîne' => [$durationString, 'durée'];

        $durationZero = self::validParcV2();
        $durationZero['cases'][0]['duree'] = 0;
        yield 'durée à zéro' => [$durationZero, 'durée'];

        // Plafond d'équipes PAR CASE (bombe) : 7 > 6.
        $tooManyTeams = self::validParcV2();
        $tooManyTeams['cases'][0]['equipes'] = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
        yield 'sept équipes sur une case (plafond 6)' => [$tooManyTeams, '6'];

        // Un nom d'équipe vide dans une case : rien à apparier.
        $emptyTeamName = self::validParcV2();
        $emptyTeamName['cases'][0]['equipes'] = ['SM1', ''];
        yield 'équipe au nom vide' => [$emptyTeamName, 'équipe'];
    }

    /**
     * Un parc-v2 minimal recevable : 1 gymnase, 1 case (terrain, une équipe). `gymnases` est
     * fournie ici mais OPTIONNELLE (cf. acceptedProvider).
     *
     * @return array<string, mixed>
     */
    private static function validParcV2(): array
    {
        return [
            'format' => 'parc-v2',
            'gymnases' => [['nom' => 'MATEO']],
            'cases' => [[
                'gymnase' => 'MATEO', 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => ['SM1'],
            ]],
        ];
    }

    #[DataProvider('acceptedProvider')]
    public function testARecevableParcIsAccepted(array $parc): void
    {
        self::assertSame([], $this->validator->errors((string) json_encode($parc, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)));
    }

    #[DataProvider('refusedProvider')]
    public function testAnInvalidParcIsRefusedWithAMessageThatNamesTheProblem(array $parc, string $expectedInMessage): void
    {
        $errors = $this->validator->errors((string) json_encode($parc, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));

        self::assertNotSame([], $errors, 'ce parc doit être refusé');
        self::assertStringContainsString(
            $expectedInMessage,
            implode(' ', $errors),
            'le message doit NOMMER ce qui cloche — sinon le gestionnaire ne peut pas corriger sans ouvrir le code',
        );
    }

    public function testPlafondErrorNamesTheOffendingCaseByItsPosition(): void
    {
        // La 2e case porte 7 équipes : l'erreur cite « case » ET le rang 2, pas un refus anonyme.
        $parc = self::validParcV2();
        $parc['cases'][] = ['gymnase' => 'MATEO', 'jour' => 2, 'debut' => '19:00', 'duree' => 90, 'equipes' => ['A', 'B', 'C', 'D', 'E', 'F', 'G']];

        $message = implode(' ', $this->validator->errors((string) json_encode($parc, \JSON_THROW_ON_ERROR)));

        self::assertStringContainsString('case', mb_strtolower($message));
        self::assertStringContainsString('2', $message);
    }

    // ───────────────────────── Récupération (D4) : seulement si le parse BRUT échoue ─────────────────────────

    public function testTypographicQuotesAreRecoveredBeforeParsing(): void
    {
        // t8 (Claude Sonnet, image médiocre) : JSON parc-v2 collé avec des guillemets typographiques
        // « “ ” » — INVALIDE tel quel. La normalisation de RÉCUPÉRATION les ramène à « " » puis parse.
        $raw = (string) file_get_contents(self::FIXTURES . 't8-claude-sonnet.json');
        self::assertFalse($this->isValidJson($raw), 'le fixture t8 doit bien être du JSON INVALIDE tel quel (guillemets typographiques)');

        self::assertSame([], $this->validator->errors($raw), 'après récupération des guillemets, t8 est un parc-v2 recevable');
    }

    public function testAMarkdownFencedBlockIsUnwrappedBeforeParsing(): void
    {
        // Un chatbot qui désobéit et habille sa sortie d'un bloc ```json … ``` : dé-habillé AVANT
        // le parse (récupération), le parc reste recevable.
        $inner = (string) json_encode(self::validParcV2(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        $fenced = "```json\n" . $inner . "\n```";

        self::assertSame([], $this->validator->errors($fenced), 'un bloc Markdown ```json est dé-habillé avant le parse');
    }

    public function testValidJsonIsNeverRetouched(): void
    {
        // La récupération ne tourne QUE si le parse brut échoue. Un JSON DÉJÀ VALIDE dont une valeur
        // contient un vrai guillemet typographique garde ce guillemet : on ne le normalise pas.
        $parc = self::validParcV2();
        $parc['cases'][0]['equipes'] = ['L’équipe “Étoile”']; // valeur avec guillemets typographiques, JSON valide
        $raw = (string) json_encode($parc, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertTrue($this->isValidJson($raw));

        $parsed = $this->validator->parse($raw);
        self::assertIsArray($parsed, 'un JSON valide est décodé tel quel');
        self::assertSame('L’équipe “Étoile”', $parsed['cases'][0]['equipes'][0], 'un JSON valide n\'est jamais retouché — les guillemets de la valeur survivent');
    }

    public function testInvalidJsonBeyondRecoveryIsRefusedWithAClearError(): void
    {
        $errors = $this->validator->errors('{ ceci n\'est pas du JSON');

        self::assertNotSame([], $errors, 'un JSON illisible même après récupération doit être refusé');
        self::assertStringContainsString('JSON', implode(' ', $errors));
    }

    public function testEveryRealChatbotFixtureIsAcceptedAsParcV2(): void
    {
        // Les sorties RÉELLES des chatbots (hors t8 qui a ses guillemets typographiques, traité à
        // part) doivent toutes passer le validateur parc-v2 — gymnases présente ou non.
        foreach (['t7.json', 't9-deepseek.json', 't10-gemini.json', 't11-mistral.json', 'rillieux-gpt.json'] as $fixture) {
            $raw = (string) file_get_contents(self::FIXTURES . $fixture);
            self::assertSame([], $this->validator->errors($raw), $fixture . ' doit être un parc-v2 recevable');
        }
    }

    protected function setUp(): void
    {
        $this->validator = new ParcPayloadValidator;
    }

    private function isValidJson(string $json): bool
    {
        json_decode($json, true);

        return \JSON_ERROR_NONE === json_last_error();
    }
}
