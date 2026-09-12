import { stripDiacritics } from "@/shared/lib/utils";

/**
 * Familles de divisions FBI — regroupement d'AFFICHAGE pour l'écran de dépôt.
 *
 * Mesure terrain du fondateur : un export réel porte 50 divisions (291 lignes),
 * illisibles dans une seule liste défilante. On les range par famille en onglets.
 * La classification est PUREMENT présentationnelle (elle ne décide d'aucun
 * comportement métier — cf. `.claude/rules/frontend.md`, redérivation interdite) :
 * elle choisit dans quel onglet une division s'affiche, rien d'autre. Une division
 * ne DISPARAÎT jamais — `autres` est le repli garanti.
 */
export type DivisionFamily = "departemental" | "regional" | "brassage" | "coupeAra" | "coupesCrm" | "amicaux" | "autres";

/** Ordre d'affichage des onglets (familles vides masquées à l'usage). */
export const FAMILY_ORDER: readonly DivisionFamily[] = ["departemental", "regional", "brassage", "coupeAra", "coupesCrm", "amicaux", "autres"];

export const FAMILY_LABEL: Record<DivisionFamily, string> = {
  departemental: "Départemental",
  regional: "Régional",
  brassage: "Brassage",
  coupeAra: "Coupe ARA",
  coupesCrm: "Coupes CRM",
  amicaux: "Amicaux",
  autres: "Autres",
};

/**
 * Range un libellé de division FBI dans une famille d'affichage.
 *
 * Normalisation : minuscules → `stripDiacritics` (maison unique) → tout caractère
 * non alphanumérique devient un espace → espaces réduits → trim ; puis découpage en
 * tokens. Les règles s'appliquent DANS CET ORDRE (le premier qui matche gagne, d'où
 * « brassage » avant « régional » : `RMU13 Brassage` est un brassage, pas du régional).
 */
export function classifyDivision(name: string): DivisionFamily {
  const normalized = stripDiacritics(name.toLowerCase())
    .replace(/[^a-z0-9]/g, " ")
    .replace(/\s+/g, " ")
    .trim();
  const tokens = normalized.split(" ").filter((token) => "" !== token);

  if (tokens.includes("brassage")) {
    return "brassage";
  }
  if (tokens.includes("ara") && tokens.includes("coupe")) {
    return "coupeAra";
  }
  if (normalized.startsWith("crml") || normalized.startsWith("cmrl")) {
    return "coupesCrm";
  }
  if (normalized.startsWith("amical")) {
    return "amicaux";
  }
  if (normalized.startsWith("d") || normalized.startsWith("pr")) {
    return "departemental";
  }
  if (normalized.startsWith("r") || normalized.startsWith("pn")) {
    return "regional";
  }
  return "autres";
}
