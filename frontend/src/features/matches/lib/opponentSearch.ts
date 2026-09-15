import { stripDiacritics } from "@/shared/lib/utils";

/**
 * PR 2a « Configuration & navigation » — la recherche instantanée d'un adversaire dans la
 * section « Adversaires à localiser ». Helpers PURS : normalisation insensible aux accents +
 * à la casse, découpe en tokens, tokens en ET (chaque token doit apparaître). Un club adverse
 * est conservé ENTIER (en-tête, défaut, toutes ses équipes) dès que SON libellé OU une de ses
 * équipes matche — jamais un filtrage équipe par équipe qui casserait le groupe.
 */

/** Insensible aux accents et à la casse (patron `stripDiacritics`, comme le picker Localiser). */
export function normalizeSearch(text: string): string {
  return stripDiacritics(text).toLowerCase();
}

/** La requête découpée en tokens non vides (une recherche vide ⇒ aucun token ⇒ tout passe). */
export function queryTokens(query: string): string[] {
  return normalizeSearch(query)
    .split(/\s+/)
    .filter((token) => "" !== token);
}

/** Le texte matche la requête quand CHAQUE token en est une sous-chaîne (tokens en ET). */
export function textMatchesQuery(text: string, tokens: string[]): boolean {
  if (0 === tokens.length) {
    return true;
  }
  const normalized = normalizeSearch(text);
  return tokens.every((token) => normalized.includes(token));
}

/**
 * Un club est conservé dès que son libellé OU une de ses équipes matche la requête. `tokens`
 * vide (recherche vide) ⇒ toujours conservé.
 */
export function clubMatchesQuery(clubLabel: string, teamLabels: string[], tokens: string[]): boolean {
  return textMatchesQuery(clubLabel, tokens) || teamLabels.some((label) => textMatchesQuery(label, tokens));
}
