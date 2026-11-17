import type { Team, TeamPeriodOverride, TeamPeriodOverridePayload } from "../api";

/**
 * Corps de la surcharge de période d'une équipe — constructeur UNIQUE côté écran.
 *
 * L'API remplace la ligne en entier (`PUT`, `null` = valeur de saison) : un geste sur l'un des champs
 * (active, séances par semaine, séances max par jour) repart donc de la ligne existante et ne touche
 * que le champ du geste. Une valeur égale à celle de la saison vaut la saison (`null`).
 *
 * Rend `null` quand il n'y a plus rien à surcharger (équipe active, séances et plafond de saison) :
 * l'appelant supprime alors la ligne existante, ou n'écrit rien s'il n'y en a pas.
 */
export function periodOverrideBody(
  schedulePlanId: string,
  team: Team,
  existing: TeamPeriodOverride | undefined,
  patch: { isActive?: boolean; sessionsPerWeek?: number | null; maxSessionsPerDay?: number | null },
): TeamPeriodOverridePayload | null {
  const isActive = patch.isActive ?? existing?.isActive ?? true;
  const sessions = undefined !== patch.sessionsPerWeek ? patch.sessionsPerWeek : (existing?.sessionsPerWeek ?? null);
  const maxPerDay = undefined !== patch.maxSessionsPerDay ? patch.maxSessionsPerDay : (existing?.maxSessionsPerDay ?? null);
  const sessionsPerWeek = sessions === team.sessionsPerWeek ? null : sessions;
  const maxSessionsPerDay = maxPerDay === seasonMaxPerDay(team) ? null : maxPerDay;

  if (isActive && null === sessionsPerWeek && null === maxSessionsPerDay) {
    return null;
  }

  return { schedulePlanId, teamId: team.id, isActive, sessionsPerWeek, maxSessionsPerDay };
}

/** Séances max par jour que la période applique : la surcharge si elle est posée, sinon la saison. */
export function effectiveMaxPerDay(team: Team, override: TeamPeriodOverride | undefined): number {
  return override?.maxSessionsPerDay ?? seasonMaxPerDay(team);
}

/** Valeur de saison ; 1 quand le serveur ne l'a pas servie (le défaut de l'API). */
function seasonMaxPerDay(team: Team): number {
  return team.maxSessionsPerDay ?? 1;
}
