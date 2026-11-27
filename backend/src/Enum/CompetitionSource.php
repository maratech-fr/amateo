<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * D'où vient une compétition : de la fédération (import FBI, appariement, canal API) ou de la main du club
 * (`POST /api/competitions`, « Amicaux » d'office). Seuls les matchs d'une compétition `FBI` sont suivis dans FBI :
 * un match d'une compétition `MANUAL` s'arrête à « Placé ». Une compétition `MANUAL` devient `FBI` quand la
 * fédération y range des matchs ; jamais l'inverse, et « Amicaux » reste `MANUAL`.
 */
enum CompetitionSource: string
{
    use HasValues;

    case FBI = 'FBI';
    case MANUAL = 'MANUAL';
}
