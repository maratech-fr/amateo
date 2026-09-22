import type { ConflictType } from "../api";
import { CONFLICT_FAMILIES } from "./conflictLabels";
import type { ConflictPivotAxis } from "./conflictPivot";
import { DEFAULT_KINDS, KINDS, type Kind } from "./consultFilter";
import { TREATMENT_KEYS, TREATMENT_SLUG, treatmentFromSlug, type TreatmentKey } from "./conflictResolution";
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
 * PR 3b — la SEMAINE affichée du Calendrier dans l'URL (`semaine=YYYY-MM-DD`, clé
 * samedi de `weekendKeyOf`). Écrite en `replace` quand `selectedWeekend` change, lue
 * au seed. Absente ou mal formée = auto (le store repart de `null`, la page résout la
 * première semaine ≥ aujourd'hui). On valide seulement la FORME (date ISO) ; une clé
 * hors de la liste des semaines retombe sur l'auto via `resolveActiveWeekend`.
 */
const WEEKEND_RE = /^\d{4}-\d{2}-\d{2}$/;

export function decodeWeekendParam(params: URLSearchParams): string | null {
  const raw = params.get("semaine");
  return null !== raw && WEEKEND_RE.test(raw) ? raw : null;
}

export function applyWeekendToParams(current: URLSearchParams, weekend: string | null): URLSearchParams {
  const next = new URLSearchParams(current);
  if (null === weekend) {
    next.delete("semaine");
  } else {
    next.set("semaine", weekend);
  }
  return next;
}

/**
 * Deep-link de la liste « FBI — à faire » : `fbi=1` ouvre la modale (depuis le cockpit
 * « Ouvrir la liste FBI »). Fonctions PURES, patron « param absent = défaut » : absent
 * = fermée. L'état vit dans l'URL (piège mémoire : ne pas le poser dans le store puis
 * naviguer — l'URL fait foi au montage).
 */
export function decodeFbiParam(params: URLSearchParams): boolean {
  return "1" === params.get("fbi");
}

export function applyFbiToParams(current: URLSearchParams, open: boolean): URLSearchParams {
  const next = new URLSearchParams(current);
  if (open) {
    next.set("fbi", "1");
  } else {
    next.delete("fbi");
  }
  return next;
}

/**
 * Deep-link d'une rencontre à mettre en évidence sur le Calendrier : `match=<fixtureId>`
 * (« Voir la semaine » depuis Conflits, ou tout lien qui pointe un match précis). Fonctions
 * PURES, patron « param absent = défaut » : absent ⇒ aucune rencontre visée. Le param est
 * CONSOMMÉ une fois au seed puis retiré en `replace` (il ne survit pas à la sélection : la
 * mise en évidence est portée par le store, pas par l'URL — piège mémoire).
 */
export function decodeMatchParam(params: URLSearchParams): string | null {
  const raw = params.get("match");
  return null !== raw && "" !== raw ? raw : null;
}

export function applyMatchToParams(current: URLSearchParams, fixtureId: string | null): URLSearchParams {
  const next = new URLSearchParams(current);
  if (null === fixtureId) {
    next.delete("match");
  } else {
    next.set("match", fixtureId);
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
  /** `null` = les DÉFAUTS (championnat+coupe+brassage) ; une liste = sélection explicite
   *  (y compris les 4 ou `[]`). Absent dans l'URL ⇔ null. */
  kinds: Kind[] | null;
  families: ConflictType[] | null;
  /** Semaine type — MASQUÉE par défaut (`type_semaine=1` quand affichée). */
  typicalWeek: boolean;
  /** Interrupteur « Extérieurs » — masqués par défaut (`exterieurs=1` quand affichés). */
  away: boolean;
  temps: ConsultTemps;
  /** Mois affiché `YYYY-MM` (temporalité Mois) ; null = auto. */
  month: string | null;
  /** Compétition appariée affichée (temporalité Phase) ; null = auto/première. */
  phaseId: string | null;
}

/** `type=` absent ⇔ les DÉFAUTS : `null` OU une liste égale aux DÉFAUTS (3 types). */
function isDefaultKinds(value: Kind[] | null): boolean {
  if (null === value) {
    return true;
  }
  return value.length === DEFAULT_KINDS.length && DEFAULT_KINDS.every((k) => value.includes(k));
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

/**
 * Les clés de query que porte l'état Consulter — liste UNIQUE (source de vérité du
 * couple decode/apply : `applyConsultToParams` écrit/supprime exactement ces clés,
 * `decodeConsultParams` les relit). Sert à `hasConsultParams`.
 */
export const CONSULT_PARAM_KEYS = ["type", "conflits", "type_semaine", "exterieurs", "temps", "mois", "phase"] as const;

/**
 * PURE — l'URL porte-t-elle AU MOINS une clé de l'état Consulter ? Sert au seed du
 * Calendrier (mémoire de session) : une URL avec au moins une clé FAIT FOI (seed complet,
 * une clé absente = son défaut) ; une URL sans aucune clé laisse le store (mémoire non
 * persistée) intact. Les clés PR-1 (`vue`/`filtre`) et la semaine (`semaine`) n'en font pas
 * partie : elles ont leur propre logique de seed.
 */
export function hasConsultParams(params: URLSearchParams): boolean {
  return CONSULT_PARAM_KEYS.some((key) => params.has(key));
}

export function decodeConsultParams(params: URLSearchParams): ConsultParams {
  const rawMonth = params.get("mois");
  const rawPhase = params.get("phase");
  return {
    // absent ⇒ null (= les DÉFAUTS) ; liste (dont `[]` pour `type=` vide) = sélection explicite.
    kinds: decodeList(params.get("type"), KINDS),
    families: decodeList(params.get("conflits"), CONFLICT_FAMILIES),
    // INVERSÉ (défaut masquée) : "1" ⇒ affichée ; absent/"0"/autre ⇒ masquée.
    typicalWeek: "1" === params.get("type_semaine"),
    // Extérieurs masqués par défaut : "1" ⇒ affichés.
    away: "1" === params.get("exterieurs"),
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
 * filtres Consulter. `type` : absent ⇔ défaut (les 3), sinon liste explicite ;
 * `conflits` : `null`/plein ⇒ absent ; `type_semaine=1`/`exterieurs=1` seulement quand
 * ALLUMÉS ; `temps` = `semaine` (défaut) ⇒ absent.
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
  // `type` : absent ⇔ défaut (les 3) ; toute autre sélection = liste EXPLICITE, y compris
  // les 4 (`type=amical,championnat,coupe,brassage`) ou `[]` (`type=` vide, zéro coché).
  if (isDefaultKinds(consult.kinds)) {
    next.delete("type");
  } else {
    next.set("type", (consult.kinds ?? []).join(","));
  }
  writeList("conflits", consult.families, CONFLICT_FAMILIES.length);
  // INVERSÉ : `type_semaine=1` quand ALLUMÉE, supprimé sinon (un ancien `type_semaine=0`
  // décode « éteinte » et se réécrit en absence par ce passage).
  if (consult.typicalWeek) {
    next.set("type_semaine", "1");
  } else {
    next.delete("type_semaine");
  }
  // Extérieurs : `exterieurs=1` quand allumé, absent sinon.
  if (consult.away) {
    next.set("exterieurs", "1");
  } else {
    next.delete("exterieurs");
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
 * PR A — sérialisation des filtres de l'onglet Conflits, fonctions PURES (mêmes
 * conventions : absent = défaut). `pivot` = l'axe de regroupement (coach défaut),
 * `conflits` = les familles cochées (réutilise `CONFLICT_FAMILIES`). `null` (familles)
 * ou la sélection PLEINE = tout coché ⇒ rien dans l'URL. La section ouverte (`?ouvert`)
 * est gérée directement par la page, ces fonctions la PRÉSERVENT (autres params copiés).
 */
const PIVOTS: ConflictPivotAxis[] = ["coach", "equipe", "gymnase", "journee"];

function isPivot(value: string | null): value is ConflictPivotAxis {
  return null !== value && (PIVOTS as string[]).includes(value);
}

export interface ConflictsParams {
  pivot: ConflictPivotAxis;
  families: ConflictType[] | null;
  /**
   * Filtre « Traitement » — `null` = tout (les 4 aussi ⇒ absent) ; une liste = sélection
   * explicite (`traitement=a_traiter,derogation`). Rétro-compat P4-207 : `traites=masques`
   * décode `["a_traiter"]` (réécrit `traitement=a_traiter`, `traites` supprimé) ; si les
   * deux coexistent, `traitement` gagne.
   */
  treatments: TreatmentKey[] | null;
  /** « Seulement avec un match à domicile » : `domicile=1` (absent = tous). */
  homeOnly: boolean;
}

/** Décode une liste de slugs `traitement=` en clés connues (slug inconnu ignoré), dédoublonnée. */
function decodeTreatments(raw: string | null): TreatmentKey[] | null {
  if (null === raw) {
    return null;
  }
  const parsed = raw
    .split(",")
    .map((s) => s.trim())
    .filter((s) => "" !== s)
    .map(treatmentFromSlug)
    .filter((k): k is TreatmentKey => null !== k);
  return [...new Set(parsed)];
}

/**
 * Les clés de query que porte l'état Conflits — source de vérité de `hasConflictsParams`.
 * Les quatre filtres (`pivot`/`conflits`/`traitement`/`domicile`), l'alias legacy `traites`
 * (rétro-compat P4-207, décodé puis réécrit), ET `ouvert` : le param qui ouvre une entrée
 * précise COMPTE comme une clé (décision fondateur), sans quoi un lien partagé vers une entrée
 * que la session masque la garderait invisible et perdrait le param en silence — le lien mentirait.
 */
export const CONFLICTS_PARAM_KEYS = ["pivot", "conflits", "traitement", "traites", "domicile", "ouvert"] as const;

/**
 * PURE — l'URL porte-t-elle AU MOINS une clé de l'état Conflits ? Miroir de `hasConsultParams`
 * (mémoire de session) : une URL avec au moins une clé FAIT FOI (seed complet, clé absente = son
 * défaut — un lien partagé dit vrai, quitte à écraser la session) ; une URL nue laisse le store
 * (mémoire non persistée) intact, la re-synchro repoussant ensuite les filtres dans l'adresse.
 */
export function hasConflictsParams(params: URLSearchParams): boolean {
  return CONFLICTS_PARAM_KEYS.some((key) => params.has(key));
}

export function decodeConflictsParams(params: URLSearchParams): ConflictsParams {
  const rawPivot = params.get("pivot");
  const rawTraitement = params.get("traitement");
  let treatments: TreatmentKey[] | null;
  if (null !== rawTraitement) {
    treatments = decodeTreatments(rawTraitement);
  } else if ("masques" === params.get("traites")) {
    treatments = ["a_traiter"]; // legacy P4-207 « Masquer les traités »
  } else {
    treatments = null;
  }
  return {
    pivot: isPivot(rawPivot) ? rawPivot : "coach",
    families: decodeList(params.get("conflits"), CONFLICT_FAMILIES),
    treatments,
    homeOnly: "1" === params.get("domicile"),
  };
}

export function applyConflictsToParams(current: URLSearchParams, conflicts: ConflictsParams): URLSearchParams {
  const next = new URLSearchParams(current);
  if ("coach" === conflicts.pivot) {
    next.delete("pivot");
  } else {
    next.set("pivot", conflicts.pivot);
  }
  if (null === conflicts.families || conflicts.families.length === CONFLICT_FAMILIES.length) {
    next.delete("conflits");
  } else {
    next.set("conflits", conflicts.families.join(","));
  }
  // Le legacy `traites=masques` est TOUJOURS purgé (réécrit en `traitement=`).
  next.delete("traites");
  if (null === conflicts.treatments || conflicts.treatments.length === TREATMENT_KEYS.length) {
    next.delete("traitement");
  } else {
    next.set("traitement", conflicts.treatments.map((k) => TREATMENT_SLUG[k]).join(","));
  }
  if (conflicts.homeOnly) {
    next.set("domicile", "1");
  } else {
    next.delete("domicile");
  }
  return next;
}

/**
 * PR 2a « Configuration & navigation » — ancrage de la section ouverte de
 * `/matchs/configuration` (accordéon « une section = un écran »). `?section=<clé>`.
 *
 * Le gabarit/créneaux (→ `/matchs/semaine-type`) ET les adversaires (→ `/matchs/adversaires`,
 * C8) ont DÉMÉNAGÉ : `ConfigSection` ne porte plus que les quatre sections RÉGLAGE restantes
 * (`echeances|durees|reglages|libelles`). **Défaut = tout replié** : absent, `aucune` (toléré,
 * ancien encodage), une valeur inconnue, ou les clés déplacées `gabarit`/`creneaux`/`adversaires`
 * ⇒ `null` (aucune section ouverte). Écrire `null` SUPPRIME le param. Les redirections des
 * anciennes clés déplacées sont portées par `ConfigurationPage` (lecture du param brut). Mêmes
 * conventions que `?vue=`/`?temps=`.
 */
export type ConfigSection = "echeances" | "durees" | "reglages" | "libelles";

const CONFIG_SECTIONS: ConfigSection[] = ["echeances", "durees", "reglages", "libelles"];

function isConfigSection(value: string | null): value is ConfigSection {
  return null !== value && (CONFIG_SECTIONS as string[]).includes(value);
}

export function decodeSectionParam(params: URLSearchParams): ConfigSection | null {
  return isConfigSection(params.get("section")) ? (params.get("section") as ConfigSection) : null;
}

export function applySectionToParams(current: URLSearchParams, section: ConfigSection | null): URLSearchParams {
  const next = new URLSearchParams(current);
  if (null === section) {
    next.delete("section");
  } else {
    next.set("section", section);
  }
  return next;
}

/**
 * C8 — le filtre segmenté de l'onglet Adversaires (`/matchs/adversaires`), ancré `?filtre=<clé>`.
 * Trois segments : « Tous » (défaut, param ABSENT), « Sans gymnase » (`sans-gymnase` : club sans
 * aucun gymnase apparié) et « À apparier » (`a-apparier` : au moins un libellé de salle orphelin).
 * Défaut = « Tous » : absent, `tous`, ou une valeur inconnue (dont l'ancien `ville`) ⇒ `null`
 * (aucun filtre, aucun segment pressé). Écrire `null` SUPPRIME le param. Conventions de `?section=`.
 */
export type OpponentFilter = "sans-gymnase" | "a-apparier";

const OPPONENT_FILTERS: OpponentFilter[] = ["sans-gymnase", "a-apparier"];

function isOpponentFilter(value: string | null): value is OpponentFilter {
  return null !== value && (OPPONENT_FILTERS as string[]).includes(value);
}

export function decodeOpponentFilter(params: URLSearchParams): OpponentFilter | null {
  return isOpponentFilter(params.get("filtre")) ? (params.get("filtre") as OpponentFilter) : null;
}

export function applyOpponentFilterToParams(current: URLSearchParams, filter: OpponentFilter | null): URLSearchParams {
  const next = new URLSearchParams(current);
  if (null === filter) {
    next.delete("filtre");
  } else {
    next.set("filtre", filter);
  }
  return next;
}
