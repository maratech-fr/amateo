<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Les équipes cochées à la naissance d'un plan (lot 4, PR 3a), selon sa nature ({@see SchedulePlanType::defaultTeamSelection}).
 */
enum PlanTeamDefault: string
{
    /** Tout le club participe. */
    case ALL = 'ALL';

    /** L'équipe fanion et les équipes importantes (les deux premiers rangs) ; à défaut, celles du meilleur rang présent. */
    case FLAGSHIP_AND_IMPORTANT = 'FLAGSHIP_AND_IMPORTANT';
}
