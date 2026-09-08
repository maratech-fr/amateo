import type { ConflictType } from "../api";
import { CONFLICT_FAMILIES } from "./conflictLabels";
import { KINDS, type Kind } from "./consultFilter";
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

/**
 * PR-2a — sérialisation des filtres de l'onglet Consulter, fonctions PURES (mêmes
 * conventions que le filtre PR-1 : absent = défaut). `type` = types de compétition
 * cochés, `conflits` = familles de conflits cochées, `type_semaine=0|1` = semaine
 * type (absent = 1 = affichée), `temps` = temporalité (PR-2a n'accepte que
 * `semaine` ; 2b ajoutera mois/phase). `null` (kinds/families) = tout coché : rien
 * n'est écrit dans l'URL, comme le défaut.
 */
export type ConsultTemps = "semaine";

export interface ConsultParams {
  kinds: Kind[] | null;
  families: ConflictType[] | null;
  typicalWeek: boolean;
  temps: ConsultTemps;
}

function decodeList<T extends string>(raw: string | null, valid: readonly T[]): T[] | null {
  if (null === raw) {
    return null;
  }
  const allowed = new Set<string>(valid);
  const parsed = raw
    .split(",")
    .map((s) => s.trim())
    .filter((s): s is T => "" !== s && allowed.has(s));
  return [...new Set(parsed)];
}

export function decodeConsultParams(params: URLSearchParams): ConsultParams {
  return {
    kinds: decodeList(params.get("type"), KINDS),
    families: decodeList(params.get("conflits"), CONFLICT_FAMILIES),
    // absent ou "1" ⇒ affichée ; "0" ⇒ masquée.
    typicalWeek: "0" !== params.get("type_semaine"),
    // PR-2a ne connaît que « semaine » ; toute autre valeur (2b) retombe dessus.
    temps: "semaine",
  };
}

/**
 * Renvoie une NOUVELLE `URLSearchParams` (autres params préservés) portant les
 * filtres Consulter. `null` OU la sélection PLEINE (les 4 types / les 9 familles) =
 * défaut ⇒ param absent ; semaine type affichée (défaut) ⇒ `type_semaine` absent ;
 * `temps` = `semaine` (défaut) ⇒ absent.
 */
export function applyConsultToParams(current: URLSearchParams, consult: ConsultParams): URLSearchParams {
  const next = new URLSearchParams(current);
  const writeList = (key: string, value: string[] | null, full: number): void => {
    if (null === value || value.length === full) {
      next.delete(key);
    } else {
      next.set(key, value.join(","));
    }
  };
  writeList("type", consult.kinds, KINDS.length);
  writeList("conflits", consult.families, CONFLICT_FAMILIES.length);
  if (consult.typicalWeek) {
    next.delete("type_semaine");
  } else {
    next.set("type_semaine", "0");
  }
  next.delete("temps");
  return next;
}
