import type { MatchFilterMode } from "./matchFilter";

/**
 * PR-1 — sérialisation du filtre de la vue Semaine dans l'URL (deep-link). Deux
 * query params : `vue` (equipe|coach|gymnase) et `filtre` (ids séparés par des
 * virgules). Fonctions PURES : la lecture au montage seed le store, l'écriture
 * (`setSearchParams(replace)`) suit chaque changement. Params absents = pas de
 * filtre (mode par défaut « equipe », aucune sélection).
 */
const MODES: MatchFilterMode[] = ["equipe", "coach", "gymnase"];

function isMode(value: string | null): value is MatchFilterMode {
  return null !== value && (MODES as string[]).includes(value);
}

/**
 * Décode `vue` + `filtre`. `validIds`, si fourni, restreint aux ressources
 * connues (ids inconnus ignorés silencieusement). L'ordre des ids est préservé,
 * les doublons retirés.
 */
export function decodeFilterParams(params: URLSearchParams, validIds?: ReadonlySet<string>): { mode: MatchFilterMode; ids: string[] } {
  const rawVue = params.get("vue");
  const mode: MatchFilterMode = isMode(rawVue) ? rawVue : "equipe";
  const rawFiltre = params.get("filtre");
  const parsed = null === rawFiltre ? [] : rawFiltre.split(",").map((s) => s.trim()).filter((s) => "" !== s);
  const kept = undefined === validIds ? parsed : parsed.filter((id) => validIds.has(id));
  return { mode, ids: [...new Set(kept)] };
}

/**
 * Renvoie une NOUVELLE `URLSearchParams` (les autres params préservés) portant le
 * filtre. `vue` absent quand mode = « equipe » (défaut) ; `filtre` absent quand
 * aucune sélection — « params absents = pas de filtre ».
 */
export function applyFilterToParams(current: URLSearchParams, mode: MatchFilterMode, ids: string[]): URLSearchParams {
  const next = new URLSearchParams(current);
  if ("equipe" === mode) {
    next.delete("vue");
  } else {
    next.set("vue", mode);
  }
  if (ids.length > 0) {
    next.set("filtre", ids.join(","));
  } else {
    next.delete("filtre");
  }
  return next;
}
