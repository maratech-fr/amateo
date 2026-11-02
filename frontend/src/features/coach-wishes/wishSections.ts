import type { PublicWishContext, PublicWishSubmission } from "./publicApi";

/** Y-m-d → j/m/aaaa. */
export const frDate = (iso: string): string => {
  const [y, m, d] = iso.split("-");
  return `${d}/${m}/${y}`;
};

/** État éditable d'une section (équipe × semaine). */
export interface SectionState {
  slotsWanted: number;
  days: Set<number>;
  /** Jours souhaités (P4-312, informatif) — toujours disjoint de `days`. */
  wishedDays: Set<number>;
  comment: string;
  /** Le coach souhaite garder ses créneaux habituels de saison cette semaine-là (volet B). */
  keepSeasonSlots: boolean;
  /**
   * Lecture seule — cette (équipe × semaine) a déjà été saisie par un AUTRE coach : on prévient
   * que l'envoi remplacera sa version (option A). Jamais pris en compte par le dirty-tracking.
   */
  byAnotherCoach: boolean;
  /** Jours d'absence par personne du staff COURANT ; une personne absente de la carte est disponible. */
  staff: Map<string, Set<number>>;
}

export const sectionKey = (teamId: string, weekStart: string): string => `${teamId}|${weekStart}`;

/** Snapshot initial (état courant pré-rempli côté serveur) — référence du dirty-tracking. */
export function buildInitialSections(context: PublicWishContext): Map<string, SectionState> {
  const map = new Map<string, SectionState>();
  for (const team of context.teams) {
    const current = new Set(team.staff.map((m) => m.coachId));
    for (const week of context.weeks) {
      const existing = context.wishes.find((w) => w.teamId === team.id && w.weekStart === week);
      map.set(sectionKey(team.id, week), {
        slotsWanted: existing?.slotsWanted ?? 0,
        days: new Set(existing?.unavailableDays ?? []),
        wishedDays: new Set(existing?.wishedDays ?? []),
        comment: existing?.comment ?? "",
        keepSeasonSlots: existing?.keepSeasonSlots ?? false,
        byAnotherCoach: existing?.byAnotherCoach ?? false,
        staff: new Map((existing?.staffUnavailability ?? []).filter((line) => current.has(line.coachId)).map((line) => [line.coachId, new Set(line.days)])),
      });
    }
  }
  return map;
}

/** Copie profonde d'une carte de sections (les `Set` sont clonés). */
export function cloneSections(source: Map<string, SectionState>): Map<string, SectionState> {
  return new Map([...source].map(([k, v]) => [k, { slotsWanted: v.slotsWanted, days: new Set(v.days), wishedDays: new Set(v.wishedDays), comment: v.comment, keepSeasonSlots: v.keepSeasonSlots, byAnotherCoach: v.byAnotherCoach, staff: new Map([...v.staff].map(([coachId, days]) => [coachId, new Set(days)])) }]));
}

/** Une section est MODIFIÉE si elle diffère de l'état initial — seules celles-là partent. */
export function isSectionDirty(a: SectionState | undefined, b: SectionState | undefined): boolean {
  if (undefined === a || undefined === b) {
    return false;
  }
  if (a.slotsWanted !== b.slotsWanted || a.comment.trim() !== b.comment.trim() || a.keepSeasonSlots !== b.keepSeasonSlots || a.days.size !== b.days.size || a.wishedDays.size !== b.wishedDays.size) {
    return true;
  }
  for (const d of a.days) {
    if (!b.days.has(d)) {
      return true;
    }
  }
  for (const d of a.wishedDays) {
    if (!b.wishedDays.has(d)) {
      return true;
    }
  }
  // Une personne sans jour vaut une personne absente de la carte : pas de faux « modifié ».
  for (const coachId of new Set([...a.staff.keys(), ...b.staff.keys()])) {
    const before = a.staff.get(coachId) ?? new Set<number>();
    const after = b.staff.get(coachId) ?? new Set<number>();
    if (before.size !== after.size || [...before].some((d) => !after.has(d))) {
      return true;
    }
  }
  return false;
}

/** Sérialise une section modifiée en payload d'envoi (jours triés, commentaire vide → null). */
export function toSubmission(key: string, s: SectionState): PublicWishSubmission {
  const [teamId, weekStart] = key.split("|");
  return {
    teamId,
    weekStart,
    slotsWanted: s.slotsWanted,
    unavailableDays: [...s.days].sort((x, y) => x - y),
    wishedDays: [...s.wishedDays].sort((x, y) => x - y),
    comment: s.comment.trim() || null,
    keepSeasonSlots: s.keepSeasonSlots,
    staffUnavailability: [...s.staff]
      .filter(([, days]) => days.size > 0)
      .sort(([x], [y]) => x.localeCompare(y))
      .map(([coachId, days]) => ({ coachId, days: [...days].sort((x, y) => x - y) })),
  };
}
