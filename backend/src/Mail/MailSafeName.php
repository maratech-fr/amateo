<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Le nom qu'un inconnu saisit à l'inscription, rendu SÛR pour un mail envoyé à un club (sujet
 * compris) : aucun lien, aucune adresse, aucun domaine n'y entre — c'est le vecteur
 * d'hameçonnage d'un mail « X demande à rejoindre votre club ».
 *
 * LISTE BLANCHE : un prénom ou un nom n'accepte que des lettres (accents compris), des espaces,
 * des traits d'union et des apostrophes (droite ou typographique). Un seul autre caractère
 * (point, virgule, barre, deux-points, arobase, ponctuation, chiffre…) dans l'un ou l'autre, et
 * le nom ENTIER devient « Un demandeur » — jamais nettoyé mot à mot (une liste noire de formes de
 * domaine laissait passer « x.com, » que les messageries rendent cliquable). Un nom vide devient
 * aussi « Un demandeur » ; un nom démesuré est borné à 80 caractères.
 */
final class MailSafeName
{
    private const int MAX_LENGTH = 80;

    private const string FALLBACK = 'Un demandeur';

    /** Lettres (et leurs marques combinantes), espaces, traits d'union, apostrophes ' et ’. */
    private const string ALLOWED = '~^[\p{L}\p{M} \'’-]*$~u';

    public static function of(string $first, string $last): string
    {
        $parts = [];
        foreach ([$first, $last] as $part) {
            $part = trim((string) preg_replace('~\s+~u', ' ', $part));
            if (1 !== preg_match(self::ALLOWED, $part)) {
                return self::FALLBACK;
            }
            if ('' !== $part) {
                $parts[] = $part;
            }
        }
        if ([] === $parts) {
            return self::FALLBACK;
        }

        return rtrim(mb_substr(implode(' ', $parts), 0, self::MAX_LENGTH));
    }
}
