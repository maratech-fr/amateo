<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\InvalidRichDocException;
use App\Service\ReleaseNoteRichDoc;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Promesse (Actualités PR B — XSS stockée A8, contenu rendu chez TOUS les clubs) : **le serveur
 * n'accepte un corps riche que dans la FORME FERMÉE de la liste blanche** (doc, paragraphe, titre de
 * niveau 3, listes à puces/numérotées ≤ 3 niveaux, texte, saut de ligne ; gras, italique, 5 couleurs
 * nommées) **et stocke une valeur RECONSTRUITE des seules clés connues** ; tout le reste (image,
 * lien, style, attribut d'événement, couleur libre, clé de prototype, document imbriqué, bornes
 * dépassées, caractère de contrôle ou bidi) est refusé avec un message lisible.
 *
 * Jumeaux (angles nommés, domaine sensible) : `CrossStack/ReleaseNoteRichDocEditorFixturesTest`
 * (ce que l'éditeur PRODUIT réellement passe ici) ; `Integration/Api/ReleaseNotesTest.php` (même
 * refus au guichet HTTP, rien n'est écrit) ; `frontend/src/shared/components/ui/rich-text.test.tsx`
 * (le rendu, lui, n'interprète jamais une chaîne comme du HTML).
 */
final class ReleaseNoteRichDocTest extends TestCase
{
    private const string INVALID = 'Le contenu mis en forme est invalide.';

    private const string TOO_DEEP = 'Les listes ne peuvent pas dépasser 3 niveaux.';

    private const string TOO_MANY_NODES = 'Le contenu contient trop d\'éléments (500 au plus).';

    private const string TOO_LONG = 'Le contenu dépasse 10 000 caractères.';

    private const string FORBIDDEN_CHARS = 'Le contenu contient des caractères non autorisés.';

    /**
     * Documents valides : la reconstruction rend EXACTEMENT la forme canonique attendue.
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function validDocuments(): iterable
    {
        $marked = static fn (string $color): array => self::doc(self::p(self::t($color, self::color($color))));
        foreach (ReleaseNoteRichDoc::COLORS as $color) {
            yield 'couleur ' . $color => [$marked($color), $marked($color)];
        }

        $rich = self::doc(self::p(
            self::t('gras', ['type' => 'bold']),
            self::t(' et '),
            self::t('italique bleu', ['type' => 'italic'], self::color('bleu')),
        ));
        yield 'gras, italique, couleur cumulés' => [$rich, $rich];

        yield 'paragraphe vide' => [self::doc(['type' => 'paragraph']), self::doc(['type' => 'paragraph'])];

        yield 'saut de ligne marqué : marques RETIRÉES à la normalisation' => [
            self::doc(self::p(self::t('avant', ['type' => 'bold']), ['type' => 'hardBreak', 'marks' => [['type' => 'bold']]], self::t('après', ['type' => 'bold']))),
            self::doc(self::p(self::t('avant', ['type' => 'bold']), ['type' => 'hardBreak'], self::t('après', ['type' => 'bold']))),
        ];

        $heading = self::doc(['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [self::t('Titre')]]);
        yield 'titre de niveau 3' => [$heading, $heading];

        $threeLevels = self::doc(self::bullet(self::item(
            self::p(self::t('niveau 1')),
            self::bullet(self::item(
                self::p(self::t('niveau 2')),
                self::ordered(['start' => 1], self::item(self::p(self::t('niveau 3')))),
            )),
        )));
        yield 'listes imbriquées sur 3 niveaux' => [$threeLevels, $threeLevels];

        $start7 = self::doc(self::ordered(['start' => 7], self::item(self::p(self::t('septième')))));
        yield 'liste numérotée commençant à 7' => [$start7, $start7];

        yield 'attribut `type: null` d\'une liste numérotée (forme TipTap v3) : accepté puis NON stocké' => [
            self::doc(self::ordered(['start' => 1, 'type' => null], self::item(self::p(self::t('un'))))),
            self::doc(self::ordered(['start' => 1], self::item(self::p(self::t('un'))))),
        ];

        $twoParagraphsInItem = self::doc(self::bullet(self::item(self::p(self::t('a')), self::p(self::t('b')))));
        yield 'plusieurs paragraphes dans une puce' => [$twoParagraphsInItem, $twoParagraphsInItem];

        $script = self::doc(self::p(self::t('<script>alert(1)</script><img src=x onerror=alert(1)>')));
        yield 'balises en TEXTE : acceptées telles quelles (le rendu ne les interprète jamais)' => [$script, $script];

        $emoji = self::doc(self::p(self::t("\u{1F3C0} \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467} \u{1F44D}\u{1F3FD} 1\u{FE0F}\u{20E3} \u{1F1EB}\u{1F1F7}")));
        yield 'emoji composés' => [$emoji, $emoji];
    }

    /**
     * Abus de forme : tous refusés par le même message (le détail part en journal).
     *
     * @return iterable<string, array{0: mixed}>
     */
    public static function malformedDocuments(): iterable
    {
        yield 'nœud image' => [self::doc(['type' => 'image', 'attrs' => ['src' => 'x', 'onerror' => 'alert(1)']])];
        yield 'image en ligne dans un paragraphe' => [self::doc(self::p(['type' => 'image', 'attrs' => ['src' => 'x']]))];
        yield 'marque lien' => [self::doc(self::p(self::t('lien', ['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']])))];
        yield 'attribut style sur un paragraphe' => [self::doc(['type' => 'paragraph', 'attrs' => ['style' => 'color:red'], 'content' => [self::t('x')]])];
        yield 'clé onclick sur un nœud texte' => [self::doc(self::p(['type' => 'text', 'text' => 'x', 'onclick' => 'alert(1)']))];
        yield 'clé text sur un paragraphe' => [self::doc(['type' => 'paragraph', 'text' => 'x'])];
        yield 'clé content sur un nœud texte' => [self::doc(self::p(['type' => 'text', 'text' => 'x', 'content' => [self::t('y')]]))];
        yield 'attrs sur la marque gras' => [self::doc(self::p(self::t('x', ['type' => 'bold', 'attrs' => ['class' => 'evil']])))];
        yield 'titre de niveau 2' => [self::doc(['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [self::t('x')]])];
        yield 'titre de niveau "3" (chaîne)' => [self::doc(['type' => 'heading', 'attrs' => ['level' => '3'], 'content' => [self::t('x')]])];
        yield 'titre sans attrs' => [self::doc(['type' => 'heading', 'content' => [self::t('x')]])];
        yield 'puce vide' => [self::doc(self::bullet(['type' => 'listItem', 'content' => []]))];
        yield 'puce commençant par une liste' => [self::doc(self::bullet(self::item(self::bullet(self::item(self::p(self::t('x')))))))];
        yield 'titre dans une puce' => [self::doc(self::bullet(self::item(self::p(self::t('a')), ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [self::t('x')]])))];
        yield 'liste numérotée start 0' => [self::doc(self::ordered(['start' => 0], self::item(self::p(self::t('x')))))];
        yield 'liste numérotée start 10000' => [self::doc(self::ordered(['start' => 10000], self::item(self::p(self::t('x')))))];
        yield 'liste numérotée type "a"' => [self::doc(self::ordered(['start' => 1, 'type' => 'a'], self::item(self::p(self::t('x')))))];
        yield 'liste à puces vide' => [self::doc(['type' => 'bulletList', 'content' => []])];
        yield 'couleur hexadécimale' => [self::doc(self::p(self::t('x', ['type' => 'noteColor', 'attrs' => ['name' => '#f00']])))];
        yield 'couleur hors palette (violet)' => [self::doc(self::p(self::t('x', self::color('violet'))))];
        yield 'couleur = clé de prototype (constructor)' => [self::doc(self::p(self::t('x', self::color('constructor'))))];
        yield 'couleur = clé de prototype (__proto__)' => [self::doc(self::p(self::t('x', self::color('__proto__'))))];
        yield 'couleur sans attrs' => [self::doc(self::p(self::t('x', ['type' => 'noteColor'])))];
        yield 'couleur avec attribut en trop' => [self::doc(self::p(self::t('x', ['type' => 'noteColor', 'attrs' => ['name' => 'bleu', 'style' => 'color:red']])))];
        yield 'marque en double' => [self::doc(self::p(self::t('x', ['type' => 'bold'], ['type' => 'bold'])))];
        yield 'texte vide' => [self::doc(self::p(self::t('')))];
        yield 'texte non chaîne' => [self::doc(self::p(['type' => 'text', 'text' => 42]))];
        yield 'type non chaîne' => [self::doc(['type' => ['paragraph']])];
        yield 'content objet (non liste)' => [['type' => 'doc', 'content' => ['a' => self::p(self::t('x'))]]];
        yield 'marks objet (non liste)' => [self::doc(self::p(['type' => 'text', 'text' => 'x', 'marks' => ['type' => 'bold']]))];
        yield 'document imbriqué dans un paragraphe' => [self::doc(self::p(self::doc(self::p(self::t('x')))))];
        yield 'document imbriqué au premier niveau' => [self::doc(self::doc(self::p(self::t('x'))))];
        yield 'document vide' => [['type' => 'doc', 'content' => []]];
        yield 'racine qui n\'est pas un doc' => [self::p(self::t('x'))];
        yield 'clé inconnue sur le doc' => [['type' => 'doc', 'content' => [self::p(self::t('x'))], 'attrs' => ['onload' => 'x']]];
        yield 'JSON chaîne (document sérialisé deux fois)' => ['{"type":"doc","content":[{"type":"paragraph"}]}'];
        yield 'liste JSON au lieu d\'un objet' => [[self::p(self::t('x'))]];
        yield 'null' => [null];
        yield 'nombre' => [42];
    }

    /** @return iterable<string, array{0: string}> */
    public static function forbiddenCharTexts(): iterable
    {
        yield 'bidi U+202E' => ["facture\u{202E}fdp.exe"];
        yield 'C0 U+0000' => ["a\u{0000}b"];
        yield 'saut U+000A dans un nœud texte' => ["a\nb"];
        yield 'C1 U+0085' => ["a\u{0085}b"];
    }

    /** @return array<string, mixed> */
    private static function doc(mixed ...$blocks): array
    {
        return ['type' => 'doc', 'content' => $blocks];
    }

    /** @return array<string, mixed> */
    private static function p(mixed ...$inline): array
    {
        return ['type' => 'paragraph', 'content' => $inline];
    }

    /** @param array<string, mixed> ...$marks @return array<string, mixed> */
    private static function t(string $text, array ...$marks): array
    {
        return [] === $marks ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'text' => $text, 'marks' => array_values($marks)];
    }

    /** @return array{type: string, attrs: array{name: string}} */
    private static function color(string $name): array
    {
        return ['type' => 'noteColor', 'attrs' => ['name' => $name]];
    }

    /** @return array<string, mixed> */
    private static function bullet(mixed ...$items): array
    {
        return ['type' => 'bulletList', 'content' => $items];
    }

    /** @param array<string, mixed> $attrs @return array<string, mixed> */
    private static function ordered(array $attrs, mixed ...$items): array
    {
        return ['type' => 'orderedList', 'attrs' => $attrs, 'content' => $items];
    }

    /** @return array<string, mixed> */
    private static function item(mixed ...$content): array
    {
        return ['type' => 'listItem', 'content' => $content];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $expected */
    #[DataProvider('validDocuments')]
    public function testWhitelistedDocumentIsAcceptedAndRebuiltFromKnownKeysOnly(array $input, array $expected): void
    {
        self::assertEquals($expected, new ReleaseNoteRichDoc()->validate($input));
    }

    #[DataProvider('malformedDocuments')]
    public function testAnythingOutsideTheClosedShapeIsRefused(mixed $input): void
    {
        $this->expectException(InvalidRichDocException::class);
        $this->expectExceptionMessage(self::INVALID);

        new ReleaseNoteRichDoc()->validate($input);
    }

    /** Seul le collage peut l'atteindre (la barre refuse Tab au 3ᵉ niveau) : le serveur le dit en clair. */
    public function testFourNestedListLevelsAreRefusedWithTheDepthMessage(): void
    {
        $fourLevels = self::doc(self::bullet(self::item(
            self::p(self::t('1')),
            self::bullet(self::item(
                self::p(self::t('2')),
                self::bullet(self::item(
                    self::p(self::t('3')),
                    self::ordered(['start' => 1], self::item(self::p(self::t('4')))),
                )),
            )),
        )));

        $this->expectException(InvalidRichDocException::class);
        $this->expectExceptionMessage(self::TOO_DEEP);

        new ReleaseNoteRichDoc()->validate($fourLevels);
    }

    /** Borne ≤ 500 nœuds, prise des deux côtés (indépendant du comptage ou non du nœud racine). */
    public function testNodeCountIsBoundedAt500(): void
    {
        $paragraphs = static fn (int $n): array => ['type' => 'doc', 'content' => array_fill(0, $n, ['type' => 'paragraph'])];

        self::assertCount(499, new ReleaseNoteRichDoc()->validate($paragraphs(499))['content'], '499 paragraphes : sous la borne');

        $this->expectException(InvalidRichDocException::class);
        $this->expectExceptionMessage(self::TOO_MANY_NODES);
        new ReleaseNoteRichDoc()->validate($paragraphs(501));
    }

    /** Borne de 10 000 points de code, CUMULÉE sur tous les nœuds texte. */
    public function testCumulatedTextIsBoundedAt10000CodePoints(): void
    {
        $atLimit = self::doc(self::p(self::t(str_repeat('é', 10000))));
        self::assertSame(10000, mb_strlen((string) new ReleaseNoteRichDoc()->validate($atLimit)['content'][0]['content'][0]['text']));

        $this->expectException(InvalidRichDocException::class);
        $this->expectExceptionMessage(self::TOO_LONG);
        new ReleaseNoteRichDoc()->validate(self::doc(self::p(self::t(str_repeat('a', 5000))), self::p(self::t(str_repeat('b', 5001)))));
    }

    #[DataProvider('forbiddenCharTexts')]
    public function testControlAndBidiCharactersInATextNodeAreRefused(string $text): void
    {
        $this->expectException(InvalidRichDocException::class);
        $this->expectExceptionMessage(self::FORBIDDEN_CHARS);

        new ReleaseNoteRichDoc()->validate(self::doc(self::bullet(self::item(self::p(self::t($text))))));
    }

    /**
     * Le miroir texte brut (`body`, lu par un ancien bundle) : titre en ligne, paragraphes et sauts
     * joints par `\n`, puce `- `, numéro `N. ` (à partir de `start`), sous-liste indentée de 2 espaces.
     */
    public function testPlainTextMirrorKeepsTheReadingStructure(): void
    {
        $doc = self::doc(
            ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [self::t('Titre')]],
            self::bullet(self::item(self::p(self::t('a')), self::bullet(self::item(self::p(self::t('b')))))),
            self::ordered(['start' => 1], self::item(self::p(self::t('c')))),
        );

        self::assertSame("Titre\n- a\n  - b\n1. c", new ReleaseNoteRichDoc()->toPlainText($doc));
    }

    public function testPlainTextMirrorJoinsParagraphsAndHardBreaksAndNumbersFromStart(): void
    {
        $doc = self::doc(
            self::p(self::t('ligne 1', ['type' => 'bold']), ['type' => 'hardBreak'], self::t('ligne 2')),
            self::p(self::t('<b>texte</b>')),
            self::ordered(['start' => 3], self::item(self::p(self::t('trois'))), self::item(self::p(self::t('quatre')))),
        );

        self::assertSame("ligne 1\nligne 2\n<b>texte</b>\n3. trois\n4. quatre", new ReleaseNoteRichDoc()->toPlainText($doc));
    }
}
