<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\OpponentVenueLink;

/**
 * Maison unique de la CLÉ d'appariement d'un adversaire (P2-54, lot « UX appariement »).
 *
 * L'appariement « libellé de salle → gymnase » ({@see OpponentVenueLink}) est
 * keyé sur le code organisme fédéral. Un adversaire SANS code fédéral (amical saisi à la
 * main, coupe non appariée…) n'avait donc aucune clé : ses rencontres restaient hors du
 * trajet. On lui dérive une clé SENTINELLE stable de son LIBELLÉ — sans migration, sans
 * catalogue partagé (un sans-code n'alimente jamais le partagé fédéral).
 *
 * `'X' . substr(sha1-256 du libellé minusculé-trimé, 0, 40)` = 41 caractères alphanumériques :
 *   - passe la borne `[A-Za-z0-9]+` des routes `/api/opponents/{code}/…` ;
 *   - tient dans le `VARCHAR(64)` de `opponent_venue_link.opponent_organisme_code` ;
 *   - se re-dérive à l'identique côté contrôleur, projection et auto-locator (une seule maison).
 *
 * La dérivation est ALIGNÉE sur la clé de groupe `'label:' . mb_strtolower(trim($label))` du
 * contrôleur d'affichage : deux rencontres du même adversaire sans code partagent la clé.
 */
final class OpponentPairingKey
{
    /**
     * La clé d'appariement d'un adversaire : son code fédéral s'il en a un, sinon une clé
     * sentinelle dérivée de son libellé (locale, jamais fédérale).
     */
    public function fromOpponent(?string $code, string $opponentLabel): string
    {
        if (null !== $code && '' !== $code) {
            return $code;
        }

        return 'X' . substr(hash('sha256', mb_strtolower(trim($opponentLabel))), 0, 40);
    }

    /**
     * Vrai si `$key` est une clé SENTINELLE (adversaire sans code fédéral) — le contrôleur
     * force alors un appariement LOCAL seul (jamais de ref fédérale, jamais de crédit partagé).
     */
    public function isSentinel(string $key): bool
    {
        return 41 === \strlen($key) && 'X' === $key[0] && 1 === preg_match('/^[0-9a-f]{40}$/', substr($key, 1));
    }
}
