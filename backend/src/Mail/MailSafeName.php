<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Le nom qu'un inconnu saisit à l'inscription, rendu SÛR pour un mail envoyé à un club (sujet
 * compris) : aucun lien, aucune adresse, aucun domaine n'y survit — c'est le vecteur
 * d'hameçonnage d'un mail « X demande à rejoindre votre club ».
 *
 * Tout mot (suite sans espace) qui porte `://`, `www.`, `@` ou une forme de domaine
 * (`.fr`, `.com/chemin`…) est retiré, partie par partie puis sur le tout, jusqu'à stabilité.
 * Un nom réduit à rien devient « Un demandeur » ; un nom démesuré est borné à 80 caractères.
 */
final class MailSafeName
{
    private const int MAX_LENGTH = 80;

    private const string FALLBACK = 'Un demandeur';

    /** Un mot porteur de lien : schéma, www, arobase, ou domaine (point + 2 lettres, en fin ou suivi de / : ? #). */
    private const string LINK_WORD = '~(?:://|www\.|@|\.[a-z]{2,}(?:[/:?#]|$))~iu';

    public static function of(string $first, string $last): string
    {
        $name = self::clean(self::clean($first) . ' ' . self::clean($last));
        if ('' === $name) {
            return self::FALLBACK;
        }

        return rtrim(mb_substr($name, 0, self::MAX_LENGTH));
    }

    private static function clean(string $value): string
    {
        do {
            $before = $value;
            $words = preg_split('~\s+~u', trim($value), -1, \PREG_SPLIT_NO_EMPTY);
            $kept = array_filter(\is_array($words) ? $words : [], static fn (string $word): bool => 1 !== preg_match(self::LINK_WORD, $word));
            $value = implode(' ', $kept);
        } while ($value !== $before);

        return $value;
    }
}
