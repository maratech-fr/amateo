<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How precisely an opponent's location is known in the global `opponent_directory`
 * (P2-54 RMM-9). VENUE = the exact gym (a salle from the FFBB rencontre hit or an
 * unambiguous salle-name match); CITY = only the opponent organisme's commune.
 * VENUE always outranks CITY: a more precise resolution replaces a less precise
 * one, never the reverse ({@see App\Service\Basketball\OpponentLocationResolver}).
 */
enum OpponentLocationPrecision: string
{
    use HasValues;

    case VENUE = 'VENUE';
    case CITY = 'CITY';
}
