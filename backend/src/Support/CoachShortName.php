<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Le nom COURT d'un coach pour l'export PDF : « prénom + initiale du nom » (« Karim B. »).
 *
 * C'est la seule forme qui atterrit sur un document remis aux familles : jamais le nom de
 * famille complet, jamais un identifiant interne. Un nom VIDE rend `null` — la ligne de coach
 * est alors OMISE (promesse fondateur « pas de coach → rien »), et c'est à la source que ce
 * null naît. L'initiale est mb-safe : « Étienne » → « É. », jamais un demi-octet.
 */
final class CoachShortName
{
    public static function from(?string $firstName, ?string $lastName): ?string
    {
        $first = trim($firstName ?? '');
        $last = trim($lastName ?? '');
        if ('' === $first && '' === $last) {
            return null;
        }

        // Un prénom seul reste « Karim », jamais « Karim . » : pas d'initiale vide inventée.
        $initial = '' === $last ? '' : mb_substr($last, 0, 1) . '.';

        return trim(\sprintf('%s %s', $first, $initial));
    }
}
