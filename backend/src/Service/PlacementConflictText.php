<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\MatchPlacementRunStatus;

/**
 * Le texte du 409 d'une seconde demande de placement, selon le dernier run du club et le délai
 * restant de son verrou.
 *
 *  - dernier run en file (PENDING) : il n'a pas démarré, le TTL posé à l'enfilage n'est pas une fin
 *    (le worker le réarme au départ) → aucun délai promis, aucun « réessayez » : l'écran relit le
 *    run et passe en « en cours » juste après, un reclic coûterait une unité de quota pour rien ;
 *  - sinon (en cours, ou terminal dont le calcul orphelin tient encore la clé) : le délai restant
 *    du verrou — réarmé au budget du run, plafond du calcul — arrondi à la minute supérieure, ou le
 *    repli sans délai si la clé n'est pas lisible.
 */
final class PlacementConflictText
{
    public static function for(?MatchPlacementRunStatus $latestStatus, ?int $remainingSeconds): string
    {
        if (MatchPlacementRunStatus::PENDING === $latestStatus) {
            return 'Un placement est déjà demandé pour ce club — il démarrera dès que possible.';
        }

        if (null === $remainingSeconds || $remainingSeconds <= 0) {
            return 'Un placement est déjà en cours — réessayez dans un instant.';
        }

        $minutes = (int) ceil($remainingSeconds / 60);

        return 1 === $minutes
            ? 'Un placement est déjà en cours — il se terminera dans 1 minute au plus.'
            : \sprintf('Un placement est déjà en cours — il se terminera dans %d minutes au plus.', $minutes);
    }
}
