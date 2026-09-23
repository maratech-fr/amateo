import { api } from "@/shared/api/client";

// ── P2-54 RMM-9 PR-3 — le radar SPATIAL : le trajet siège club ↔ lieu adverse ──

/** How precisely an away opponent's venue is known (mirror of the backend enum, présentation seule). */
export type OpponentLocationPrecision = "VENUE" | "CITY";
export type OpponentTravelSource = "AUTO" | "MANUAL";

/**
 * Un gymnase APPARIÉ d'un club adverse (`OpponentVenueLink`), TOUT calculé côté serveur.
 * Amendement 2026-09-20 : le gymnase se rattache au club adverse et au libellé de salle,
 * jamais à l'équipe. `travelMinutes` = aller simple ; `travelStatus` done|pending|unavailable ;
 * `fixtureCount` = rencontres qui pointent ce gymnase ; `fallbackVenueName` = le gymnase qui
 * recueillerait ses rencontres s'il était retiré (null si dernier) — le front ne le dérive JAMAIS.
 */
export interface OpponentVenue {
  id: string;
  label: string;
  externalRef: string | null;
  /** Coordonnées fédérales du gymnase (publiques) — repassées telles quelles au geste de fusion. */
  latitude: number;
  longitude: number;
  source: OpponentTravelSource;
  travelMinutes: number | null;
  travelStatus: "done" | "pending" | "unavailable";
  approximated: boolean;
  fixtureCount: number;
  fallbackVenueName: string | null;
}

/** Un libellé de salle du fichier encore SANS lien (« à apparier »). */
export interface OpponentUnmatchedLabel {
  label: string;
  fixtureCount: number;
}

/**
 * Un CLUB adverse joué à l'extérieur, avec ses gymnases appariés et ses libellés à apparier.
 * `code` null = adversaire sans code fédéral (non appariable). Tout vient du serveur.
 */
export interface OpponentClub {
  code: string | null;
  /**
   * La clé d'appariement SERVIE par le backend : le code fédéral quand il existe, sinon une clé
   * sentinelle locale dérivée du libellé (adversaire sans code, apparié localement). Le front
   * l'utilise telle quelle dans les routes d'écriture ; il ne la redérive JAMAIS
   * (🔴 .claude/rules/frontend.md).
   */
  pairingKey: string;
  name: string;
  city: string | null;
  postalCode: string | null;
  precision: OpponentLocationPrecision | null;
  hasLogo: boolean;
  fixtureCount: number;
  venues: OpponentVenue[];
  unmatchedLabels: OpponentUnmatchedLabel[];
}

export interface OpponentTravelPayload {
  clubGeolocated: boolean;
  opponents: OpponentClub[];
}

/** GET /api/opponents/travel — la liste PAR CLUB adverse + le booléen « siège localisé ». */
export const getOpponentTravel = async (): Promise<OpponentTravelPayload> => {
  const payload = await api.get("opponents/travel").json<{ clubGeolocated: boolean; opponents: OpponentClub[] }>();
  return { clubGeolocated: payload.clubGeolocated, opponents: payload.opponents };
};

/** La réponse d'écriture d'un lien + le compte résultant du gymnase cible (fusion « en portera N »). */
export interface VenueLinkWriteResult {
  id: string;
  opponentOrganismeCode: string;
  fbiLabel: string;
  label: string;
  externalRef: string | null;
  source: OpponentTravelSource;
  travelMinutes: number | null;
  targetFixtureCount: number;
}

export interface AddOpponentVenueInput {
  code: string;
  venueLabel: string;
  venueExternalRef?: string | null;
  latitude: number;
  longitude: number;
  /** Le libellé de fichier que ce gymnase couvre ; par défaut le libellé du gymnase. */
  fbiLabel?: string;
}

/** Ajoute un gymnase pour un adversaire (lien MANUAL, ref fédérale ou coordonnées). */
export const addOpponentVenue = ({ code, ...body }: AddOpponentVenueInput): Promise<VenueLinkWriteResult> =>
  api.post(`opponents/${encodeURIComponent(code)}/venues`, { json: body }).json<VenueLinkWriteResult>();

export interface PairVenueLabelInput {
  code: string;
  fbiLabel: string;
  venueLabel: string;
  venueExternalRef?: string | null;
  latitude: number;
  longitude: number;
}

/** Apparie un libellé de salle ORPHELIN à un gymnase (lien MANUAL). */
export const pairOpponentVenueLabel = ({ code, ...body }: PairVenueLabelInput): Promise<VenueLinkWriteResult> =>
  api.post(`opponents/${encodeURIComponent(code)}/venue-links`, { json: body }).json<VenueLinkWriteResult>();

export interface RepointVenueLinkInput {
  id: string;
  venueLabel: string;
  venueExternalRef?: string | null;
  latitude: number;
  longitude: number;
}

/** Ré-apparie / fusionne un lien vers un autre gymnase (le libellé reste reconnu à l'import). */
export const repointVenueLink = ({ id, ...body }: RepointVenueLinkInput): Promise<VenueLinkWriteResult> =>
  api.put(`opponents/venue-links/${encodeURIComponent(id)}`, { json: body }).json<VenueLinkWriteResult>();

/** Retire l'appariement LOCAL (le catalogue fédéral n'est jamais touché). */
export const deleteVenueLink = (id: string): Promise<unknown> =>
  api.delete(`opponents/venue-links/${encodeURIComponent(id)}`).then(() => null);

/** L'origine fédérale d'un gymnase suggéré pour un adversaire — observé (API) ou choisi par des clubs (manuel). */
export type VenueSuggestionSource = "FFBB_API" | "MANUAL";

/**
 * Un gymnase connu d'un adversaire, PARTAGÉ entre clubs (table globale, données fédérales
 * seulement). « Un COMPTE, jamais un QUI » : `chosenByCount` compte des choix, jamais des
 * clubs identifiés ; `lastChosenAt` est servi au JOUR seul. Tout vient du backend.
 */
export interface VenueSuggestion {
  /** Le n° de salle FFBB pour une suggestion MANUELLE ; null pour une observée en API. */
  externalRef: string | null;
  label: string;
  city: string | null;
  postalCode: string | null;
  latitude: number | null;
  longitude: number | null;
  source: VenueSuggestionSource;
  /** Combien de fois ce gymnase a été choisi (par club/saison/équipe) — jamais par QUI. */
  chosenByCount: number;
  /** Jour seul (Y-m-d), jamais l'heure. */
  lastChosenAt: string | null;
}

/** Les gymnases connus d'un adversaire (partagés) — FFBB_API d'abord, puis MANUAL par compte décroissant. */
export const getVenueSuggestions = async (code: string): Promise<VenueSuggestion[]> =>
  (await api.get(`opponents/${encodeURIComponent(code)}/venue-suggestions`).json<{ code: string; suggestions: VenueSuggestion[] }>()).suggestions;

/**
 * C6 — le recalcul quitte le rail synchrone : `POST /api/opponents/travel/resolve` DISPATCHE au
 * worker (rafale IGN pacée > plafond HTTP) et répond `{queued}` immédiatement. La progression et
 * les trajets arrivent ensuite par Mercure (`club:{clubId}:travel`), `travelStatus` par ligne au
 * prochain GET. `resolve()` ne route QUE les trajets manquants (C5) — c'est le « Réessayer les
 * manquants ».
 */
export interface OpponentTravelResolveResult {
  queued: boolean;
  /** C6 (sécurité H) — un calcul était DÉJÀ en cours pour le club : rien n'a été dispatché. */
  alreadyRunning: boolean;
}

/** Lance le recalcul des trajets AUTO MANQUANTS du club+saison (le MANUAL est préservé). */
export const resolveOpponentTravel = (): Promise<OpponentTravelResolveResult> =>
  api.post("opponents/travel/resolve").json<OpponentTravelResolveResult>();

// ── Rattrapage des codes FFBB des adversaires — annuaire GLOBAL (PR 2a) ───────
/**
 * La réponse du rattrapage d'annuaire (`POST /api/opponents/resolve`) : combien d'adversaires
 * AWAY ont VU leur localisation résolue (`resolved`), la liste des non résolus (`unresolved`),
 * ceux déjà à jour (`skipped`) et combien de fixtures ont été estampillées de leur code
 * (`stamped`) — le rattrapage écrit la table GLOBALE et estampille les codes des rencontres.
 */
export interface OpponentResolveResult {
  resolved: number;
  unresolved: string[];
  skipped: number;
  stamped: number;
}

/** Rattrape les codes FFBB des adversaires AWAY (annuaire global + estampille les rencontres). Best-effort, management. */
export const resolveOpponents = (): Promise<OpponentResolveResult> => api.post("opponents/resolve").json<OpponentResolveResult>();

/**
 * La réponse de la mise à jour groupée (`POST /api/opponents/refresh`, PR 2b) : les TROIS passes
 * best-effort en un seul appel — (`codes`) rattrapage des codes FFBB de l'annuaire, (`autoLocated`)
 * localisation des gymnases depuis le libellé du fichier FBI, (`travel`) recalcul des trajets AUTO.
 * Chaque passe est indépendante (l'échec de l'une n'annule pas les autres). Champs alignés sur le
 * snapshot OpenAPI (`/api/opponents/refresh`).
 */
export type OpponentRefreshStep = "codes" | "auto-locate" | "travel";

export interface OpponentRefreshResult {
  codes: { resolved: number; unresolved: string[]; skipped: number; stamped: number };
  autoLocated: { located: number; ambiguous: number; unmatched: number; skipped: number };
  /** C6 — le recalcul des trajets est DISPATCHÉ au worker : la réponse dit qu'il est en file
   *  et combien d'adversaires distincts il traitera (la progression arrive par Mercure). */
  travel: { queued: boolean; alreadyRunning: boolean; pending: number };
  /** Les passes best-effort qui ont levé et sont retombées sur leur résultat neutre (vide en régime nominal). */
  failedSteps: OpponentRefreshStep[];
}

/** Met à jour tous les adversaires AWAY en UN appel : codes FFBB + gymnases depuis le fichier + trajets. Best-effort, management. */
export const refreshOpponents = (): Promise<OpponentRefreshResult> => api.post("opponents/refresh").json<OpponentRefreshResult>();
