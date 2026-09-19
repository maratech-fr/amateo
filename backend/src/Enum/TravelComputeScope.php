<?php

declare(strict_types=1);

namespace App\Enum;

use App\Message\ComputeTravelTimesMessage;
use App\Service\Geo\OpponentTravelResolver;
use App\Service\Geo\VenueTravelTimeAutofillService;

/**
 * C6 — la PORTÉE d'un calcul de trajets asynchrone ({@see ComputeTravelTimesMessage}) :
 *   - OPPONENTS    : les trajets siège du club → lieux adverses ({@see OpponentTravelResolver});
 *   - VENUE_MATRIX : la matrice de trajets entre gymnases du wizard ({@see VenueTravelTimeAutofillService}).
 */
enum TravelComputeScope: string
{
    case OPPONENTS = 'OPPONENTS';
    case VENUE_MATRIX = 'VENUE_MATRIX';
}
