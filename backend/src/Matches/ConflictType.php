<?php

declare(strict_types=1);

namespace App\Matches;

use App\Enum\HasValues;

/**
 * Les types de conflit du radar des matchs (`GET /api/fixtures/conflicts`, champ `type`) :
 * la liste existe ici, une fois. Émetteurs, empreinte, table des statuts de traitement et
 * documentation de l'API la lisent ; l'union TypeScript `ConflictType`
 * (`frontend/src/features/matches/api/conflicts.ts`), les libellés de
 * `features/matches/lib/conflictLabels.ts` et le snapshot OpenAPI la recopient, gardés par
 * `ConflictTypeListParityTest`.
 *
 * Valeur = nom : la valeur est sérialisée telle quelle dans la réponse et entre dans
 * l'empreinte d'un conflit (`ConflictFingerprinter`) — la changer orphelinerait les statuts
 * de traitement déjà posés.
 */
enum ConflictType: string
{
    use HasValues;

    case VENUE_OVERLAP = 'VENUE_OVERLAP';

    case LEAGUE_WINDOW_VIOLATION = 'LEAGUE_WINDOW_VIOLATION';

    case CLUB_RULE_VIOLATION = 'CLUB_RULE_VIOLATION';

    case TEAM_VENUE_FORBIDDEN = 'TEAM_VENUE_FORBIDDEN';

    case MATCH_MATCH = 'MATCH_MATCH';

    case MATCH_TRAINING = 'MATCH_TRAINING';

    case MATCH_COACH_UNAVAILABLE = 'MATCH_COACH_UNAVAILABLE';

    case VENUE_UNAVAILABLE = 'VENUE_UNAVAILABLE';

    case ACCESS_WINDOW_LOST = 'ACCESS_WINDOW_LOST';

    case VENUE_TRAINING_OVERLAP = 'VENUE_TRAINING_OVERLAP';

    case COMPETITION_INCOMPLETE = 'COMPETITION_INCOMPLETE';

    case AWAY_NO_FOOTPRINT = 'AWAY_NO_FOOTPRINT';

    case FRIENDLY_ON_MATCH_SLOT = 'FRIENDLY_ON_MATCH_SLOT';

    case TEAM_LEVEL_MISSING = 'TEAM_LEVEL_MISSING';
}
