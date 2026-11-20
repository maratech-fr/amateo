import type { FfbbSalle } from "../api";

/** Casse et accents neutralisés — « mateo » doit trouver « GYMNASE MATEO », « émile » « EMILE ». */
const fold = (s: string): string =>
  s
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase();

/**
 * Les suggestions à OFFRIR pour ce qui est tapé (P2-20) : contains plié sur le
 * nom OU l'adresse (on cherche autant « mateo » que « dunière »), plafonnées à
 * 8 — au-delà la liste ne se lit plus, on affine en tapant. Champ vide = tout
 * offrir (plafonné) : c'est l'invitation à choisir plutôt qu'à taper.
 * Règle pure : testée sans monter l'écran.
 */
export function filterSalles(salles: FfbbSalle[], typed: string): FfbbSalle[] {
  const needle = fold(typed.trim());
  const matches = "" === needle ? salles : salles.filter((s) => fold(s.name).includes(needle) || fold(s.address ?? "").includes(needle));

  return matches.slice(0, 8);
}
