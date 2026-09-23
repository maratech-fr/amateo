import { api } from "@/shared/api/client";
import type { DeviationField, FixtureStatus, HomeAway } from "./fixtures";

/**
 * Le rôle d'UNE personne sur UN côté d'un conflit personne-en-double (« une
 * personne = ses équipes coachées + ses équipes où elle joue ») : `MAIN` /
 * `ASSISTANT` (coach) ou `PLAYER` (joueuse). Miroir de `App\Enum\ConflictPersonRole`.
 */
export type ConflictSideRole = "MAIN" | "ASSISTANT" | "PLAYER";

/** One side of a conflict — the fixture and its computed occupancy window. */
export interface ConflictFixtureView {
  fixtureId: string;
  teamId: string;
  homeAway: HomeAway;
  matchDate: string;
  kickoffTime: string | null;
  /** P1-4 PR C — the window borrows the team's HABITUAL kickoff (away match
   * without a real hour): say « heure estimée ». */
  estimatedKickoff?: boolean;
  /** Le rôle de la personne sur CE côté — servi seulement pour MATCH_MATCH
   * (`left`/`right`). Absent sur la famille gymnase, qui partage
   * cette vue mais ne porte aucune personne. */
  role?: ConflictSideRole;
  windowStart: string;
  windowEnd: string;
  /**
   * Détail par côté — champs ADDITIFS servis par le backend pour rendre une ligne par
   * côté sur TOUTES les familles qui partagent cette vue : l'écran des conflits les lit
   * aussi pour la famille gymnase (VENUE_OVERLAP lit `opponentLabel` et
   * `matchDurationMinutes`, `lib/conflictSideLines.ts::venueSide`), pas seulement pour
   * MATCH_MATCH / MATCH_TRAINING. Optionnels côté TS.
   */
  /** Heure estimée « HH:MM » empruntée à l'habitude — non-null SSI `estimatedKickoff`. */
  estimatedKickoffTime?: string | null;
  /** Trajet ALLER simple (minutes) ; null = non modélisé (« trajet inconnu ») ; toujours null en domicile. */
  travelOneWayMinutes?: number | null;
  /** Durée du match de l'équipe de ce côté (profil catégorie résolu, sinon défaut). */
  matchDurationMinutes?: number;
  /** Le libellé de l'adversaire de cette rencontre (« vs … »). */
  opponentLabel?: string;
  /** Où joue l'adversaire — décoré côté AWAY seulement ; null = lieu inconnu. */
  opponentPlace?: string | null;
}

export interface ConflictTrainingView {
  slotTemplateId: string;
  scheduleId: string;
  teamId: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
  /** Le rôle de la personne avec l'équipe du créneau — MATCH_TRAINING. */
  role?: ConflictSideRole;
  windowStart: string;
  windowEnd: string;
}

/** VENUE_UNAVAILABLE side — no occupancy window: the DATE match suffices. */
export interface ConflictUnavailableFixtureView {
  fixtureId: string;
  teamId: string;
  homeAway: HomeAway;
  matchDate: string;
  kickoffTime: string | null;
  /** Never set on this variant (no window, so nothing to estimate). */
  estimatedKickoff?: boolean;
  status: FixtureStatus;
}

export type ConflictType =
  | "VENUE_OVERLAP"
  | "LEAGUE_WINDOW_VIOLATION"
  | "MATCH_MATCH"
  | "MATCH_TRAINING"
  | "VENUE_UNAVAILABLE"
  | "ACCESS_WINDOW_LOST"
  | "COMPETITION_INCOMPLETE"
  | "AWAY_NO_FOOTPRINT"
  | "FRIENDLY_ON_MATCH_SLOT";

/**
 * P4-207 — l'axe TRAITEMENT d'un conflit (où en est sa RÉSOLUTION), distinct de la
 * gravité : `DEROGATION_REQUESTED` (dérogation demandée à la ligue),
 * `RESOLVED_INTERNALLY` (réglé en interne), `NO_SOLUTION_YET` (sans solution pour
 * l'instant). « À traiter » n'est PAS un statut : c'est l'ABSENCE de résolution
 * (`resolution === null`). Miroir de `App\Enum\ConflictResolutionStatus`.
 */
export type ConflictResolutionStatus =
  | "DEROGATION_REQUESTED"
  | "RESOLVED_INTERNALLY"
  | "NO_SOLUTION_YET"
  | "COACHES_NOT_PLAYING"
  | "PLAYS_NOT_COACHING"
  // Statuts propres à une famille (lot N) : calendrier incomplet → importer les matchs
  // manquants ; collision de gymnase → erreur FBI / match à déplacer. Le backend refuse
  // un statut hors de la table de sa famille (`ConflictResolutionStatus::casesForFamily`).
  | "IMPORT_MISSING_MATCHES"
  | "FBI_ERROR"
  | "MATCH_TO_MOVE";

/**
 * P4-207 — la résolution PERSISTÉE d'un conflit (par empreinte, jamais par id) : où
 * en est son traitement, une note libre facultative, et l'instant du dernier
 * changement (ATOM). `null` sur un conflit = « à traiter ». Le conflit reste listé
 * partout tant qu'il existe ; la résolution ne fait qu'annoter son traitement.
 */
export interface ConflictResolution {
  status: ConflictResolutionStatus;
  note: string | null;
  updatedAt: string;
}

/** LEAGUE_WINDOW_VIOLATION — an allowed kickoff window (envelope) the placement violates. */
export interface LeagueKickoffWindow {
  dayOfWeek: number;
  kickoffMin: string;
  kickoffMax: string;
}

/** ACCESS_WINDOW_LOST — one match-access window of the fixture's venue. */
export interface VenueAccessWindow {
  dayOfWeek: number;
  startTime: string;
  endTime: string;
}

export interface Conflict {
  type: ConflictType;
  /** P1-4 PR E2 — gravity emitted by the SERVER (1 = worst … 7 = info). */
  severity: number;
  /** MATCH_MATCH / MATCH_TRAINING — rôle AGRÉGÉ de la personne : MAIN si tous les
   * côtés sont MAIN, ASSISTANT dès qu'un côté est ASSISTANT, PLAYER sinon. Le rôle
   * PAR CÔTÉ vit sur `left`/`right` (ou `fixture`/`training`). */
  coachRole?: ConflictSideRole;
  coachId?: string;
  /**
   * Overlap segment — coach conflicts only. ISO datetimes carrying the club's
   * WALL-CLOCK time WITHOUT an offset (`2026-10-03T20:45:00`): the UI parses
   * them as local and re-formats them, so the hour shown is the hour the club
   * lives, whatever the viewer's timezone (P4-191).
   */
  start?: string;
  end?: string;
  left?: ConflictFixtureView;
  right?: ConflictFixtureView;
  fixture?: ConflictFixtureView | ConflictUnavailableFixtureView;
  training?: ConflictTrainingView;
  /** VENUE_OVERLAP / VENUE_UNAVAILABLE / ACCESS_WINDOW_LOST. */
  venueId?: string;
  unavailabilityId?: string;
  label?: string | null;
  unavailableFrom?: string;
  unavailableUntil?: string;
  /**
   * LEAGUE_WINDOW_VIOLATION — the allowed kickoff windows the placement violates (`kickoffMin`/`kickoffMax`).
   * ACCESS_WINDOW_LOST — the fixture's venue match-access windows, the match weekday first (`startTime`/`endTime`).
   */
  windows?: LeagueKickoffWindow[] | VenueAccessWindow[];
  /** COMPETITION_INCOMPLETE (severity 6) — paired-competition completeness. */
  competitionId?: string;
  competitionName?: string;
  teamId?: string;
  imported?: number;
  expected?: number;
  /**
   * FRIENDLY_ON_MATCH_SLOT (severity 5, P4-193) — pourquoi l'amical est signalé :
   * `MATCH_SLOT_WINDOW` (empreinte sur une fenêtre d'accès match) et/ou
   * `MATCH_WEEKEND` (week-end où le club joue une rencontre non amicale). EXCLU de
   * l'empreinte d'identité (le litige reste « cet amical sur un créneau »).
   */
  reasons?: string[];
  /**
   * RMM-3 — identité STABLE d'un conflit (`ConflictFingerprinter` côté serveur) :
   * même valeur tant que c'est le même litige, elle change quand sa nature change.
   * Le « gardien » compare ces empreintes d'une visite à l'autre pour marquer
   * « Nouveau » ceux qui viennent d'apparaître. Ornement pur : rien de la sévérité
   * ni des étapes de la boucle n'en dépend.
   */
  fingerprint?: string;
  /**
   * P4-207 — la résolution PERSISTÉE de ce conflit, ou `null` quand il est « à
   * traiter ». Le backend la sert TOUJOURS (jamais absente) : un conflit sans ligne
   * de résolution porte `resolution: null`. Écrivable seulement quand `fingerprint`
   * est présent (l'empreinte EST la clé d'écriture).
   */
  resolution: ConflictResolution | null;
}

export interface ConflictsResponse {
  clubId: string;
  seasonId: string | null;
  conflicts: Conflict[];
  /**
   * Le plan de la saison pointe-t-il une version ? Sinon la saison n'a pas de
   * calendrier : les conflits match↔entraînement ne peuvent pas être détectés hors
   * période, et `conflicts: []` devient indiscernable d'une saison saine. Atteignable
   * quand /api/me est périmé (staleTime 60 s) face à un planning rouvert ailleurs.
   */
  seasonPlanChosen: boolean;
}

/** Same-coach conflict radar, recomputed server-side on every call. */
export const getConflicts = (): Promise<ConflictsResponse> => api.get("fixtures/conflicts").json<ConflictsResponse>();

/**
 * P4-207 — pose (ou REMPLACE) la résolution d'un conflit, adressée par son EMPREINTE
 * (jamais un id). Le PUT est un remplacement plein : `note` absente ⇒ note vidée côté
 * serveur — l'appelant qui ne veut CHANGER que le statut resservit donc la note
 * existante. Management-gated (403 membre) ; 422 statut inconnu / note > 500 /
 * empreinte disparue du flux / statut hors table de famille / complément d'erreur FBI
 * invalide. Le complément `fbiCorrection` (FBI_ERROR seulement) ouvre l'entrée « à
 * corriger dans FBI » du côté fautif ; ignoré pour les autres statuts. Rend l'état à
 * jour `{fingerprint, resolution}`.
 */
export const putConflictResolution = (
  fingerprint: string,
  input: { status: ConflictResolutionStatus; note?: string; fbiCorrection?: { fixtureId: string; field: DeviationField } },
): Promise<{ fingerprint: string; resolution: ConflictResolution }> =>
  api.put(`fixtures/conflicts/${fingerprint}/resolution`, { json: input }).json<{ fingerprint: string; resolution: ConflictResolution }>();

/**
 * P4-207 — remet un conflit « à traiter » (efface sa résolution). 204 idempotent —
 * même sans ligne existante. Management-gated.
 */
export const deleteConflictResolution = (fingerprint: string): Promise<void> =>
  api.delete(`fixtures/conflicts/${fingerprint}/resolution`).then(() => undefined);
