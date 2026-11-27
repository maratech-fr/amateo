<?php

declare(strict_types=1);

namespace App\Enum;

use App\Matches\FederalFollowUp;

/**
 * Où en est un match dans FBI : à saisir, saisi (envoyé à la ligue), validé (confirmé par la ligue).
 * Lu par {@see FederalFollowUp::stateOf}. Distinct du placement : un match à placer est aussi
 * « à saisir ».
 */
enum FederalState: string
{
    case TO_ENTER = 'TO_ENTER';
    case SUBMITTED = 'SUBMITTED';
    case VALIDATED = 'VALIDATED';
}
