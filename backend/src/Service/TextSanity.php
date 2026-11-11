<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Caractères refusés dans un texte saisi puis affiché chez TOUS les clubs (journal de nouveautés) :
 * C0 sauf la tabulation (U+0000 que JSONB refuse, les sauts U+000A/U+000D — un saut passe par un
 * paragraphe ou un saut de ligne du corps riche), DEL et C1 (U+007F–U+009F), et les commandes bidi
 * (U+202A–U+202E, U+2066–U+2069) qui retournent l'affichage d'un texte.
 *
 * Jamais d'interdiction de `\p{Cf}` en bloc : les emoji composés reposent sur des caractères de
 * format HORS de ces plages (U+200D ZWJ, U+FE0F sélecteur de variante, U+20E3 touche, teints,
 * indicateurs régionaux). Une chaîne qui n'est pas de l'UTF-8 valide est refusée aussi.
 */
final readonly class TextSanity
{
    private const string FORBIDDEN = '/[\x{0000}-\x{0008}\x{000A}-\x{001F}\x{007F}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    public function hasForbiddenChars(string $text): bool
    {
        return 0 !== preg_match(self::FORBIDDEN, $text);
    }
}
