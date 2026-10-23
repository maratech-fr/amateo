<?php

declare(strict_types=1);

namespace App\Tests\Unit\ParcImport;

use App\Service\ParcImport\ParcPayloadValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Chantier G PR1 — LE validateur du format `parc-v1`, le texte brut que le chatbot
 * rend et que le gestionnaire colle. C'est un ACCÉLÉRATEUR DE SAISIE, pas la vérité :
 * le validateur refuse ce qui ne peut pas être lu sans ambiguïté et NOMME le fautif
 * par élément (« équipe n°3 : genre inconnu »), pour que le gestionnaire corrige dans
 * l'aperçu sans jamais ouvrir le code.
 *
 * Décisions tranchées (option A, cadrage G v2 2026-10-23) :
 *  - `format` est un VERROU de version : une version inconnue (« parc-v2 », un ancien
 *    « socle-v1 »…) est refusée — jamais lue « au mieux ».
 *  - Sections : `equipes`, `gymnases`, `creneaux`, `mutualisations`, `reservations`
 *    sont REQUISES (listes, éventuellement VIDES) ; `coachs` est OPTIONNELLE (absente
 *    = aucun coach, jamais une erreur).
 *  - Champs HORS PÉRIMÈTRE (`niveau`, `adresse`, `effectif`, et toute clé non connue) :
 *    IGNORÉS, jamais une erreur. Reco la plus simple ET la plus robuste — le chatbot
 *    n'est pas sous notre contrôle et peut enrichir sa sortie ; on ne lit QUE la liste
 *    blanche (le reste tombe en silence) plutôt que de rougir un import pour un extra.
 *    Le témoin « hors périmètre ignoré » le prouve dans les DEUX sens (présent = vert).
 *  - Les TÉMOINS comptent autant que les refus : un validateur qui refuse tout ferait
 *    passer la moitié de ce fichier au rouge sur les cas acceptés.
 *
 * Le contrat : {@see ParcPayloadValidator::errors()} prend le JSON BRUT (il possède le
 * décodage, donc « JSON invalide → erreur claire » se prouve ici) et rend la liste des
 * motifs de refus, VIDE si le parc est recevable.
 */
#[Group('phase1')]
final class ParcPayloadValidatorTest extends TestCase
{
    private ParcPayloadValidator $validator;

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function acceptedProvider(): iterable
    {
        yield 'baseline complet' => [self::validParc()];

        // `coachs` optionnelle : présente (nom + équipe liée) = recevable.
        $withCoachs = self::validParc();
        $withCoachs['coachs'] = [['nom' => 'Alice Martin', 'equipe' => 'SM1']];
        yield 'section coachs présente (optionnelle)' => [$withCoachs];

        // Les sections requises peuvent être VIDES (un club sans mutualisation ni réservation).
        $empty = self::validParc();
        $empty['reservations'] = [];
        yield 'sections requises vides' => [$empty];

        // `equipes_en_parallele` omis = défaut (un seul terrain) — jamais exigé.
        $noParallel = self::validParc();
        unset($noParallel['creneaux'][0]['equipes_en_parallele']);
        yield 'equipes_en_parallele omis (défaut)' => [$noParallel];

        // Genre : les trois valeurs admises, et l'absence (déduit plus tard, jamais bloquant).
        $genres = self::validParc();
        $genres['equipes'] = [
            ['nom' => 'SF1', 'genre' => 'F'],
            ['nom' => 'U13MIXTE', 'genre' => 'MIXTE'],
            ['nom' => 'SansGenre'],
        ];
        yield 'genres F / MIXTE / absent' => [$genres];

        // Champs hors périmètre : présents sur chaque élément, IGNORÉS (reste vert).
        $extra = self::validParc();
        $extra['equipes'][0] += ['niveau' => 'R2', 'effectif' => 14];
        $extra['gymnases'][0] += ['adresse' => '1 rue du Stade'];
        $extra['fermetures'] = [['gymnase' => 'Gymnase Mateo']]; // section inconnue, ignorée
        yield 'champs et section hors périmètre ignorés' => [$extra];
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusedProvider(): iterable
    {
        // `format` — le verrou de version.
        $noFormat = self::validParc();
        unset($noFormat['format']);
        yield 'format absent' => [$noFormat, 'format'];

        $badFormat = self::validParc();
        $badFormat['format'] = 'parc-v2';
        yield 'version inconnue' => [$badFormat, 'parc-v1'];

        $legacyFormat = self::validParc();
        $legacyFormat['format'] = 'socle-v1';
        yield 'ancien format refusé' => [$legacyFormat, 'parc-v1'];

        // Sections requises : présence + forme (liste).
        $noGymnases = self::validParc();
        unset($noGymnases['gymnases']);
        yield 'section requise absente (gymnases)' => [$noGymnases, 'gymnases'];

        $notAList = self::validParc();
        $notAList['equipes'] = 'SM1';
        yield 'section en chaîne, pas une liste' => [$notAList, 'equipes'];

        // Élément sans nom : rien à apparier.
        $noName = self::validParc();
        $noName['equipes'] = [['genre' => 'M']];
        yield 'équipe sans nom' => [$noName, 'nom'];

        // `jour` ISO 1..7.
        $dayHigh = self::validParc();
        $dayHigh['creneaux'][0]['jour'] = 8;
        yield 'jour hors semaine (8)' => [$dayHigh, 'jour'];

        $dayZero = self::validParc();
        $dayZero['creneaux'][0]['jour'] = 0;
        yield 'jour à zéro' => [$dayZero, 'jour'];

        $dayString = self::validParc();
        $dayString['creneaux'][0]['jour'] = '2';
        yield 'jour en chaîne' => [$dayString, 'jour'];

        // `debut` HH:MM.
        $hourImpossible = self::validParc();
        $hourImpossible['creneaux'][0]['debut'] = '25:00';
        yield 'heure impossible' => [$hourImpossible, 'HH:MM'];

        $hourLetters = self::validParc();
        $hourLetters['creneaux'][0]['debut'] = '18h';
        yield 'heure en toutes lettres' => [$hourLetters, 'HH:MM'];

        // `duree` en minutes (entier > 0).
        $durationString = self::validParc();
        $durationString['creneaux'][0]['duree'] = '90';
        yield 'durée en chaîne' => [$durationString, 'durée'];

        $durationZero = self::validParc();
        $durationZero['creneaux'][0]['duree'] = 0;
        yield 'durée à zéro' => [$durationZero, 'durée'];

        // `equipes_en_parallele` 1..3 (terrain divisible en 2 ou 3, jamais au-delà).
        $parallelHigh = self::validParc();
        $parallelHigh['creneaux'][0]['equipes_en_parallele'] = 4;
        yield 'équipes en parallèle au-delà de 3' => [$parallelHigh, '1 à 3'];

        $parallelZero = self::validParc();
        $parallelZero['creneaux'][0]['equipes_en_parallele'] = 0;
        yield 'équipes en parallèle à zéro' => [$parallelZero, '1 à 3'];

        // `genre` M/F/MIXTE quand il est présent.
        $badGender = self::validParc();
        $badGender['equipes'][0]['genre'] = 'X';
        yield 'genre inconnu' => [$badGender, 'genre'];
    }

    /**
     * Un parc-v1 minimal mais réaliste : 1 gymnase, 1 équipe, 1 créneau (terrain
     * divisé en 2 via `equipes_en_parallele`), 1 réservation. `coachs` et
     * `mutualisations` absents/vides à dessein (optionnel / liste vide recevable).
     *
     * @return array<string, mixed>
     */
    private static function validParc(): array
    {
        return [
            'format' => 'parc-v1',
            'gymnases' => [['nom' => 'Gymnase Mateo']],
            'equipes' => [['nom' => 'SM1', 'genre' => 'M', 'seances' => 3]],
            'creneaux' => [[
                'gymnase' => 'Gymnase Mateo', 'jour' => 2, 'debut' => '18:00',
                'duree' => 90, 'equipes_en_parallele' => 2,
            ]],
            'mutualisations' => [],
            'reservations' => [[
                'equipe' => 'SM1', 'gymnase' => 'Gymnase Mateo', 'jour' => 2, 'debut' => '18:00',
            ]],
        ];
    }

    #[DataProvider('acceptedProvider')]
    public function testARecevableParcIsAccepted(array $parc): void
    {
        self::assertSame([], $this->validator->errors((string) json_encode($parc, \JSON_THROW_ON_ERROR)));
    }

    #[DataProvider('refusedProvider')]
    public function testAnInvalidParcIsRefusedWithAMessageThatNamesTheProblem(array $parc, string $expectedInMessage): void
    {
        $errors = $this->validator->errors((string) json_encode($parc, \JSON_THROW_ON_ERROR));

        self::assertNotSame([], $errors, 'ce parc doit être refusé');
        self::assertStringContainsString(
            $expectedInMessage,
            implode(' ', $errors),
            'le message doit NOMMER ce qui cloche — sinon le gestionnaire ne peut pas corriger sans ouvrir le code',
        );
    }

    public function testInvalidJsonIsRefusedWithAClearError(): void
    {
        $errors = $this->validator->errors('{ ceci n\'est pas du JSON');

        self::assertNotSame([], $errors, 'un JSON illisible doit être refusé');
        self::assertStringContainsString('JSON', implode(' ', $errors), 'l\'erreur doit dire que le texte collé n\'est pas du JSON');
    }

    public function testANonObjectPayloadIsRefused(): void
    {
        // JSON valide mais pas un objet parc (une liste nue, une chaîne) : rien à lire.
        self::assertNotSame([], $this->validator->errors('[]'), 'une liste nue n\'est pas un parc');
        self::assertNotSame([], $this->validator->errors('"parc-v1"'), 'une chaîne nue n\'est pas un parc');
    }

    public function testErrorNamesTheOffendingElementByItsOrdinalPosition(): void
    {
        // La 3e équipe porte un genre inconnu : l'erreur doit citer « équipe » ET le
        // rang 3 (« équipe n°3 : genre inconnu ») — pas un message global anonyme.
        $parc = self::validParc();
        $parc['equipes'] = [
            ['nom' => 'SM1', 'genre' => 'M'],
            ['nom' => 'SF1', 'genre' => 'F'],
            ['nom' => 'U11M1', 'genre' => 'X'],
        ];

        $message = implode(' ', $this->validator->errors((string) json_encode($parc, \JSON_THROW_ON_ERROR)));

        self::assertStringContainsString('équipe', $message);
        self::assertStringContainsString('3', $message);
        self::assertStringContainsString('genre', $message);
    }

    protected function setUp(): void
    {
        $this->validator = new ParcPayloadValidator;
    }
}
