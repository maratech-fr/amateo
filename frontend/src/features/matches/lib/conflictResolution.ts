import { Check, Hourglass, type LucideIcon, Send } from "lucide-react";

import type { Conflict, ConflictResolutionStatus } from "../api";

/**
 * P4-207 — la MAISON UNIQUE du traitement d'un conflit : la table de PRÉSENTATION des
 * trois statuts (libellé + variante de pastille + glyphe), et les deux dérivations
 * « ce conflit est-il à traiter ? » / « combien à traiter ? ». Une seule maison,
 * consommée par le layout (badge nav), le rail (« Conflits (n) »), le radar (badge),
 * la page (compteurs, tri) et `countByFamily` : partout, un COMPTEUR ne compte que
 * l'À TRAITER, jamais les conflits déjà annotés.
 *
 * PRÉSENTATION seule (🔴 `.claude/rules/frontend.md`) : la table est un `Record` sur
 * l'enum, JAMAIS un `switch` décideur — elle choisit un libellé/glyphe, elle ne
 * décide d'aucun comportement métier (le backend reste souverain sur ce qui est
 * permis, 403 côté serveur).
 */

export const RESOLUTION_STATUSES: ConflictResolutionStatus[] = ["DEROGATION_REQUESTED", "RESOLVED_INTERNALLY", "NO_SOLUTION_YET"];

/** Un glyphe DISTINCT par statut (jamais la couleur seule) + un libellé FR + la variante `StatusPill`. */
export const RESOLUTION_LABEL: Record<ConflictResolutionStatus, { label: string; variant: "warning" | "accent" | "neutral"; icon: LucideIcon }> = {
  DEROGATION_REQUESTED: { label: "Dérogation demandée", variant: "warning", icon: Send },
  RESOLVED_INTERNALLY: { label: "Réglé en interne", variant: "accent", icon: Check },
  NO_SOLUTION_YET: { label: "Sans solution pour l'instant", variant: "neutral", icon: Hourglass },
};

/**
 * Un conflit est « à traiter » quand il n'a AUCUNE résolution. Le backend sert
 * toujours le champ (`null` ou objet) ; on tolère aussi l'absence (`undefined`) pour
 * ne jamais compter à tort un conflit sans donnée servie comme « traité ».
 */
export const isOpenConflict = (conflict: Conflict): boolean => null == conflict.resolution;

/** Le nombre de conflits À TRAITER dans un lot (les données absentes ⇒ 0, jamais « · 0 » fabriqué). */
export const openConflictCount = (conflicts: Conflict[] | undefined): number => (conflicts ?? []).filter(isOpenConflict).length;

/**
 * L'axe de FILTRE « Traitement » de l'onglet Conflits : « à traiter » (l'ABSENCE de
 * résolution) + les trois statuts de `ConflictResolutionStatus`. « à traiter » n'est
 * PAS un statut serveur, d'où la clé distincte `"a_traiter"`.
 */
export type TreatmentKey = "a_traiter" | ConflictResolutionStatus;

/** Les 4 clés, dans l'ordre des puces (« à traiter » en tête). */
export const TREATMENT_KEYS: TreatmentKey[] = ["a_traiter", "DEROGATION_REQUESTED", "RESOLVED_INTERNALLY", "NO_SOLUTION_YET"];

/**
 * Slug URL par clé — TABLE `Record` exhaustive (TypeScript exige les 4), jamais un
 * `switch`. PRÉSENTATION du deep-link : `?traitement=derogation,regle_interne`.
 */
export const TREATMENT_SLUG: Record<TreatmentKey, string> = {
  a_traiter: "a_traiter",
  DEROGATION_REQUESTED: "derogation",
  RESOLVED_INTERNALLY: "regle_interne",
  NO_SOLUTION_YET: "sans_solution",
};

const SLUG_TO_TREATMENT: Record<string, TreatmentKey> = Object.fromEntries(
  (Object.keys(TREATMENT_SLUG) as TreatmentKey[]).map((key) => [TREATMENT_SLUG[key], key]),
) as Record<string, TreatmentKey>;

/** La clé d'un slug URL connu, sinon `null` (slug inconnu ignoré au décodage). */
export const treatmentFromSlug = (slug: string): TreatmentKey | null => SLUG_TO_TREATMENT[slug] ?? null;

/** Le traitement d'un conflit : son statut de résolution, sinon « à traiter ». */
export const treatmentOf = (conflict: Conflict): TreatmentKey => conflict.resolution?.status ?? "a_traiter";

/** Compte par clé de traitement (les 4) sur un lot — compteurs FIXES saison (doctrine
 *  de la page : un filtre change l'affichage, jamais les compteurs). */
export function countByTreatment(conflicts: Conflict[]): Map<TreatmentKey, number> {
  const counts = new Map<TreatmentKey, number>();
  for (const conflict of conflicts) {
    const key = treatmentOf(conflict);
    counts.set(key, (counts.get(key) ?? 0) + 1);
  }
  return counts;
}
