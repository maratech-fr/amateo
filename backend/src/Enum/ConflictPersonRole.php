<?php

declare(strict_types=1);

namespace App\Enum;

use App\Entity\CoachPlayerMembership;

/**
 * How a person is engaged with ONE team, for the conflict radar (module matchs,
 * lot « une personne = ses équipes coachées + ses équipes où elle joue »). It
 * unions the coach engagement ({@see TeamCoachRole}: MAIN/ASSISTANT, from
 * `team_coach`) with the PLAYER engagement (from an active
 * {@see CoachPlayerMembership}) into a single per-side role.
 *
 * ⚠ It is NOT a third case of {@see TeamCoachRole} (which is a PERSISTED column):
 * PLAYER never touches `team_coach`. When a person is BOTH a coach and a player of
 * the same team, the coach role wins (a MAIN who also plays is graded MAIN).
 */
enum ConflictPersonRole: string
{
    case MAIN = 'MAIN';
    case ASSISTANT = 'ASSISTANT';
    case PLAYER = 'PLAYER';
}
