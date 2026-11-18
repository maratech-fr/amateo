<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Levée quand la séance à déplacer (déplacer, essai compris, échanger, déplacer un groupe) est
 * verrouillée ET tenue par une réservation du plan de sa version, à sa case exacte (même équipe,
 * gymnase, jour, heure). Refus AVANT le moteur, RIEN n'est écrit. Le contrôleur la mappe en 422
 * `source_reserved` avec son message, qui dit où retirer la réservation.
 */
final class SourceReservedException extends RuntimeException
{
    public static function forTeam(string $teamName, bool $period): self
    {
        return new self(\sprintf(
            '%s tient ce créneau par une réservation de gymnase : il ne se déplace pas depuis le planning. Retirez d\'abord sa réservation (%s), puis replacez la séance.',
            $teamName,
            self::where($period),
        ));
    }

    public static function forGroup(bool $period): self
    {
        return new self(\sprintf(
            'Ce groupe tient ce créneau par une réservation de gymnase : il ne se déplace pas depuis le planning. Retirez d\'abord sa réservation (%s), puis replacez la séance du groupe.',
            self::where($period),
        ));
    }

    private static function where(bool $period): string
    {
        return $period
            ? 'Wizard de la période, étape Contraintes, onglet Réserver, ou Récapitulatif'
            : 'Wizard, étape Contraintes, onglet Réserver, ou Récapitulatif';
    }
}
