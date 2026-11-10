<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\TextSanity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Promesse (Actualités PR B, contenu stocké rendu chez TOUS les clubs) : **un texte de note refuse
 * les caractères qui trompent la lecture ou cassent le stockage — C0 (sauf la tabulation), C1 et les
 * commandes bidi — mais laisse passer TOUS les emoji composés** (ZWJ, sélecteur de variante, teint,
 * touche, drapeau), qui reposent sur des caractères de format (`\p{Cf}`) HORS des plages interdites.
 *
 * Un ban `\p{Cf}` en bloc casserait « 👨‍👩‍👧 » (U+200D) : les cas emoji ci-dessous le rougissent.
 * Les sites d'appel (titre, corps brut, nœuds texte du corps riche) sont prouvés par
 * `Integration/Api/ReleaseNotesTest.php` et `Unit/ReleaseNoteRichDocTest.php`.
 */
final class TextSanityTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function acceptedTexts(): iterable
    {
        yield 'français accentué' => ['Nouveautés : l\'été, à côté — « déjà » !'];
        yield 'emoji famille (ZWJ U+200D)' => ["\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"];
        yield 'cœur avec sélecteur de variante (VS16 U+FE0F)' => ["\u{2764}\u{FE0F}"];
        yield 'pouce avec teint (U+1F3FD)' => ["\u{1F44D}\u{1F3FD}"];
        yield 'touche 1 (U+FE0F U+20E3)' => ["1\u{FE0F}\u{20E3}"];
        yield 'drapeau (indicateurs régionaux)' => ["\u{1F1EB}\u{1F1F7}"];
        yield 'ballon de basket' => ["\u{1F3C0}"];
        yield 'tabulation U+0009' => ["avant\tapres"];
        yield 'espace insécable U+00A0' => ["10\u{00A0}000"];
        yield 'balise html (du TEXTE, pas une menace ici)' => ['<img src=x onerror=alert(1)>'];
    }

    /** @return iterable<string, array{0: string}> */
    public static function refusedTexts(): iterable
    {
        yield 'U+202E (RLO, renversement bidi)' => ["abc\u{202E}def"];
        yield 'U+202A (LRE)' => ["abc\u{202A}def"];
        yield 'U+2066 (LRI)' => ["abc\u{2066}def"];
        yield 'U+2069 (PDI)' => ["abc\u{2069}def"];
        yield 'U+0000 (NUL, refusé par JSONB)' => ["abc\u{0000}def"];
        yield 'U+000A (saut : passe par hardBreak/paragraphe)' => ["abc\ndef"];
        yield 'U+000D' => ["abc\rdef"];
        yield 'U+001F' => ["abc\u{001F}def"];
        yield 'U+007F (DEL)' => ["abc\u{007F}def"];
        yield 'U+0085 (NEL, C1)' => ["abc\u{0085}def"];
        yield 'U+009F (C1)' => ["abc\u{009F}def"];
    }

    #[DataProvider('acceptedTexts')]
    public function testLegitimateTextAndComposedEmojiPass(string $text): void
    {
        self::assertFalse(new TextSanity()->hasForbiddenChars($text), \sprintf('« %s » (%s) doit passer.', $text, bin2hex($text)));
    }

    #[DataProvider('refusedTexts')]
    public function testControlAndBidiCharactersAreRefused(string $text): void
    {
        self::assertTrue(new TextSanity()->hasForbiddenChars($text), \sprintf('%s doit être refusé.', bin2hex($text)));
    }
}
