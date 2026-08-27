<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * D'où vient le temps de trajet (et le lieu) d'un adversaire pour UN club précis
 * (P2-54 RMM-9 PR-3, patron {@see VenueTravelTimeSource}).
 *
 * `AUTO` : calculé par le résolveur (itinéraire IGN, siège du club → lieu de
 * l'adversaire connu du global). `MANUAL` : le gestionnaire a choisi lui-même le
 * gymnase de l'adversaire (surcharge le lieu du global) — sa correction terrain.
 * Le cœur du patron : une valeur MANUAL n'est JAMAIS écrasée par un recalcul AUTO.
 */
enum OpponentTravelSource: string
{
    use HasValues;

    case AUTO = 'AUTO';

    case MANUAL = 'MANUAL';
}
