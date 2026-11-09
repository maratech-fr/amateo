<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Pourquoi le rappel d'une vacance scolaire est fermé pour un club : le club l'a ignorée
 * (IGNORED), une coupure/fermeture couvre chacune de ses semaines de vacances (COVERED), ou un
 * planning de période existe sur une entrée qui la chevauche (PLANNED).
 */
enum HolidayCloseReason: string
{
    case IGNORED = 'ignored';
    case PLANNED = 'planned';
    case COVERED = 'covered';
}
