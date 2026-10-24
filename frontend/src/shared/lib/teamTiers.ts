import { compareNamesFr } from "@/shared/lib/nameOrder";

// Single home for the priority-tier "découpage" S/A/B/C/D shared by the teams
// step and every team selector. The order here is THE canonical team order:
// tiers by importance (id asc, 1=S…5=D), then the manager's manual tierOrder,
// then name. Reordering teams in the wizard (drag & drop) therefore reorders
// them in every selector — one source of truth.

/** Manager-facing meaning of each priority tier (bare letters are FFBB levels). */
export const TIER_MEANING: Record<string, string> = {
  S: "Fanion",
  A: "Importante",
  B: "Moyenne",
  C: "De base",
  D: "Bonus",
};

export interface TierLike {
  id: number;
  label: string;
  name: string;
}

export interface TeamLike {
  id: string;
  name: string;
  priorityTierId: number;
  tierOrder: number;
  /** Équipe « en entente (CTC) » — repère visuel porté par TeamSelect devant le nom. */
  isCtc?: boolean;
  /** Le/les partenaire(s) de l'entente, texte libre ; pour le nom accessible du repère. */
  ctcPartner?: string | null;
}

export interface TeamTierGroup<T extends TeamLike> {
  /** null = the trailing "Autres" bucket: teams whose tier is not in the loaded
   *  set (data drift, or the tiers query not resolved yet). */
  tier: TierLike | null;
  teams: T[];
}

/** Label of an "unranked" bucket, used when a team's tier is not loaded/known. */
export const ORPHAN_TIER_LABEL = "Autres";

/**
 * THE canonical team comparator: priority tier id asc (1=S…5=D), then the
 * manager's manual tierOrder, then name. Any selector/grid that lists teams
 * flat (no tier headers) sorts with this so the order matches the tiered view
 * for the normal case (every team in a loaded tier 1..5).
 *
 * It takes no tier set (that is what keeps it reusable), so it cannot detect an
 * "orphan" team whose tier is not loaded/known: such a team sorts by its raw id
 * here, whereas groupTeamsByTier routes it to the trailing "Autres" bucket. That
 * only diverges under data drift (a tier id outside the loaded set) — a flat
 * list that needs the exact "Autres last" placement should use groupTeamsByTier.
 */
export function compareTeamsByRank(a: TeamLike, b: TeamLike): number {
  return a.priorityTierId - b.priorityTierId || a.tierOrder - b.tierOrder || compareNamesFr(a.name, b.name);
}

/** "S · Fanion" — the label of a tier group (optgroup / zone header). */
export const tierGroupLabel = (tier: TierLike | null): string =>
  null === tier ? ORPHAN_TIER_LABEL : `${tier.label} · ${TIER_MEANING[tier.label] ?? tier.name}`;

/**
 * Teams grouped by the tiers actually used, tiers ordered by importance (id
 * asc), teams within a tier by manual tierOrder then name. Empty tiers are
 * dropped. **No team is ever lost**: any team whose priorityTierId is absent
 * from `tiers` (data drift, or `tiers` not loaded yet) lands in a trailing
 * `tier: null` bucket — a selector must render it or the team would silently
 * disappear and become unselectable.
 */
export function groupTeamsByTier<T extends TeamLike>(teams: T[], tiers: TierLike[]): TeamTierGroup<T>[] {
  const known = new Set(tiers.map((t) => t.id));
  const byOrderThenName = (a: T, b: T) => a.tierOrder - b.tierOrder || compareNamesFr(a.name, b.name);

  const groups: TeamTierGroup<T>[] = tiers
    .filter((tier) => teams.some((t) => t.priorityTierId === tier.id))
    .sort((a, b) => a.id - b.id)
    .map((tier) => ({ tier, teams: teams.filter((t) => t.priorityTierId === tier.id).sort(byOrderThenName) }));

  const orphans = teams.filter((t) => !known.has(t.priorityTierId)).sort(byOrderThenName);
  if (orphans.length > 0) {
    groups.push({ tier: null, teams: orphans });
  }
  return groups;
}
