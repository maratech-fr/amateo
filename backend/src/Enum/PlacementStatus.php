<?php

declare(strict_types=1);

namespace App\Enum;

use App\Matches\FederalFollowUp;

/**
 * Le placement stocké d'un match : à placer ou placé (gymnase et heure pour un domicile). Fermé par un CHECK en
 * base. Le stade servi (à placer, placé, saisi, validé) le compose avec le suivi fédéral
 * ({@see FederalFollowUp::stageOf}) : ne pas lire l'un pour l'autre.
 */
enum PlacementStatus: string
{
    use HasValues;

    case UNPLACED = 'UNPLACED';
    case PLACED = 'PLACED';
}
