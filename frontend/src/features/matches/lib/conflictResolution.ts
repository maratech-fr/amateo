import { Check, ClipboardList, Download, Dumbbell, FileWarning, Hourglass, type LucideIcon, Move, Send } from "lucide-react";

import type { Conflict, ConflictResolutionStatus, ConflictType } from "../api";

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
  // Réservés aux conflits où la personne JOUE (proposés seulement dans ce cas).
  COACHES_NOT_PLAYING: { label: "Coache, ne joue pas", variant: "accent", icon: ClipboardList },
  PLAYS_NOT_COACHING: { label: "Joue, ne coache pas", variant: "accent", icon: Dumbbell },
  // Statuts propres à une famille (lot N) : « on a une action, pas forcément directe ».
  IMPORT_MISSING_MATCHES: { label: "Importer les matchs manquants", variant: "neutral", icon: Download },
  FBI_ERROR: { label: "Erreur FBI", variant: "warning", icon: FileWarning },
  MATCH_TO_MOVE: { label: "Match à déplacer", variant: "neutral", icon: Move },
};

/**
 * Les statuts EN PLUS de la base, PAR FAMILLE — TABLE (jamais un `switch` décideur :
 * `.claude/rules/frontend.md`), miroir de présentation de `ConflictResolutionStatus::FAMILY_EXTRA`
 * côté serveur. Les 2 statuts « joue/coache » des familles de personne restent CONDITIONNÉS à un
 * côté PLAYER (voir `resolutionChoicesFor`), donc absents de cette table statique.
 */
const FAMILY_EXTRA_STATUSES: Partial<Record<ConflictType, ConflictResolutionStatus[]>> = {
  COMPETITION_INCOMPLETE: ["IMPORT_MISSING_MATCHES"],
  VENUE_OVERLAP: ["FBI_ERROR", "MATCH_TO_MOVE"],
};

/** Un côté servi porte-t-il le rôle PLAYER ? (lecture des rôles servis, jamais de redérivation).
 *  Le côté VENUE_UNAVAILABLE ne porte aucun rôle — le garde `"role" in side` l'écarte. */
const conflictHasPlayerSide = (conflict: Conflict): boolean =>
  [conflict.left, conflict.right, conflict.fixture, conflict.training].some(
    (side) => null != side && "role" in side && "PLAYER" === side.role,
  );

/**
 * Les statuts PROPOSÉS pour ce conflit : les 3 de base, PLUS les statuts propres à sa famille
 * (par ex. « erreur FBI » / « match à déplacer » sur une collision de gymnase), PLUS les 2
 * « joue/coache » quand la personne joue un côté servi. Le backend refuse tout statut hors de la
 * table de sa famille (422) — on masque le geste voué au refus (🔴 `.claude/rules/frontend.md`),
 * on ne re-décide rien.
 */
export const resolutionChoicesFor = (conflict: Conflict): ConflictResolutionStatus[] => {
  const familyExtra = FAMILY_EXTRA_STATUSES[conflict.type] ?? [];
  const play: ConflictResolutionStatus[] = conflictHasPlayerSide(conflict) ? ["COACHES_NOT_PLAYING", "PLAYS_NOT_COACHING"] : [];
  return [...RESOLUTION_STATUSES, ...familyExtra, ...play];
};

/** « Erreur FBI » exige un complément (rencontre + champ) : c'est le seul statut qui ouvre un dialogue. */
export const statusNeedsFbiComplement = (status: ConflictResolutionStatus): boolean => "FBI_ERROR" === status;

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
export type TreatmentKey =
  | "a_traiter"
  | "DEROGATION_REQUESTED"
  | "RESOLVED_INTERNALLY"
  | "NO_SOLUTION_YET"
  | "IMPORT_MISSING_MATCHES"
  | "FBI_ERROR"
  | "MATCH_TO_MOVE";

/** Les 4 clés HISTORIQUES, TOUJOURS rendues comme puces (même à zéro). « À traiter » en tête. */
export const HISTORIC_TREATMENT_KEYS: TreatmentKey[] = ["a_traiter", "DEROGATION_REQUESTED", "RESOLVED_INTERNALLY", "NO_SOLUTION_YET"];

/** Les 3 clés propres à une famille (lot N) : une puce ne se rend que si elle est PRÉSENTE
 *  (patron des puces de famille) — sinon on passerait de 4 à 7 puces permanentes. */
export const CONDITIONAL_TREATMENT_KEYS: TreatmentKey[] = ["IMPORT_MISSING_MATCHES", "FBI_ERROR", "MATCH_TO_MOVE"];

/** L'ensemble des clés de filtre (historiques + conditionnelles), dans l'ordre des puces.
 *  Les 2 statuts « joue/coache » n'ont pas de chip propre — ils se rangent sous « Réglé en
 *  interne » (voir `treatmentOf`). */
export const TREATMENT_KEYS: TreatmentKey[] = [...HISTORIC_TREATMENT_KEYS, ...CONDITIONAL_TREATMENT_KEYS];

/**
 * Slug URL par clé — TABLE `Record` exhaustive (TypeScript exige les 7), jamais un
 * `switch`. PRÉSENTATION du deep-link : `?traitement=derogation,erreur_fbi`.
 */
export const TREATMENT_SLUG: Record<TreatmentKey, string> = {
  a_traiter: "a_traiter",
  DEROGATION_REQUESTED: "derogation",
  RESOLVED_INTERNALLY: "regle_interne",
  NO_SOLUTION_YET: "sans_solution",
  IMPORT_MISSING_MATCHES: "import_matchs",
  FBI_ERROR: "erreur_fbi",
  MATCH_TO_MOVE: "match_a_deplacer",
};

const SLUG_TO_TREATMENT: Record<string, TreatmentKey> = Object.fromEntries(
  (Object.keys(TREATMENT_SLUG) as TreatmentKey[]).map((key) => [TREATMENT_SLUG[key], key]),
) as Record<string, TreatmentKey>;

/** La clé d'un slug URL connu, sinon `null` (slug inconnu ignoré au décodage). */
export const treatmentFromSlug = (slug: string): TreatmentKey | null => SLUG_TO_TREATMENT[slug] ?? null;

/** Le traitement d'un conflit : sa clé de FILTRE. Les statuts « joue/coache » n'ont pas de chip
 *  propre et se rangent sous « Réglé en interne » ; sinon le statut, sinon « à traiter ». */
export const treatmentOf = (conflict: Conflict): TreatmentKey => {
  const status = conflict.resolution?.status;
  if (undefined === status) {
    return "a_traiter";
  }
  if ("COACHES_NOT_PLAYING" === status || "PLAYS_NOT_COACHING" === status) {
    return "RESOLVED_INTERNALLY";
  }
  return status;
};

/** Compte par clé de traitement sur un lot — compteurs FIXES saison (doctrine
 *  de la page : un filtre change l'affichage, jamais les compteurs). */
export function countByTreatment(conflicts: Conflict[]): Map<TreatmentKey, number> {
  const counts = new Map<TreatmentKey, number>();
  for (const conflict of conflicts) {
    const key = treatmentOf(conflict);
    counts.set(key, (counts.get(key) ?? 0) + 1);
  }
  return counts;
}

/**
 * Les clés de traitement à AFFICHER en puces : les 4 historiques (toujours rendues, même
 * à zéro) + les conditionnelles PRÉSENTES (au moins un conflit) — patron des puces de
 * famille (`familiesPresent`), pour ne pas passer de 4 à 7 puces permanentes.
 */
export function treatmentChipKeys(conflicts: Conflict[]): TreatmentKey[] {
  const present = new Set(conflicts.map(treatmentOf));
  return TREATMENT_KEYS.filter((key) => HISTORIC_TREATMENT_KEYS.includes(key) || present.has(key));
}
