<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * D'où vient un temps de trajet stocké entre deux gymnases (P2-53 RMM-8).
 *
 * `AUTO` : calculé par l'autofill (itinéraire IGN). `MANUAL` : posé/corrigé par
 * le gestionnaire — « la RÉALITÉ terrain qu'il connaît mieux que nous ». Le cœur
 * de la feature : une valeur MANUAL n'est JAMAIS écrasée par un recalcul (« le
 * 15 min métro A survit à tous les re-calculs »). La colonne source ne porte de
 * valeur que quand la minute correspondante est renseignée (null sinon).
 */
enum VenueTravelTimeSource: string
{
    use HasValues;

    case AUTO = 'AUTO';

    case MANUAL = 'MANUAL';
}
