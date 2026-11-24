<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Comment un gymnase se comporte SUR UNE PÉRIODE, tel que l'API le lit et l'écrit. Il se range
 * sur la ligne du gymnase dans le plan de période (`PlanVenue::mode()`).
 *
 * Le 3e mode, INHERIT, est le DÉFAUT et n'est JAMAIS une valeur : une ligne au défaut
 * signifie « ce gymnase garde ses créneaux de saison » — pour y revenir on SUPPRIME le réglage.
 *
 * DISABLED = le gymnase ne sert pas du tout cette période (la ligne ne participe pas).
 * BLANK    = on repart d'une grille vierge (l'effet de fermeture est levé).
 */
enum VenuePeriodMode: string
{
    use HasValues;

    case DISABLED = 'DISABLED';
    case BLANK = 'BLANK';
}
