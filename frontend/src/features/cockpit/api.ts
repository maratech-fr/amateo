import { HTTPError } from "ky";

import { api } from "@/shared/api/client";
import { collectionAll } from "@/shared/api/collection";

/**
 * ADR-0002 inv. 4 (P2-38 PR3) — un AUTRE plan de période gouverne déjà tout ou partie de la
 * fenêtre du plan qui naît (409 `window_already_planned`). Le serveur NOMME déjà la période en
 * place, sa fenêtre et les issues (modifier / supprimer / découper en semaines) dans `message` —
 * le front l'AFFICHE tel quel (règle d'or : il ne réécrit pas une règle métier). `conflictingEntryId`
 * = l'entrée du planning en conflit, de quoi l'ouvrir « en place ».
 */
export class WindowAlreadyPlannedError extends Error {
  readonly conflictingEntryId: string;

  constructor(message: string, conflictingEntryId: string) {
    super(message);
    this.name = "WindowAlreadyPlannedError";
    this.conflictingEntryId = conflictingEntryId;
  }
}

/**
 * Traduit un 409 `window_already_planned` en {@link WindowAlreadyPlannedError} ; tout autre échec
 * → `null` (le pipeline normal reprend). Lecture par `error.data` (ky 2.x consomme lui-même le
 * corps ; re-lire `error.response` throw « body stream already read ») — patron `planning/api.ts`.
 */
export function asWindowAlreadyPlanned(error: unknown): WindowAlreadyPlannedError | null {
  if (error instanceof HTTPError && 409 === error.response.status) {
    const body = ((error as { data?: unknown }).data ?? {}) as { code?: string; error?: string; entryId?: string };
    if ("window_already_planned" === body.code && "string" === typeof body.entryId) {
      return new WindowAlreadyPlannedError(body.error ?? "Ces dates sont déjà planifiées par un autre planning.", body.entryId);
    }
  }

  return null;
}

export type CalendarEntryKind = "event" | "period";
export type CalendarEntryPeriodType = "closure" | "holiday" | "cutoff" | "mutualisation" | "custom";
export type CalendarEntryStatus = "proposed" | "active" | "ignored";

export interface CalendarEntry {
  id: string;
  kind: CalendarEntryKind;
  title: string;
  startDate: string;
  endDate: string;
  isDisruptive: boolean;
  periodType: CalendarEntryPeriodType | null;
  schoolHolidayId: string | null;
  /** Semaine ENFANT d'une période mère (P2-5 E1) ; null = entrée racine. */
  parentEntryId: string | null;
  status: CalendarEntryStatus;
  createdBy: string | null;
}

export type SchedulePlanType = "SEASON" | "CLOSURE" | "HOLIDAY";

/**
 * ADR-0002: le plan = LA RÉPONSE à un événement du calendrier. Un plan CLOSURE/HOLIDAY
 * naît avec le geste « ajuster cette période » (= la création du CalendarEntry), donc
 * il existe dès qu'une période existe — pas besoin d'attendre une génération.
 */
export interface SchedulePlan {
  id: string;
  type: SchedulePlanType;
  name: string;
  /** Sa fenêtre d'application (ADR-0002) — la SEULE clé de tri chronologique fiable :
   *  le nom porte une date en toutes lettres, que `localeCompare` ordonne « 10 août »
   *  avant « 3 août ». Déjà exposée par `SchedulePlanResource`. */
  startDate: string;
  calendarEntryId: string | null;
  chosenScheduleId: string | null;
  /** Period-editable structure: has this plan's team selection been configured once (seed guard)? */
  teamSelectionInitialized: boolean;
}

/** Le plan d'une période. null si l'entrée n'en porte pas (cutoff/mutualisation — inv. 9). */
export const getSchedulePlanForEntry = async (calendarEntryId: string): Promise<SchedulePlan | null> => {
  const items = await collectionAll<SchedulePlan>("schedule_plans", { calendarEntryId });

  return items[0] ?? null;
};

/**
 * Tous les plans de la saison (tenant + saison résolus côté serveur). Le radar en
 * dérive, PAR PÉRIODE, sa « version active » = chosenScheduleId du plan (ADR-0002
 * lot D-b) — binaire : validé → on montre, non validé → on ajuste. Un seul appel
 * plutôt qu'un hook par entrée (règles des hooks dans la liste du radar).
 */
export const getAllSchedulePlans = async (): Promise<SchedulePlan[]> => collectionAll<SchedulePlan>("schedule_plans");

/**
 * LE GESTE « Adapter » (ADR-0002 amendé 2026-07-24) : le plan d'une période
 * closure/holiday naît de ce POST — plus jamais de la création de l'entrée.
 * Idempotent : la période a déjà son plan → il est rendu tel quel (201).
 */
export const createSchedulePlan = async (calendarEntryId: string): Promise<SchedulePlan> => {
  try {
    return await api.post("schedule_plans", { json: { calendarEntryId } }).json<SchedulePlan>();
  } catch (error) {
    const conflict = asWindowAlreadyPlanned(error);
    if (null !== conflict) {
      throw conflict;
    }
    throw error;
  }
};

export interface SchoolHoliday {
  id: string;
  label: string;
  holidayType: string;
  startDate: string;
  endDate: string;
  schoolYear: string;
}

export interface SchoolHolidaysResponse {
  zone: string | null;
  items: SchoolHoliday[];
}

/** Single-day entry (unlike school holidays there is no start/end range). */
export interface PublicHoliday {
  id: string;
  date: string;
  label: string;
  national: boolean;
}

export interface PublicHolidaysResponse {
  zone: string | null;
  items: PublicHoliday[];
}

export interface EntryConflict {
  slotTemplateId: string;
  teamId: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  endTime: string;
  dates: string[];
}

/**
 * Une FERMETURE de gymnase déclarée sur la fenêtre de l'entrée (P2-22 PR 1, backend).
 * `weekdays` = jours ISO fermés (1 = lundi … 7 = dimanche) ∩ fenêtre de l'entrée, calculés
 * SERVEUR avec la même sémantique que le retrait des créneaux du payload solveur — le front
 * les LIT (régime 1), il ne dérive rien. Servie sur TOUTES les sorties du endpoint, même
 * `seasonPlanChosen=false`.
 */
export interface Closure {
  constraintId: string;
  venueId: string;
  title: string;
  startDate: string;
  endDate: string;
  weekdays: number[];
}

export interface EntryConflictsResponse {
  entryId: string;
  venueIds: string[];
  conflicts: EntryConflict[];
  /** Fermetures de gymnase déclarées sur la fenêtre (toujours servies, indépendantes de `seasonPlanChosen`). */
  closures: Closure[];
  /**
   * P2-37 D6 — les gymnases ENTIÈREMENT fermés sur la fenêtre : indisponibilité TOTALE
   * (toutes les dates fermées, relais de fermetures compris). DÉRIVÉE côté SERVEUR (jamais
   * stockée) — le front la LIT, il ne redérive PAS le calcul « toutes les dates fermées »
   * (règle d'or : `.claude/rules/frontend.md`). Servie sur TOUTES les sorties qui portent
   * `closures`, indépendante de `seasonPlanChosen`.
   */
  fullyClosedVenueIds: string[];
  /**
   * Indispo INFORMATIVE (décision fondateur 2026-08-18) — l'ÉTAT EFFECTIF jour par jour, AVEC
   * provenance : `{ venueId → { jour ISO "1".."7" → "manual" | "default-incident" } }`. Seuls les
   * jours EFFECTIVEMENT fermés y figurent (`manual` = décoché à la main ; `default-incident` =
   * hérité de l'indisponibilité déclarée). Composé SERVEUR (`PlanVenueClosures`) — le front le LIT,
   * il ne recompose JAMAIS incident × masque (règle d'or, `.claude/rules/frontend.md`).
   * Un gymnase DÉSACTIVÉ en est absent (il rejoint `disabledVenueIds`). Vide sans plan de période.
   */
  effectiveClosedWeekdays: Record<string, Record<string, "manual" | "default-incident">>;
  /** Les gymnases DÉSACTIVÉS pour la période (mode DISABLED), calculés SERVEUR. Vide sans plan. */
  disabledVenueIds: string[];
  /**
   * Le plan de la saison pointe-t-il une version ? Sinon la saison n'a PAS de
   * calendrier et le radar n'a rien pu comparer : `conflicts: []` veut alors dire
   * « je ne sais pas », pas « aucun impact ». Sans ce drapeau les deux se lisent
   * pareil, et le gestionnaire conclut que sa fermeture de gymnase ne gêne personne.
   */
  seasonPlanChosen: boolean;
}

export interface CreateEventPayload {
  title: string;
  startDate: string;
  endDate: string;
  isDisruptive: boolean;
}

export interface CreateClosurePayload {
  title: string;
  startDate: string;
  endDate: string;
  venueId: string;
}

export interface CreateCutoffPayload {
  title: string;
  startDate: string;
  endDate: string;
}

/** Calendar entries overlapping the [from, to] window. Paginated endpoint → page through it. */
export const getCalendarEntries = (from: string, to: string): Promise<CalendarEntry[]> =>
  collectionAll<CalendarEntry>("calendar_entries", { from, to });

export const getCalendarEntry = (id: string): Promise<CalendarEntry> => api.get(`calendar_entries/${id}`).json();

export const createCalendarEntry = (json: Record<string, unknown>): Promise<CalendarEntry> =>
  api.post("calendar_entries", { json }).json();

export const deleteCalendarEntry = (id: string): Promise<unknown> => api.delete(`calendar_entries/${id}`).json();

export const getSchoolHolidays = (from?: string, to?: string): Promise<SchoolHolidaysResponse> =>
  api.get("school-holidays", from && to ? { searchParams: { from, to } } : undefined).json();

/** Explicit window: without from/to the backend needs an active season and 400s otherwise. */
export const getPublicHolidays = (from: string, to: string): Promise<PublicHolidaysResponse> =>
  api.get("public-holidays", { searchParams: { from, to } }).json();

export const getEntryConflicts = (entryId: string): Promise<EntryConflictsResponse> =>
  api.get(`calendar-entries/${entryId}/conflicts`).json();

/**
 * P2-38 (prévention) — UNE FENÊTRE déjà planifiée par un AUTRE plan de période, servie par le
 * backend pour que la modale d'ajustement cesse d'offrir une semaine dont la création serait
 * refusée en 409. Le `reason` est la PHRASE prête à afficher, composée SERVEUR (même foyer que le
 * refus 409) : le front l'affiche TELLE QUELLE, il n'en dérive ni n'en concatène aucune règle
 * métier (règle d'or, `.claude/rules/frontend.md`). `entryId` = l'entrée du planning en conflit,
 * pour le raccourci « Ouvrir le planning en place ».
 */
export interface PlannedWindow {
  entryId: string;
  title: string;
  startDate: string;
  endDate: string;
  label: string;
  reason: string;
}

/**
 * Les fenêtres déjà gouvernées par un AUTRE plan de période sur [start, end]. Deux chemins d'appel
 * (le backend en exige exactement un) : `entryId` quand la mère est matérialisée, `seasonId` quand
 * la mère n'existe pas encore (chemin pending). Rend directement le tableau `windows` (trié par date
 * côté serveur).
 */
export const getPlannedWindows = async (params: { start: string; end: string; entryId?: string; seasonId?: string }): Promise<PlannedWindow[]> => {
  const searchParams: Record<string, string> = { start: params.start, end: params.end };
  if (undefined !== params.entryId) {
    searchParams.entryId = params.entryId;
  }
  if (undefined !== params.seasonId) {
    searchParams.seasonId = params.seasonId;
  }
  const response = await api.get("planned-windows", { searchParams }).json<{ windows: PlannedWindow[] }>();

  return response.windows;
};
