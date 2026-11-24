<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The three kinds of SchedulePlan (ADR-0002). A SchedulePlan is a first-order,
 * named container; its Schedules are its versions. SEASON = the base plan of the
 * whole season (one per season); CLOSURE / HOLIDAY = a bounded secondary plan
 * bound to a CalendarEntry period (its calendarEntryId is set).
 */
enum SchedulePlanType: string
{
    /** Les équipes cochées à la naissance du plan : des vacances reprennent avec l'équipe fanion et les importantes. */
    public function defaultTeamSelection(): PlanTeamDefault
    {
        return self::HOLIDAY === $this ? PlanTeamDefault::FLAGSHIP_AND_IMPORTANT : PlanTeamDefault::ALL;
    }

    /**
     * Une règle de saison s'applique-t-elle par défaut à un plan de cette nature ? Une fermeture applique tout ; des
     * vacances (reprise) n'appliquent que les règles de tout le club — la période a sa propre vie, sa grille, les coachs
     * réellement présents (décision fondateur 2026-10-10). Le plan de saison applique tout. Défaut écrit sur la ligne
     * par `PlanComposition::ensureLines`, réécrit au changement de portée.
     */
    public function keepsSocleConstraintByDefault(ConstraintScope $scope): bool
    {
        return self::HOLIDAY !== $this || ConstraintScope::CLUB === $scope;
    }

    /** Un plan de fermeture naît avec une copie des groupes mutualisés du socle ; des vacances n'en copient aucun. */
    public function copiesSharedBlocksAtBirth(): bool
    {
        return self::CLOSURE === $this;
    }
    case SEASON = 'SEASON';
    case CLOSURE = 'CLOSURE';
    case HOLIDAY = 'HOLIDAY';
}
