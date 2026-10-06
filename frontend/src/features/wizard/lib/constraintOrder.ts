import type { Coach, Constraint, PriorityTier, Team, TeamTag, Venue } from "@/features/wizard/api";
import { retiredWord } from "@/shared/lib/coachWording";
import { groupTeamsByTier, tierGroupLabel } from "@/shared/lib/teamTiers";
import { targetTagNames } from "@/shared/lib/tagTeamIds";

import { AXIS_LABEL, groupTagsByAxis, KNOWN_AXES, tagLabel } from "./tagLabels";
import { compareNamesFr } from "@/shared/lib/nameOrder";

/** A grouped section of the constraint list: a header + its constraint rows. */
export interface ConstraintSection {
  key: string;
  label: string;
  items: Constraint[];
}

/** Constraint families in the order of the wizard's constraint tabs. */
export const FAMILY_ORDER = ["TIME", "DAY", "FACILITY", "COACH_AVAILABILITY"] as const;

export const FAMILY_LABEL: Record<string, string> = {
  TIME: "Horaire",
  DAY: "Jours",
  FACILITY: "Gymnase",
  COACH_AVAILABILITY: "Dispo coach",
};

/** Absent-from-refs fallback — shared so both order sources tie identically. */
export const RANK_FALLBACK = 9999;

/** Canonical tag order (by axis then label) — the single source for BOTH the
 *  constraint tab's list and the recap, so they never diverge. */
export function orderedTagNames(tags: TeamTag[]): string[] {
  return groupTagsByAxis(tags).flatMap((g) => g.tags).map((t) => t.name);
}

/** The venue a FACILITY constraint refers to (any of its keys). */
function constraintVenueId(c: Constraint): string | null {
  const cfg = c.config ?? {};
  for (const k of ["forcedVenueId", "preferredVenueId", "forbiddenVenueId", "minAtVenueId", "venueId"]) {
    if ("string" === typeof cfg[k]) {
      return cfg[k] as string;
    }
  }
  // P2-22 D4 — une fermeture (venue_closed) porte son gymnase dans `scopeTargetId` (scope
  // FACILITY), pas dans config. Repli APRÈS les clés config pour la ranger sous SON gymnase ;
  // les contraintes config-based (préfère/impose…) ont déjà répondu au-dessus.
  if ("FACILITY" === c.family && "string" === typeof c.scopeTargetId) {
    return c.scopeTargetId;
  }
  return null;
}

interface GroupContext {
  teams: Team[];
  tiers: PriorityTier[];
  tags: TeamTag[];
  coaches: Coach[];
  coachPlayerIds: Set<string>;
  venues: Venue[];
  coachName: (id: string) => string;
  venueName: (id: string) => string;
}

/**
 * Group a family's constraints into labelled sections. The grouping DIMENSION
 * depends on the family (user request):
 * - COACH_AVAILABILITY → staffing group (Salariés / Coachs-joueurs / Bénévoles) ;
 * - FACILITY → the venue the rule targets ;
 * - TIME / DAY → the target's AXIS (Genre / Niveau / Âge) for group rules, then
 *   per-team (rank order), then club-wide.
 * Shared by the constraint tab AND the recap so both group identically.
 */
export function groupConstraints(constraints: Constraint[], family: string, ctx: GroupContext): ConstraintSection[] {
  const push = (m: Map<string, Constraint[]>, k: string, c: Constraint): void => {
    m.set(k, [...(m.get(k) ?? []), c]);
  };

  if ("COACH_AVAILABILITY" === family) {
    const groupOf = (id: string | null): "salaried" | "player" | "other" | null => {
      const coach = ctx.coaches.find((co) => co.id === id);
      if (!coach) {
        return null;
      }
      return coach.isEmployee ? "salaried" : ctx.coachPlayerIds.has(coach.id) ? "player" : "other";
    };
    const buckets: Record<string, Constraint[]> = { salaried: [], player: [], other: [], gone: [] };
    for (const c of constraints) {
      buckets[groupOf(c.scopeTargetId) ?? "gone"].push(c);
    }
    // Pluriels de catégorie INCHANGÉS (décision fondateur) ; « Coach retiré·e » désigne une
    // personne au genre inconnu (coach supprimé) ⇒ double forme via le foyer `coachWording`.
    const labels: [string, string][] = [["salaried", "Salariés"], ["player", "Coachs-joueurs"], ["other", "Bénévoles"], ["gone", `Coach ${retiredWord("UNSPECIFIED")}`]];
    return labels.filter(([k]) => buckets[k].length > 0).map(([k, label]) => ({ key: `staff:${k}`, label, items: buckets[k] }));
  }

  if ("FACILITY" === family) {
    const byVenue = new Map<string, Constraint[]>();
    const noVenue: Constraint[] = [];
    for (const c of constraints) {
      const vid = constraintVenueId(c);
      if (null !== vid) {
        push(byVenue, vid, c);
      } else {
        noVenue.push(c);
      }
    }
    const out = [...ctx.venues]
      .filter((v) => byVenue.has(v.id))
      .sort((a, b) => compareNamesFr(a.name, b.name))
      .map((v) => ({ key: `v:${v.id}`, label: ctx.venueName(v.id), items: byVenue.get(v.id) as Constraint[] }));
    // Venues referenced but absent from the list, then the venue-less remainder.
    [...byVenue.keys()].filter((id) => !ctx.venues.some((v) => v.id === id)).forEach((id) => out.push({ key: `v:${id}`, label: ctx.venueName(id), items: byVenue.get(id) as Constraint[] }));
    if (noVenue.length > 0) {
      out.push({ key: "v:none", label: "Autres", items: noVenue });
    }
    return out;
  }

  // TIME / DAY (and any other): group by AXIS of the target tag, then teams, then club.
  const tagAxis = new Map(ctx.tags.map((t) => [t.name, t.axis]));
  const tagRank = new Map(orderedTagNames(ctx.tags).map((name, i) => [name, i]));
  const byAxis: Record<string, Constraint[]> = { GENRE: [], NIVEAU: [], AGE: [] };
  const byTagFallback = new Map<string, Constraint[]>();
  const byTeam = new Map<string, Constraint[]>();
  const clubWide: Constraint[] = [];
  const other: Constraint[] = [];
  for (const c of constraints) {
    // Groupé sous le tag PRINCIPAL (le 1er de `targetTags`, ou le legacy `targetTag`) : un
    // raffinage « + / sauf » ne déplace pas la contrainte de section — son NOM le porte (P2-29).
    const tag = targetTagNames(c.config)[0] ?? null;
    const axis = null !== tag ? tagAxis.get(tag) ?? null : null;
    if (null !== axis && axis in byAxis) {
      byAxis[axis].push(c);
    } else if (null !== tag) {
      // Tag whose axis is unknown (not in the loaded tags) → its own group,
      // never misfiled into "toutes les équipes".
      push(byTagFallback, tag, c);
    } else if ("TEAM" === c.scope && null !== c.scopeTargetId) {
      push(byTeam, c.scopeTargetId, c);
    } else if ("CLUB" === c.scope) {
      clubWide.push(c);
    } else {
      other.push(c);
    }
  }
  const out: ConstraintSection[] = [];
  const primaryTag = (c: Constraint): string => targetTagNames(c.config)[0] ?? "";
  const byTag = (a: Constraint, b: Constraint): number => (tagRank.get(primaryTag(a)) ?? RANK_FALLBACK) - (tagRank.get(primaryTag(b)) ?? RANK_FALLBACK);
  KNOWN_AXES.forEach((axis) => {
    if (byAxis[axis].length > 0) {
      out.push({ key: `axis:${axis}`, label: AXIS_LABEL[axis], items: [...byAxis[axis]].sort(byTag) });
    }
  });
  [...byTagFallback.entries()].sort((a, b) => a[0].localeCompare(b[0])).forEach(([tag, items]) => out.push({ key: `g:${tag}`, label: `Groupe ${tagLabel(tag)}`, items }));
  if (clubWide.length > 0) {
    out.push({ key: "club", label: "Toutes les équipes", items: clubWide });
  }
  // A team-targeted constraint is filed under its team's RANG (tier) group
  // (user: cibler SM14 → sous « S · Fanion »), teams in rank order within.
  for (const tierGroup of groupTeamsByTier(ctx.teams, ctx.tiers)) {
    const items = tierGroup.teams.flatMap((t) => byTeam.get(t.id) ?? []);
    if (items.length > 0) {
      out.push({ key: `tier:${tierGroup.tier?.id ?? "orphan"}`, label: tierGroupLabel(tierGroup.tier), items });
    }
  }
  if (other.length > 0) {
    out.push({ key: "other", label: "Autres", items: other });
  }
  return out;
}
