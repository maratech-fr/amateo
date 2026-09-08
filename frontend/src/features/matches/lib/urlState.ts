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
 * PR-2a/2b — sérialisation des filtres de l'onglet Consulter, fonctions PURES (mêmes
 * conventions que le filtre PR-1 : absent = défaut). `type` = types de compétition
 * cochés, `conflits` = familles de conflits cochées, `type_semaine=0|1` = semaine
 * type (absent = 1 = affichée), `temps` = temporalité (`semaine` défaut · `mois` ·
 * `phase`, PR-2b), `mois=YYYY-MM` (temporalité Mois), `phase=<competitionId>`
 * (temporalité Phase). `null` (kinds/families) = tout coché : rien n'est écrit dans
 * l'URL, comme le défaut ; `mois`/`phase` ne sont écrits que sous LEUR temporalité.
 */
export type ConsultTemps = "semaine" | "mois" | "phase";

const TEMPS: ConsultTemps[] = ["semaine", "mois", "phase"];
const MONTH_RE = /^\d{4}-(0[1-9]|1[0-2])$/;

function isTemps(value: string | null): value is ConsultTemps {
  return null !== value && (TEMPS as string[]).includes(value);
}

export interface ConsultParams {
  kinds: Kind[] | null;
  families: ConflictType[] | null;
  typicalWeek: boolean;
  temps: ConsultTemps;
  /** Mois affiché `YYYY-MM` (temporalité Mois) ; null = auto. */
  month: string | null;
  /** Compétition appariée affichée (temporalité Phase) ; null = auto/première. */
  phaseId: string | null;
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
  const rawMonth = params.get("mois");
  const rawPhase = params.get("phase");
  return {
    kinds: decodeList(params.get("type"), KINDS),
    families: decodeList(params.get("conflits"), CONFLICT_FAMILIES),
    // absent ou "1" ⇒ affichée ; "0" ⇒ masquée.
    typicalWeek: "0" !== params.get("type_semaine"),
    // semaine · mois · phase ; toute autre valeur retombe sur semaine (défaut).
    temps: isTemps(params.get("temps")) ? (params.get("temps") as ConsultTemps) : "semaine",
    // `mois` doit être un YYYY-MM valide (une valeur bidon est ignorée) ; `phase` est
    // un id brut, validé côté page contre les compétitions connues.
    month: null !== rawMonth && MONTH_RE.test(rawMonth) ? rawMonth : null,
    phaseId: null !== rawPhase && "" !== rawPhase ? rawPhase : null,
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
  // `temps` absent quand semaine (défaut) ; `mois`/`phase` n'existent que sous LEUR
  // temporalité (une sélection posée sous une autre temporalité ne pollue pas l'URL).
  if ("semaine" === consult.temps) {
    next.delete("temps");
  } else {
    next.set("temps", consult.temps);
  }
  if ("mois" === consult.temps && null !== consult.month) {
    next.set("mois", consult.month);
  } else {
    next.delete("mois");
  }
  if ("phase" === consult.temps && null !== consult.phaseId) {
    next.set("phase", consult.phaseId);
  } else {
    next.delete("phase");
  }
  return next;
}

/**
 * P4-185 — ancrage de la section ouverte de `/matchs/configuration` (accordéon
 * « une section = un écran »). `?section=<clé>` ; absent/inconnu ⇒ `gabarit`
 * (défaut ouvert), `aucune` ⇒ `null` (tout replié). Écrire le défaut (`gabarit`)
 * SUPPRIME le param ; `null` écrit `aucune` — sans quoi replier le gabarit
 * (param absent) le rouvrirait au décodage. Mêmes conventions que `?vue=`/`?temps=`.
 */
export type ConfigSection = "gabarit" | "creneaux" | "echeances" | "durees" | "adversaires" | "reglages";

const CONFIG_SECTIONS: ConfigSection[] = ["gabarit", "creneaux", "echeances", "durees", "adversaires", "reglages"];

function isConfigSection(value: string | null): value is ConfigSection {
  return null !== value && (CONFIG_SECTIONS as string[]).includes(value);
}

export function decodeSectionParam(params: URLSearchParams): ConfigSection | null {
  const raw = params.get("section");
  if ("aucune" === raw) {
    return null;
  }
  return isConfigSection(raw) ? raw : "gabarit";
}

export function applySectionToParams(current: URLSearchParams, section: ConfigSection | null): URLSearchParams {
  const next = new URLSearchParams(current);
  if (null === section) {
    next.set("section", "aucune");
  } else if ("gabarit" === section) {
    next.delete("section");
  } else {
    next.set("section", section);
  }
  return next;
}
