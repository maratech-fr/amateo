import type { TeamTag, TeamTagAxis } from "../api";
import { LEVEL_LABEL } from "@/shared/lib/teamIdentity";

/**
 * GENRE + ÂGE display labels. The NIVEAU tags reuse LEVEL_LABEL (single source of
 * truth). The option VALUE stays the raw tag name (`tag:${name}`, the emitted
 * constraint targetTag is unchanged); only the shown text changes. Names absent
 * everywhere (U9…U21, a future custom tag) fall back to the raw name.
 */
const TAG_LABELS: Record<string, string> = {
  // GENRE
  MASCULINE: "Homme",
  FEMININE: "Femme",
  MIXTE: "Mixte",
  // NIVEAU (hors LEVEL_LABEL : COMPETITION n'est pas un TeamLevel)
  // Engagement en compétition = niveau NON loisir (`TeamTagService::determineTagNames`).
  COMPETITION: "Compétition (hors loisir)",
  // ÂGE — ADULTE (« + de 18 », `ageMin >= 19`) et SENIOR (« + de 22 ») se CHEVAUCHENT
  // (décision fondateur 2026-08-15) : « Senior »/« Vétéran » portent les deux, « U21 »
  // n'a qu'ADULTE. Le nom d'écran dit la borne pour lever l'ambiguïté.
  ADULTE: "Adulte (+ de 18)",
  SENIOR: "Senior (+ de 22)",
  // ⚠ « Jeune (U13-U21) » MENTAIT jusqu'à P4-63 : la règle serveur branche JEUNE sur
  // `ageMin <= 18` (`TeamTagService::determineTagNames`) et U21 a `ageMin` 19
  // (`CategoryCatalog`) — il est donc tagué SENIOR. Un gestionnaire qui posait une
  // contrainte « Jeune » croyait couvrir ses U21 : elle ne les atteignait pas.
  // Décision fondateur (2026-08-03) : corriger l'ÉTIQUETTE, pas la règle — déplacer
  // U21 vers JEUNE aurait élargi en silence la portée de contraintes DÉJÀ écrites.
  // Viser les U21 reste possible explicitement, par le tag `U21`.
  JEUNE: "Jeune (U13-U18)",
  // ⚠ « EMB (U9-U11) » MENTAIT jusqu'à P4-42 : la règle serveur taguait EMB toute
  // catégorie d'`ageMax <= 12`, donc U5 et U7 aussi. Le lot a rétréci le tag au lieu de
  // corriger l'étiquette — c'est elle qui disait le besoin. Ne pas l'élargir « pour
  // coller au code » sans vérifier `TeamTagService::determineTagNames` d'abord.
  EMB: "EMB (U9-U11)",
  // « Baby basket » est NOMMÉ, pas sous-entendu : le tag le couvre (catégorie sans âge,
  // semée dans tout club neuf), et un libellé qui s'arrête à « U5-U7 » referait exactement
  // ce qu'on reproche à l'ancien « EMB » — annoncer une portée plus étroite que la vraie,
  // sur une contrainte qui peut être HARD (revue #352).
  BABY: "Baby (U5-U7, Baby basket)",
};

const LEVEL_LABELS: Record<string, string | undefined> = LEVEL_LABEL;

export function tagLabel(name: string): string {
  return TAG_LABELS[name] ?? LEVEL_LABELS[name] ?? name;
}

export const AXIS_LABEL: Record<TeamTagAxis, string> = { GENRE: "Genre", NIVEAU: "Niveau", AGE: "Âge" };
export const KNOWN_AXES: TeamTagAxis[] = ["GENRE", "NIVEAU", "AGE"];

export interface TagAxisGroup {
  label: string;
  tags: TeamTag[];
}

/**
 * Ordered axis sections for the constraint target picker: Genre, Niveau, Âge,
 * then a fallback « Autres » for any tag outside the three axes — null OR
 * undefined (API Platform omits null fields, and a stale cache may lack it), so
 * no tag is ever silently dropped. Each section is sorted by display label;
 * empty sections are removed.
 */
export function groupTagsByAxis(tags: TeamTag[]): TagAxisGroup[] {
  const byLabel = (a: TeamTag, b: TeamTag): number => tagLabel(a.name).localeCompare(tagLabel(b.name), "fr");
  const sections: TagAxisGroup[] = KNOWN_AXES.map((axis) => ({
    label: AXIS_LABEL[axis],
    tags: tags.filter((t) => t.axis === axis).sort(byLabel),
  }));
  sections.push({
    label: "Autres",
    tags: tags.filter((t) => null == t.axis || !KNOWN_AXES.includes(t.axis)).sort(byLabel),
  });
  return sections.filter((group) => group.tags.length > 0);
}
