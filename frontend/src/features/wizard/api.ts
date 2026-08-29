import { HTTPError } from "ky";

import { api } from "@/shared/api/client";
import { collection, collectionAll } from "@/shared/api/collection";
import type { DeletionImpact } from "@/shared/api/deletionImpact";
import type { ToReplaceEntry } from "@/features/planning/lib/toReplaceReason";
import { sortByName } from "@/shared/lib/nameOrder";
// P4-148 — `Gender`/`TeamLevel` (l'identité FFBB d'une équipe) sont descendus dans shared/lib/ ;
// on les ré-exporte ici pour ne pas casser les ~consommateurs qui les lisent depuis ce module.
import type { Gender, TeamLevel } from "@/shared/lib/teamIdentity";

export type { Gender, TeamLevel };

export interface Team {
  id: string;
  name: string;
  sportCategoryId: string;
  priorityTierId: number;
  tierOrder: number;
  gender: Gender | null;
  level: TeamLevel | null;
  sessionsPerWeek: number;
  isActive: boolean;
  /**
   * L'équipe joue déjà en compétition — vrai dès qu'elle porte AU MOINS UN match, quel
   * qu'en soit le statut : la correspondance faite par l'import FBI suffit, la
   * fédération la connaît. Donc ni suppression ni changement de niveau. Son nom, son
   * rang, son `isActive` et ses créneaux restent libres.
   *
   * Vient du serveur — celui-là même qui refuse ces écritures. Le recalculer ici
   * ferait un second endroit qui répond « engagée ? », et il finirait par répondre
   * autre chose que le serveur : l'écran offrirait un geste toujours refusé.
   */
  isEngaged?: boolean;
}

export interface SportCategory {
  id: string;
  name: string;
  sortOrder: number;
}

export interface PriorityTier {
  id: number;
  label: string;
  name: string;
  color: string | null;
}

export interface TeamPayload {
  name: string;
  sportCategoryId?: string;
  priorityTierId?: number;
  tierOrder?: number;
  gender?: Gender | null;
  level?: TeamLevel | null;
  sessionsPerWeek?: number;
  isActive?: boolean;
}

export const listTeams = (): Promise<Team[]> => collectionAll<Team>("teams");
export const listSportCategories = (): Promise<SportCategory[]> => collection<SportCategory>("sport_categories");
export const listPriorityTiers = (): Promise<PriorityTier[]> => collection<PriorityTier>("priority_tiers");

export const createTeam = (body: TeamPayload): Promise<Team> => api.post("teams", { json: body }).json();
export const updateTeam = (id: string, body: TeamPayload): Promise<Team> => api.put(`teams/${id}`, { json: body }).json();
/** Bulk reorder in one transaction (atomic) — avoids N concurrent PUTs racing on the optimistic-lock version. */
export const reorderTeams = (items: { id: string; priorityTierId: number; tierOrder: number }[]): Promise<unknown> =>
  api.post("teams/reorder", { json: { items } }).json();
export const deleteTeam = (id: string): Promise<void> => api.delete(`teams/${id}`).then(() => undefined);

// --- Venues + availability slots (W2) ---

export interface Venue {
  id: string;
  name: string;
  color: string | null;
  canSplit: boolean;
  isActive: boolean;
  /** Numéro fédéral FFBB (ancre P2-20/P2-21) — null pour un gymnase saisi à la main. */
  externalRef?: string | null;
  /** P2-53 RMM-8 — adresse saisie (géocodée en lat/long via GET /api/geocode). null tant que non renseignée. */
  address?: string | null;
  /** GPS posé par une salle FFBB (P2-20) OU par le géocodage d'une adresse (P2-53). Chaînes numériques. */
  latitude?: string | null;
  longitude?: string | null;
}

export interface VenueTrainingSlot {
  id: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
  capacity: number;
  /** P2-17 — libellé d'un créneau mutualisé (« CEC3 »), ≤ 40 ; null/absent quand il n'en porte pas.
   *  Le backend le trim, normalise vide→null et le refuse (422) sur une capacité < 2. Esthétique. */
  groupLabel?: string | null;
  /** null = créneau de SAISON ; set = créneau de la grille que ce plan de période possède (#8). */
  schedulePlanId?: string | null;
}

export interface VenuePayload {
  name: string;
  color?: string | null;
  canSplit?: boolean;
  isActive?: boolean;
  // Ancrage FFBB (P2-20) : posés quand le gymnase est créé depuis une
  // suggestion de salle — numéro fédéral + GPS, colonnes déjà en base.
  externalRef?: string | null;
  latitude?: string | null;
  longitude?: string | null;
  /** P2-53 RMM-8 — adresse saisie, posée par le géocodage de `VenuesStep`. null = inchangée (PUT partiel). */
  address?: string | null;
  /**
   * v2 cohérence canSplit — confirme une transition « terrain divisible » true→false alors que
   * des créneaux du gymnase accueillent 2 équipes ou plus. Sans elle, le backend refuse (422) ;
   * avec elle, il ramène ces créneaux à 1 et vide leurs réservations. Posée par la modale de
   * confirmation de `VenuesStep` uniquement.
   */
  confirmSplitCascade?: boolean;
}

/** Une salle FFBB de la commune (P2-20) — mapping serveur, jamais le hit brut. */
export interface FfbbSalle {
  name: string;
  address: string | null;
  city: string | null;
  externalRef: string | null;
  latitude: string | null;
  longitude: string | null;
}

/** Les salles FFBB d'un CP (management). Sans CP exploitable : liste vide, pas d'erreur. */
export const listFfbbSalles = (postalCode: string): Promise<{ postalCode: string | null; salles: FfbbSalle[] }> =>
  api.get("ffbb/salles", { searchParams: { postalCode } }).json();

/** Les salles PROCHES du club, triées par distance (P2-21 lot D). radius null = auto-élargi 3→20 km. */
export const listFfbbSallesProches = (radiusKm: number | null): Promise<{ radiusKm: number | null; salles: FfbbSalle[] }> =>
  api.get("ffbb/salles-proches", null === radiusKm ? undefined : { searchParams: { radius: radiusKm } }).json();

export interface SlotPayload {
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
  capacity: number;
  /** P2-17 — libellé de groupe envoyé tel quel (le backend trim/normalise vide→null) ; null pour
   *  l'effacer. L'éditeur ne l'envoie non nul que si la capacité effective est ≥ 2 (aligné 422). */
  groupLabel?: string | null;
  /** Period-editable structure: set to scope the slot to a PLAN (gym lent for the window). */
  schedulePlanId?: string | null;
}

export const listVenues = async (): Promise<Venue[]> => sortByName(await collectionAll<Venue>("venues"));
export const listVenueSlots = (): Promise<VenueTrainingSlot[]> => collectionAll<VenueTrainingSlot>("venue_training_slots");
/** #8 — la grille que la période POSSÈDE (copie du modèle de saison, plus rien d'additif). */
export const listPeriodSlots = (schedulePlanId: string): Promise<VenueTrainingSlot[]> => collectionAll<VenueTrainingSlot>("venue_training_slots", { schedulePlanId });
export const createVenue = (body: VenuePayload): Promise<Venue> => api.post("venues", { json: { source: "manual", ...body } }).json();
export const updateVenue = (id: string, body: VenuePayload): Promise<Venue> => api.put(`venues/${id}`, { json: { source: "manual", ...body } }).json();
export const deleteVenue = (id: string): Promise<void> => api.delete(`venues/${id}`).then(() => undefined);

/**
 * Les entités qu'une suppression peut viser. Le TYPE de l'impact (`DeletionImpact`)
 * vit dans `@/shared/api/deletionImpact` (P4-123) ; le geste reste ici.
 */
export type DeletableKind = "venue" | "team" | "coach" | "slot";

const DELETION_IMPACT_PATH: Record<DeletableKind, string> = { venue: "venues", team: "teams", coach: "coaches", slot: "venue_training_slots" };

export const fetchDeletionImpact = (kind: DeletableKind, id: string): Promise<DeletionImpact> =>
  api.get(`${DELETION_IMPACT_PATH[kind]}/${id}/deletion-impact`).json<DeletionImpact>();

export const createSlot = (body: SlotPayload): Promise<VenueTrainingSlot> => api.post("venue_training_slots", { json: body }).json();
export const updateSlot = (id: string, body: SlotPayload): Promise<VenueTrainingSlot> => api.put(`venue_training_slots/${id}`, { json: body }).json();
export const deleteSlot = (id: string): Promise<void> => api.delete(`venue_training_slots/${id}`).then(() => undefined);

/**
 * #8 — le MODE d'un gymnase POUR une période. Réglage SPARSE : pas de ligne = « hériter »,
 * le défaut. Seuls les deux écarts sont stockés.
 *
 * `DISABLED` : le gymnase ne sert pas cette période — sa grille est CONSERVÉE (on la voit
 * à l'écran, elle sort juste du payload envoyé au solveur), pour que réactiver ne coûte
 * jamais la saisie déjà faite.
 * `BLANK` : on repart d'une grille vierge — destructif, à confirmer.
 */
export type VenuePeriodMode = "DISABLED" | "BLANK";
/** Le masque manuel tri-état d'un jour : OPEN (rouvert) / CLOSED (fermé). L'absence = « hériter ». */
export type VenueDayState = "OPEN" | "CLOSED";
/** Masque manuel SPARSE : jour ISO (1..7) → OPEN|CLOSED. Clés string en JSON. */
export type VenueDayMask = Record<string, VenueDayState>;

export interface VenuePeriodOverride {
  id: string;
  schedulePlanId: string;
  venueId: string;
  /**
   * FACULTATIF (indispo informative, 2026-08-18) : une ligne peut n'exister que pour son masque.
   * null = pas de mode (hériter), le défaut étant matérialisé par l'ABSENCE de ligne (DELETE).
   */
  mode: VenuePeriodMode | null;
  /** Masque manuel jour ISO → OPEN|CLOSED (ou null). Le front le LIT ; la composition vit serveur. */
  dayOverrides: VenueDayMask | null;
}

/** Corps d'écriture d'un réglage (mode ET/OU masque — au moins l'un, garde serveur). */
export interface VenuePeriodOverridePayload {
  schedulePlanId: string;
  venueId: string;
  mode?: VenuePeriodMode | null;
  dayOverrides?: VenueDayMask | null;
}

export const listVenuePeriodOverrides = (schedulePlanId: string): Promise<VenuePeriodOverride[]> =>
  collectionAll<VenuePeriodOverride>("venue_period_overrides", { schedulePlanId });
export const createVenuePeriodOverride = (body: VenuePeriodOverridePayload): Promise<VenuePeriodOverride> =>
  api.post("venue_period_overrides", { json: body }).json();
export const updateVenuePeriodOverride = (id: string, body: VenuePeriodOverridePayload): Promise<VenuePeriodOverride> =>
  api.put(`venue_period_overrides/${id}`, { json: body }).json();
/** Retirer la ligne = revenir au défaut « hériter ». Depuis VIERGE, cela reprend la grille
 *  de saison ; depuis DÉSACTIVÉ, cela réactive SANS toucher à la grille (revue #8 round 4). */
export const deleteVenuePeriodOverride = (id: string): Promise<void> =>
  api.delete(`venue_period_overrides/${id}`).then(() => undefined);
/** Action atomique : vider puis recopier le modèle de saison pour CE gymnase. Destructif. */
export const resetVenuePeriodGrid = (schedulePlanId: string, venueId: string): Promise<unknown> =>
  api.post("venue_period_overrides/reset-grid", { json: { schedulePlanId, venueId } }).json();
/** Action atomique : vider la grille de CE gymnase. Destructif, idempotent (vider une
 *  grille déjà vide ne fait rien) — jamais un PUT de mode BLANK, qui serait un no-op. */
export const clearVenuePeriodGrid = (schedulePlanId: string, venueId: string): Promise<unknown> =>
  api.post("venue_period_overrides/clear-grid", { json: { schedulePlanId, venueId } }).json();

/**
 * Period-editable structure: a sparse per-(plan, team) override — off for the period, or a
 * different session count. Ancré au PLAN (ADR-0002 inv. 5), pas au déclencheur calendrier :
 * c'est ce qui permettra à 2 semaines de vacances (2 plans) de porter 2 jeux de réglages.
 */
export interface TeamPeriodOverride {
  id: string;
  schedulePlanId: string;
  teamId: string;
  isActive: boolean;
  sessionsPerWeek: number | null;
}

export interface TeamPeriodOverridePayload {
  schedulePlanId: string;
  teamId: string;
  isActive: boolean;
  sessionsPerWeek?: number | null;
}

export const listTeamPeriodOverrides = (schedulePlanId: string): Promise<TeamPeriodOverride[]> => collectionAll<TeamPeriodOverride>("team_period_overrides", { schedulePlanId });
export const createTeamPeriodOverride = (body: TeamPeriodOverridePayload): Promise<TeamPeriodOverride> => api.post("team_period_overrides", { json: body }).json();
export const updateTeamPeriodOverride = (id: string, body: TeamPeriodOverridePayload): Promise<TeamPeriodOverride> => api.put(`team_period_overrides/${id}`, { json: body }).json();
export const deleteTeamPeriodOverride = (id: string): Promise<void> => api.delete(`team_period_overrides/${id}`).then(() => undefined);

/** Period-editable structure: a sparse per-(period, constraint) toggle — isActive=false disables the permanent constraint for the period. No row = applies as usual. */
export interface ConstraintPeriodOverride {
  id: string;
  schedulePlanId: string;
  constraintId: string;
  isActive: boolean;
}

export interface ConstraintPeriodOverridePayload {
  schedulePlanId: string;
  constraintId: string;
  isActive: boolean;
}

export const listConstraintPeriodOverrides = (schedulePlanId: string): Promise<ConstraintPeriodOverride[]> => collectionAll<ConstraintPeriodOverride>("constraint_period_overrides", { schedulePlanId });
export const createConstraintPeriodOverride = (body: ConstraintPeriodOverridePayload): Promise<ConstraintPeriodOverride> => api.post("constraint_period_overrides", { json: body }).json();
export const updateConstraintPeriodOverride = (id: string, body: ConstraintPeriodOverridePayload): Promise<ConstraintPeriodOverride> => api.put(`constraint_period_overrides/${id}`, { json: body }).json();
export const deleteConstraintPeriodOverride = (id: string): Promise<void> => api.delete(`constraint_period_overrides/${id}`).then(() => undefined);

/** A persistent team→slot HARD pin (base plan or a period overlay). Server-backed. */
export interface Reservation {
  id: string;
  schedulePlanId: string | null;
  teamId: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
}

export interface ReservationPayload {
  teamId: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
  schedulePlanId?: string | null;
}

export const listReservations = (params?: Record<string, string>): Promise<Reservation[]> => collectionAll<Reservation>("reservations", params);
export const createReservation = (body: ReservationPayload): Promise<Reservation> => api.post("reservations", { json: body }).json();
export const deleteReservation = (id: string): Promise<void> => api.delete(`reservations/${id}`).then(() => undefined);

/**
 * Rail BATCH de mutualisation (P2-46 PR-2) : poser UN groupe sur UNE case écrit ses N réservations
 * en un seul flush atomique. Le retrait n'a pas de route dédiée — c'est N `deleteReservation`.
 */
export interface GroupReservationPayload {
  sharedTrainingGroupId: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
  schedulePlanId?: string | null;
}

export const createGroupReservation = (body: GroupReservationPayload): Promise<{ ids: string[]; count: number }> =>
  api.post("reservations/group", { json: body }).json();

// --- Shared training groups (P2-27) : N teams train TOGETHER, K common sessions ---

/**
 * A mutualisation declaration: N teams (2..10) train together with EXACTLY
 * `commonSessions` common sessions. Club+season scoped, filtered by
 * `schedulePlanId` (null = season socle, a UUID = a period plan). Server-backed;
 * the server is the sole judge of validity (see the DTO + processor).
 */
export interface SharedTrainingGroup {
  id: string;
  version: number;
  createdAt: string;
  updatedAt: string;
  schedulePlanId: string | null;
  teamIds: string[];
  commonSessions: number;
}

/** POST carries the scope (`schedulePlanId`); PUT never remaps it (the plan is fixed at creation). */
export interface SharedTrainingGroupPayload {
  teamIds: string[];
  commonSessions: number;
  schedulePlanId?: string | null;
}

/**
 * Sans `schedulePlanId`, le provider renvoie le socle ET les périodes (le front trie) :
 * pour la portée socle on liste sans param puis on filtre `null === schedulePlanId`.
 */
export const listSharedTrainingGroups = (params?: Record<string, string>): Promise<SharedTrainingGroup[]> =>
  collectionAll<SharedTrainingGroup>("shared_training_groups", params);
export const createSharedTrainingGroup = (body: SharedTrainingGroupPayload): Promise<SharedTrainingGroup> =>
  api.post("shared_training_groups", { json: body }).json();
export const updateSharedTrainingGroup = (id: string, body: { teamIds: string[]; commonSessions: number }): Promise<SharedTrainingGroup> =>
  api.put(`shared_training_groups/${id}`, { json: body }).json();
export const deleteSharedTrainingGroup = (id: string): Promise<void> => api.delete(`shared_training_groups/${id}`).then(() => undefined);

// --- Coaches + links (W3) ---

export type TeamCoachRole = "MAIN" | "ASSISTANT";

export interface Coach {
  id: string;
  firstName: string;
  lastName: string;
  email: string | null;
  isEmployee: boolean;
  isActive: boolean;
  /** P4-51 — plafond de jours au club par semaine. null = pas de plafond. PRÉFÉRÉ côté
   *  solveur : il regroupe quand il peut, ne sacrifie jamais une séance, et le récap
   *  nomme le dépassement quand il n'y arrive pas. */
  maxDaysOverride: number | null;
  /** P2-53 RMM-8 — le coach a un véhicule. Détermine le barème de trajet appliqué à ses
   *  enchaînements (voiture s'il est véhiculé, à pied sinon). Défaut false. */
  isVehicled: boolean;
}

export interface TeamCoach {
  id: string;
  teamId: string;
  coachId: string;
  role: TeamCoachRole;
}

export interface CoachPlayerMembership {
  id: string;
  teamId: string;
  coachId: string;
  isActive: boolean;
}

export interface CoachPayload {
  firstName: string;
  lastName?: string | null;
  email?: string | null;
  isEmployee?: boolean;
  isActive?: boolean;
  maxDaysOverride?: number | null;
  /** P2-53 RMM-8 — statut véhiculé (PUT partiel : null = inchangé côté serveur). */
  isVehicled?: boolean | null;
}

export const listCoaches = (): Promise<Coach[]> => collectionAll<Coach>("coaches");
export const createCoach = (body: CoachPayload): Promise<Coach> => api.post("coaches", { json: body }).json();
export const updateCoach = (id: string, body: CoachPayload): Promise<Coach> => api.put(`coaches/${id}`, { json: body }).json();
export const deleteCoach = (id: string): Promise<void> => api.delete(`coaches/${id}`).then(() => undefined);

export const listTeamCoaches = (): Promise<TeamCoach[]> => collectionAll<TeamCoach>("team_coaches");
export const createTeamCoach = (body: { teamId: string; coachId: string; role: TeamCoachRole }): Promise<TeamCoach> => api.post("team_coaches", { json: body }).json();
export const deleteTeamCoach = (id: string): Promise<void> => api.delete(`team_coaches/${id}`).then(() => undefined);

export const listCoachPlayers = (): Promise<CoachPlayerMembership[]> => collectionAll<CoachPlayerMembership>("coach_player_memberships");
export const createCoachPlayer = (body: { teamId: string; coachId: string; isActive: boolean }): Promise<CoachPlayerMembership> =>
  api.post("coach_player_memberships", { json: body }).json();
export const deleteCoachPlayer = (id: string): Promise<void> => api.delete(`coach_player_memberships/${id}`).then(() => undefined);

// --- Travel-time matrix + geocoding (P2-53 RMM-8) ---

/** L'origine d'une valeur de trajet : AUTO (calculée par l'IGN) ou MANUAL (saisie, jamais écrasée). */
export type VenueTravelTimeSource = "AUTO" | "MANUAL";

/**
 * Un barème de trajet entre DEUX gymnases (couple symétrique, un par couple : venueAId < venueBId
 * normalisé serveur). Deux modes, chacun avec sa minute et sa source ; null tant qu'un mode n'est
 * pas renseigné. Le club+saison est résolu serveur-side (SeasonFilter) — pas de param.
 */
export interface VenueTravelTime {
  id: string;
  venueAId: string;
  venueBId: string;
  drivingMinutes: number | null;
  walkingMinutes: number | null;
  drivingSource: VenueTravelTimeSource | null;
  walkingSource: VenueTravelTimeSource | null;
}

/** Écriture d'un couple : une valeur écrite pose MANUAL (VenueTravelTimeStateProcessor). */
export interface VenueTravelTimePayload {
  venueAId: string;
  venueBId: string;
  drivingMinutes?: number | null;
  walkingMinutes?: number | null;
}

/** La raison qu'un couple n'a pu être résolu par l'autofill (servie, jamais devinée). */
export type AutofillUnresolvedReason = "missing_geo" | "routing_failed" | "budget_exceeded";

/** Le verdict d'un autofill : combien remplis, quels couples non résolus (avec raison), combien de
 *  valeurs MANUAL préservées. */
export interface VenueTravelTimeAutofillResult {
  filled: number;
  unresolved: { venueAId: string; venueBId: string; reason: AutofillUnresolvedReason }[];
  skippedManual: number;
}

/** Un candidat d'adresse rendu par la BAN (mapping serveur, jamais le hit brut). */
export interface GeocodeCandidate {
  label: string;
  latitude: number;
  longitude: number;
  /** Score de pertinence BAN, 0..1. */
  score: number;
}

/** La matrice du club+saison courant (SeasonFilter serveur-side, aucun param). */
export const listVenueTravelTimes = (): Promise<VenueTravelTime[]> => collectionAll<VenueTravelTime>("venue_travel_times");

export const createVenueTravelTime = (body: VenueTravelTimePayload): Promise<VenueTravelTime> => api.post("venue_travel_times", { json: body }).json();

export const updateVenueTravelTime = (id: string, body: VenueTravelTimePayload): Promise<VenueTravelTime> => api.put(`venue_travel_times/${id}`, { json: body }).json();

/** POST /api/venue-travel-times/autofill (tirets) — remplit AUTO, saute les MANUAL. 422 cap / 429 / 409. */
export const autofillVenueTravelTimes = (): Promise<VenueTravelTimeAutofillResult> => api.post("venue-travel-times/autofill", { json: {} }).json();

/** GET /api/geocode?q= (management) — jamais un appel direct à la BAN (frontière §2). 422 q<3/>200, 502 BAN down. */
export const geocodeAddress = (q: string): Promise<GeocodeCandidate[]> =>
  api
    .get("geocode", { searchParams: { q } })
    .json<{ candidates: GeocodeCandidate[] }>()
    .then((r) => r.candidates);

// --- Levier d'intensité de la règle de trajet (P2-53 RMM-8 PR-4) ---

/**
 * L'intensité de la règle « Trajet entre gymnases » — vocabulaire des passerelles côté
 * entraînement : PREFERRED (préférence souple) ou MANDATORY (obligatoire). PAS le vocabulaire
 * bien-être (HARD/PREFERRED/OFF) : c'est un store DÉDIÉ, singleton par club+saison.
 */
export type VenueTravelRuleIntensity = "PREFERRED" | "MANDATORY";

/** Le levier RÉSOLU (stocké OU défaut PREFERRED). `isDefault` : true tant qu'aucune ligne stockée. */
export interface VenueTravelRuleSetting {
  ruleKey: "travelTime";
  intensity: VenueTravelRuleIntensity;
  isDefault: boolean;
}

/** GET du levier résolu du club+saison courant (SeasonFilter serveur-side, identifiant fixe). */
export const getTravelRuleSetting = (): Promise<VenueTravelRuleSetting> => api.get("venue_travel_rule_settings/travelTime").json();

/** PUT du levier (management) — 422 sur un vocabulaire bien-être, 409 saison archivée. */
export const updateTravelRuleSetting = (intensity: VenueTravelRuleIntensity): Promise<VenueTravelRuleSetting> =>
  api.put("venue_travel_rule_settings/travelTime", { json: { intensity } }).json();

// --- Constraints (W4) ---

export type ConstraintFamily = "TIME" | "DAY" | "FACILITY" | "COACH_AVAILABILITY";
export type ConstraintScope = "CLUB" | "TEAM" | "COACH" | "FACILITY";
export type ConstraintRuleType = "HARD" | "PREFERRED" | "BONUS" | "LOCK";

export interface Constraint {
  id: string;
  name: string;
  scope: ConstraintScope;
  scopeTargetId: string | null;
  family: ConstraintFamily;
  ruleType: ConstraintRuleType;
  config: Record<string, unknown>;
  isActive: boolean;
}

export interface ConstraintPayload {
  name: string;
  scope: ConstraintScope;
  scopeTargetId?: string | null;
  family: ConstraintFamily;
  ruleType: ConstraintRuleType;
  config: Record<string, unknown>;
  isActive?: boolean;
  /** Attach to a CalendarEntry (period) → dated constraint, excluded from base generation. */
  calendarEntryId?: string;
}

export type TeamTagAxis = "GENRE" | "NIVEAU" | "AGE";

export interface TeamTag {
  id: string;
  name: string;
  color: string | null;
  isSystem: boolean;
  /** Constraint-target grouping axis; null when the tag fits none of the three. */
  axis: TeamTagAxis | null;
}

export const listTeamTags = (): Promise<TeamTag[]> => collectionAll<TeamTag>("team_tags");

/** Explicit team→tag link (season-scoped). A tag with no assignment concerns no
 *  team of the club — it must not appear in the constraint group selector. */
export interface TeamTagAssignment {
  id: string;
  teamId: string;
  tagId: string;
  seasonId: string;
}

export const listTeamTagAssignments = (): Promise<TeamTagAssignment[]> => collectionAll<TeamTagAssignment>("team_tag_assignments");

/** Base plan constraints (permanent=1) or a period's dated constraints (calendarEntryId). */
export const listConstraints = (params?: Record<string, string>): Promise<Constraint[]> => collectionAll<Constraint>("constraints", params);
export const createConstraint = (body: ConstraintPayload): Promise<Constraint> => api.post("constraints", { json: { isActive: true, ...body } }).json();
export const updateConstraint = (id: string, body: ConstraintPayload): Promise<Constraint> => api.put(`constraints/${id}`, { json: body }).json();
export const deleteConstraint = (id: string): Promise<void> => api.delete(`constraints/${id}`).then(() => undefined);

// --- Implicit "well-being" rules, adjustable (P2-28) ---

/**
 * Les 4 règles implicites « bien-être », RÉGLABLES par club/saison (contrat moteur 2.7).
 * L'identifiant EST `ruleKey` (la clé camelCase du contrat), pas un uuid : on règle une règle
 * par son nom. La collection est RÉSOLUE côté serveur — TOUJOURS 4 entrées, défauts inclus —
 * pour que le front n'invente aucune valeur. Le front AFFICHE ce que le GET renvoie et POSTE ce
 * que le gestionnaire choisit ; les bornes de seuil et le couple règle↔seuil restent jugés par
 * le serveur (422). Voir `App\ApiResource\ImplicitRuleSettingResource`.
 */
export type ImplicitRuleKey = "coachRestDay" | "salarieDistribution" | "maxConsecutiveSessions" | "ageAscending" | "maxConsecutiveDays";
/** ⚠ `OFF` n'existe que pour les règles OPT-IN (P2-42) : la règle est proposée mais NON
 *  appliquée tant que le club ne l'active pas. Les règles historiques n'ont que 2 crans. */
export type ImplicitRuleIntensity = "HARD" | "PREFERRED" | "OFF";

export interface ImplicitRuleSetting {
  ruleKey: ImplicitRuleKey;
  intensity: ImplicitRuleIntensity;
  /** Seuil de repos coach — non-null UNIQUEMENT pour coachRestDay (le serveur le résout ainsi). */
  minRestDays: number | null;
  /** Plafond de créneaux consécutifs — non-null UNIQUEMENT pour maxConsecutiveSessions. */
  maxConsecutive: number | null;
  /** Plafond de JOURS consécutifs — non-null UNIQUEMENT pour maxConsecutiveDays (à ne pas
   *  confondre avec `maxConsecutive`, qui compte des CRÉNEAUX dans une journée). */
  maxConsecutiveDays: number | null;
  /** true tant que la règle est au défaut (aucune ligne stockée) : pilote « Réinitialiser ». */
  isDefault: boolean;
}

export interface ImplicitRuleSettingPayload {
  intensity: ImplicitRuleIntensity;
  minRestDays?: number;
  maxConsecutive?: number;
  maxConsecutiveDays?: number;
  /**
   * ADR-0002 inv. 5 — la PORTÉE, lue du CORPS par le PUT : null/absent = saison ; un UUID = la
   * copie d'un plan de période. Le GET la lit en query, le DELETE aussi ; le PUT dans le corps.
   */
  schedulePlanId?: string | null;
}

/** Collection RÉSOLUE (toujours 4, non paginée) — portée saison (null) ou plan de période (uuid). */
export const listImplicitRuleSettings = (schedulePlanId?: string | null): Promise<ImplicitRuleSetting[]> =>
  collection<ImplicitRuleSetting>("implicit_rule_settings", schedulePlanId ? { schedulePlanId } : undefined);
/** Upsert d'un réglage par sa clé (crée la ligne au premier réglage) ; la portée voyage dans le corps. */
export const updateImplicitRuleSetting = (ruleKey: string, body: ImplicitRuleSettingPayload): Promise<ImplicitRuleSetting> =>
  api.put(`implicit_rule_settings/${ruleKey}`, { json: body }).json();
/**
 * Réinitialiser une règle. Portée saison (planId absent) : le serveur supprime la ligne → retour
 * au défaut. Portée période (`?schedulePlanId=`) : le serveur RE-COPIE la valeur de saison courante
 * dans la copie du plan (il ne supprime pas — l'invariant 4 lignes tient).
 */
export const resetImplicitRuleSetting = (ruleKey: string, schedulePlanId?: string | null): Promise<void> =>
  api.delete(`implicit_rule_settings/${ruleKey}`, schedulePlanId ? { searchParams: { schedulePlanId } } : undefined).then(() => undefined);

// --- Recap + generate (W5) ---

export interface ValidateResult {
  valid: boolean;
  errors: Record<string, string[]>;
  conflicts: { constraint1Id: string; constraint2Id: string; reason: string }[];
  /**
   * P2-9 PR B — impossibilités PHYSIQUES déjà formulées par le serveur (un coach à deux
   * endroits en même temps). Elles BLOQUENT la génération, contrairement aux `warnings`
   * (règle #8 : un avertissement n'invalide rien). Optionnel : un serveur antérieur à
   * PR B ne renvoie pas la clé.
   */
  blockers?: string[];
  /**
   * Avertissements formulés par le serveur — « "X" vise le gymnase Y, désactivé pour
   * cette période : elle ne sera pas appliquée ». Ils n'invalident RIEN (règle #8) et
   * arrivent donc **avec `valid: true`** : les lire dans une branche `if (!valid)` ne
   * remonterait jamais rien. Le backend les produit depuis #8 ; rien ne les affichait
   * jusqu'ici, si bien qu'une contrainte écartée disparaissait en silence.
   * Optionnel : un serveur plus ancien ne renvoie pas la clé.
   */
  warnings?: string[];
}

/**
 * Le corps d'un 422 de validation, et lui seul. Sans cette garde, `data as ValidateResult`
 * transtypait N'IMPORTE QUEL objet JSON : un 403 (rôle insuffisant) ou un 500 devenait un
 * résultat de validation aux champs `undefined`, et le récap s'affichait comme si tout
 * allait bien. On ne peut pas distinguer « invalide » de « pas pu valider » sans ça.
 */
function isValidateResult(data: unknown): data is ValidateResult {
  if (null === data || typeof data !== "object") {
    return false;
  }
  const candidate = data as Partial<ValidateResult>;

  return typeof candidate.valid === "boolean" && null !== candidate.errors && typeof candidate.errors === "object" && Array.isArray(candidate.conflicts);
}

/** Pre-solve gate (BW3). Returns the body whether valid (200) or not (422). Period mode scopes to a calendar entry. */
export async function validateConstraints(calendarEntryId?: string): Promise<ValidateResult> {
  try {
    return await api.post("constraints/validate", calendarEntryId ? { json: { calendarEntryId } } : undefined).json<ValidateResult>();
  } catch (error) {
    if (error instanceof HTTPError) {
      // ky 2.x parses the 422 body into error.data — re-reading the response
      // throws "body stream already read".
      const data = (error as { data?: unknown }).data;
      // Garde de forme : seul un VRAI corps de validation est rendu. Tout autre objet
      // (403, 500, page d'erreur JSON) relève le HTTPError — l'appelant saura qu'il n'a
      // pas pu valider, au lieu de croire à un récap valide.
      if (isValidateResult(data)) {
        return data;
      }
    }
    throw error;
  }
}

/** ADR-0002 C4 : crée une version SOUS un plan. Omis → le plan SEASON (socle) ;
 *  fourni → le plan d'une période (overlay).
 *
 *  Aucun `name` n'est envoyé : le nom vit sur le PLAN (inv. 12) et le serveur en dérive
 *  celui de la version. Les clients en inventaient un chacun de leur côté, et celui-ci
 *  ressortait dans la liste des plannings et le nom du fichier exporté. */
export const createSchedule = (schedulePlanId?: string): Promise<{ id: string }> =>
  api.post("schedules", { json: { status: "DRAFT", ...(schedulePlanId ? { schedulePlanId } : {}) } }).json();
export const generateSchedule = (id: string): Promise<unknown> => api.post(`schedules/${id}/generate`).json();

// --- P2-44 : transcription depuis le socle (ADR-0004) ---

/**
 * Le résultat de `POST /schedule_plans/{id}/transcribe-from-socle` : la V1 d'un plan de PÉRIODE
 * VIERGE naît comme une COPIE de la version pointée du socle, filtrée de la sélection de période
 * (ADR-0004). La réponse porte la liste « à replacer » — les séances du socle NON reprises avec
 * leur raison, SERVIE par le backend ; le front ne redérive rien. La clé JSON de l'id est `id`
 * (comme toute création de version), pas `scheduleId`.
 */
export interface PeriodTranscription {
  id: string;
  versionNumber: number;
  copiedCount: number;
  toReplace: ToReplaceEntry[];
}

/** Transcrit la version pointée du socle vers la V1 d'un plan de période vierge. */
export const transcribeFromSocle = (schedulePlanId: string): Promise<PeriodTranscription> =>
  api.post(`schedule_plans/${schedulePlanId}/transcribe-from-socle`).json<PeriodTranscription>();

export type ScheduleStatus = "DRAFT" | "PENDING" | "GENERATING" | "COMPLETED" | "FAILED";
export const getSchedule = (id: string): Promise<{ id: string; status: ScheduleStatus }> => api.get(`schedules/${id}`).json();
