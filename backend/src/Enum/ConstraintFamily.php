<?php

declare(strict_types=1);

namespace App\Enum;

enum ConstraintFamily: string
{
    use HasValues;

    case TIME = 'TIME';
    case DAY = 'DAY';
    case FACILITY = 'FACILITY';
    case COACH_AVAILABILITY = 'COACH_AVAILABILITY';
    // PR 6 (lot 8) — « Garder ses créneaux de saison » (Q3/Q4) : une préférence TRÈS FORTE (jamais
    // obligatoire), scope TEAM, SANS config (un marqueur). Le MOTEUR ne la reçoit jamais comme
    // contrainte (famille inconnue filtrée du payload) : son effet passe par les placements de socle
    // émis en `socleReferenceAssignments` avec `keep: true` (le solveur garde alors le jour+heure,
    // et le gymnase si possible). Créée par « Transférer au planning » pour les équipes dont le
    // coach a coché « garder mes créneaux habituels ».
    case SEASON_SLOTS = 'SEASON_SLOTS';
}
