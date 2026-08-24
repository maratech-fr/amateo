import { api } from "@/shared/api/client";
import { collection, collectionAll } from "@/shared/api/collection";
import { sortByName } from "@/shared/lib/nameOrder";

/**
 * Matches read/write API (module matchs, palier A PR-3). Tenant (club) + active
 * season are resolved server-side from the JWT — no header is sent. Consumes only
 * the endpoints delivered by PR-1/PR-2; this PR adds no backend.
 */

export type HomeAway = "HOME" | "AWAY";
export type FixtureStatus = "UNPLACED" | "PLACED" | "SUBMITTED" | "VALIDATED";

export interface Fixture {
  id: string;
  teamId: string;
  seasonId: string;
  competitionId: string | null;
  /** Y-m-d */
  matchDate: string;
  homeAway: HomeAway;
  opponentLabel: string;
  status: FixtureStatus;
  venueId: string | null;
  /** HH:MM, null until placed/estimated */
  kickoffTime: string | null;
  /** FBI match number (import idempotence key) — null for manual entries. */
  externalRef: string | null;
  /** Raw FBI « Salle » label, HOME and AWAY — never a Venue reference. */
  fbiVenueLabel: string | null;
  /** MANUAL | SOLVER | null — who placed it (re-solve anchor marker, PR D). */
  placementSource: "MANUAL" | "SOLVER" | null;
}

export interface Competition {
  id: string;
  teamId: string;
  name: string;
  competitionType: string;
  /** FBI club-team label — set when two club teams share one division. */
  fbiTeamLabel?: string | null;
  /** FFBB pairing refs (P1-4 PR F) — read-only, written by the pairing confirm. */
  ffbbCompetitionId?: string | null;
  ffbbPouleId?: string | null;
  ffbbPouleName?: string | null;
  ffbbCompetitionName?: string | null;
  expectedMatchdays?: number | null;
}

// ── FFBB pairing (P1-4 PR F) ─────────────────────────────────────────────────

export interface FfbbEngagement {
  ffbbCompetitionId: string;
  ffbbCompetitionCode: string;
  competitionName: string;
  ffbbPouleId: string;
  pouleName: string;
  category: string | null;
  level: string | null;
  gender: string | null;
  pouleSize: number;
  pouleOpponents: string[];
  /** Pre-fill: the team already paired to this competition (or its next phase). */
  suggestedTeamId: string | null;
  suggestedCompetitionId: string | null;
}

export const getFfbbEngagements = (): Promise<{ engagements: FfbbEngagement[] }> =>
  api.get("ffbb/engagements").json<{ engagements: FfbbEngagement[] }>();

export const confirmFfbbPairings = (pairings: { ffbbCompetitionId: string; teamId: string }[]): Promise<void> =>
  api
    .post("ffbb/engagements/confirm", { json: { pairings } })
    .then(() => undefined);

export interface LeagueWindow {
  id: string;
  league: string;
  category: string;
  level: string;
  gender: string | null;
  /** ISO 1..7 */
  dayOfWeek: number;
  /** HH:MM */
  kickoffMin: string;
  kickoffMax: string;
}

export interface LeagueWindowsResponse {
  league: string;
  items: LeagueWindow[];
  /** P1-4 PR E2 (dette iv) — teamId → applicable window ids, resolved by the
   * SERVER with the same join as the solver ([] = unmapped → advisory only). */
  resolvedTeamWindows: Record<string, string[]>;
}

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
  windowStart: string;
  windowEnd: string;
}

export interface ConflictTrainingView {
  slotTemplateId: string;
  scheduleId: string;
  teamId: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
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
  | "TEAM_LINK_OVERLAP"
  | "COMPETITION_INCOMPLETE"
  | "AWAY_NO_FOOTPRINT";

export interface Conflict {
  type: ConflictType;
  /** P1-4 PR E2 — gravity emitted by the SERVER (1 = worst … 7 = info). */
  severity: number;
  /** MATCH_MATCH / MATCH_TRAINING — MAIN or ASSISTANT (worst engagement wins). */
  coachRole?: "MAIN" | "ASSISTANT";
  coachId?: string;
  /** TEAM_LINK_OVERLAP only. */
  teamLinkId?: string;
  /** Overlap segment (ISO datetimes) — coach conflicts only. */
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
  /** LEAGUE_WINDOW_VIOLATION — the windows the placement violates. */
  windows?: { dayOfWeek: number; kickoffMin: string; kickoffMax: string }[];
  /** COMPETITION_INCOMPLETE (severity 6) — paired-competition completeness. */
  competitionId?: string;
  competitionName?: string;
  teamId?: string;
  imported?: number;
  expected?: number;
  /**
   * RMM-3 — identité STABLE d'un conflit (`ConflictFingerprinter` côté serveur) :
   * même valeur tant que c'est le même litige, elle change quand sa nature change.
   * Le « gardien » compare ces empreintes d'une visite à l'autre pour marquer
   * « Nouveau » ceux qui viennent d'apparaître. Ornement pur : rien de la sévérité
   * ni des étapes de la boucle n'en dépend.
   */
  fingerprint?: string;
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

/** Team reference row — carries the axes the league envelope maps on. */
export interface Team {
  id: string;
  name: string;
  sportCategoryId: string;
  level: string | null;
  gender: string | null;
  // Priority tier (S/A/B/C/D) — used to group teams in selectors, same
  // découpage as the wizard's teams step.
  priorityTierId: number;
  tierOrder: number;
}

export interface PriorityTier {
  id: number;
  label: string;
  name: string;
  color: string | null;
}

export interface Venue {
  id: string;
  name: string;
  color: string | null;
}

export interface Category {
  id: string;
  name: string;
}

export interface Coach {
  id: string;
  firstName: string;
  lastName: string;
}

export interface CreateFixtureInput {
  teamId: string;
  matchDate: string;
  homeAway: HomeAway;
  opponentLabel: string;
  competitionId?: string | null;
}

/** The placement of a home fixture: venue + kickoff, status → PLACED. */
export interface PlaceFixtureInput {
  venueId: string;
  kickoffTime: string;
}

/** The API omits null props from JSON → coerce the optionals back to null so
 * `null !==` guards and the grid/envelope logic never see `undefined`. */
function normalizeFixture(raw: Fixture): Fixture {
  return {
    ...raw,
    competitionId: raw.competitionId ?? null,
    venueId: raw.venueId ?? null,
    kickoffTime: raw.kickoffTime ?? null,
    externalRef: raw.externalRef ?? null,
    fbiVenueLabel: raw.fbiVenueLabel ?? null,
    placementSource: raw.placementSource ?? null,
  };
}

export const getFixtures = async (): Promise<Fixture[]> => (await collectionAll<Fixture>("fixtures")).map(normalizeFixture);
export const getCompetitions = (): Promise<Competition[]> => collectionAll<Competition>("competitions");
export const getTeams = (): Promise<Team[]> => collectionAll<Team>("teams");
// Tiers are a tiny fixed set (S/A/B/C/D) and their id is numeric, so use the
// unpaginated `collection` (collectionAll constrains T to a string id).
export const getPriorityTiers = (): Promise<PriorityTier[]> => collection<PriorityTier>("priority_tiers");
export const getVenues = async (): Promise<Venue[]> => sortByName(await collectionAll<Venue>("venues"));
export const getCategories = (): Promise<Category[]> => collectionAll<Category>("sport_categories");
export const getCoaches = (): Promise<Coach[]> => collectionAll<Coach>("coaches");

/** The league match-kickoff windows inherited by the club (envelope, AURA default). */
export const getLeagueWindows = (): Promise<LeagueWindowsResponse> =>
  api.get("league-match-windows").json<LeagueWindowsResponse>();

/** Same-coach conflict radar, recomputed server-side on every call. */
export const getConflicts = (): Promise<ConflictsResponse> => api.get("fixtures/conflicts").json<ConflictsResponse>();

/**
 * RMM-3 — le « gardien » à l'ouverture du module. Ce que le POST rapporte : ce qui
 * a CHANGÉ depuis la précédente visite de CET utilisateur (matchs arrivés, conflits
 * neufs par empreinte, planning de saison qui a bougé). Le serveur stampe la visite
 * comme effet de bord (première visite = silencieuse, delta vide ; grâce glissante
 * de 30 min → un F5 rejoue le même delta sans le rotationner). Voir
 * `MatchModuleVisitController`.
 */
export interface ModuleVisitDelta {
  /** Première visite : la référence est figée en silence, tous les comptes à zéro. */
  firstVisit: boolean;
  /** Fixtures créés depuis la prise de référence. */
  newFixturesCount: number;
  /** Empreintes des conflits présents maintenant et absents de la référence. */
  newConflictFingerprints: string[];
  /** La version de saison pointée (ou la dernière COMPLETED) a changé. */
  planningChanged: boolean;
  /** L'instant contre lequel les badges sont mesurés (ISO). */
  referenceTakenAt: string;
}

/** Stampe la visite et rend le delta depuis la précédente (POST sans corps). */
export const postModuleVisit = (): Promise<ModuleVisitDelta> => api.post("matches/module-visit").json<ModuleVisitDelta>();

// ── Capacity layer (P1-4 PR B) ───────────────────────────────────────────────

/** Match access window of a venue — ≥ 1 window makes it a MATCH venue. */
export interface VenueMatchWindow {
  id: string;
  venueId: string;
  /** ISO 1..7 */
  dayOfWeek: number;
  /** HH:MM */
  startTime: string;
  /** HH:MM, same-day, exclusive. */
  endTime: string;
}

/** All-circumstances venue closure — alerts, never blocks. */
export interface VenueUnavailability {
  id: string;
  venueId: string;
  /** Y-m-d, inclusive. */
  startDate: string;
  endDate: string;
  label: string | null;
}

export interface UnavailabilityImpactItem {
  unavailabilityId: string;
  venueId: string;
  startDate: string;
  endDate: string;
  label: string | null;
  affectedFixtures: { fixtureId: string; teamId: string; matchDate: string; kickoffTime: string | null; status: FixtureStatus }[];
  /** Dated training sessions inside the range. */
  trainingOccurrences: number;
  /** Distinct weekly slots affected. */
  trainingSlotCount: number;
}

export interface UnavailabilityImpactResponse {
  clubId: string;
  seasonId: string | null;
  items: UnavailabilityImpactItem[];
}

export const getVenueMatchWindows = (): Promise<VenueMatchWindow[]> => collectionAll<VenueMatchWindow>("venue_match_windows");

export const createVenueMatchWindow = (input: { venueId: string; dayOfWeek: number; startTime: string; endTime: string }): Promise<VenueMatchWindow> =>
  api.post("venue_match_windows", { json: input }).json<VenueMatchWindow>();

export const deleteVenueMatchWindow = (id: string): Promise<void> => api.delete(`venue_match_windows/${id}`).then(() => undefined);

export const getVenueUnavailabilities = (): Promise<VenueUnavailability[]> =>
  (async () => (await collectionAll<VenueUnavailability>("venue_unavailabilities")).map((u) => ({ ...u, label: u.label ?? null })))();

export const createVenueUnavailability = (input: { venueId: string; startDate: string; endDate: string; label?: string }): Promise<VenueUnavailability> =>
  api.post("venue_unavailabilities", { json: input }).json<VenueUnavailability>();

export const deleteVenueUnavailability = (id: string): Promise<void> => api.delete(`venue_unavailabilities/${id}`).then(() => undefined);

/** Alert-only impact feed (cockpit card): what each unavailability affects. */
export const getUnavailabilityImpact = (): Promise<UnavailabilityImpactResponse> =>
  api.get("venue-unavailability-impact").json<UnavailabilityImpactResponse>();

// ── Preferences layer (P1-4 PR C) ────────────────────────────────────────────

/** A team's habitual match window — one per weekday, venue optional. */
export interface TeamMatchHabit {
  id: string;
  teamId: string;
  /** ISO 1..7 */
  dayOfWeek: number;
  /** HH:MM — an instant, not a range. */
  kickoffTime: string;
  venueId: string | null;
}

export type TeamLinkType = "NOT_SIMULTANEOUS" | "BACK_TO_BACK";

/**
 * Intensité d'une passerelle CÔTÉ ENTRAÎNEMENT (lot PASSERELLES, arbitrage fondateur n°1).
 * Miroir de `App\Enum\TeamLinkIntensity`. Ne gouverne QUE le solveur d'entraînement — le rail
 * matchs garde sa pénalité SOFT historique (`linkType`), insensible à ce réglage.
 * `PREFERRED` (défaut) : le solveur préfère éviter le chevauchement des séances. `MANDATORY` :
 * il l'interdit (contrainte dure — peut rendre le planning infaisable si trop contraint).
 */
export type TeamLinkIntensity = "PREFERRED" | "MANDATORY";

/** A declared team bridge — symmetric (teamAId < teamBId), one per couple. */
export interface TeamLink {
  id: string;
  teamAId: string;
  teamBId: string;
  linkType: TeamLinkType;
  /** Training-only intensity (PREFERRED default). Never governs matches. */
  trainingIntensity: TeamLinkIntensity;
}

export const getTeamMatchHabits = (): Promise<TeamMatchHabit[]> =>
  (async () => (await collectionAll<TeamMatchHabit>("team_match_habits")).map((h) => ({ ...h, venueId: h.venueId ?? null })))();

export const createTeamMatchHabit = (input: { teamId: string; dayOfWeek: number; kickoffTime: string; venueId?: string }): Promise<TeamMatchHabit> =>
  api.post("team_match_habits", { json: input }).json<TeamMatchHabit>();

export const deleteTeamMatchHabit = (id: string): Promise<void> => api.delete(`team_match_habits/${id}`).then(() => undefined);

export const getTeamLinks = (): Promise<TeamLink[]> => collectionAll<TeamLink>("team_links");

export const createTeamLink = (input: { teamAId: string; teamBId: string; linkType: TeamLinkType; trainingIntensity?: TeamLinkIntensity }): Promise<TeamLink> =>
  api.post("team_links", { json: input }).json<TeamLink>();

/**
 * Edit an existing bridge — PUT is a full replace in this API, so the identity
 * (teams + matches linkType) is echoed and only the changed axis moves. Today the
 * one editable axis is the training intensity (matches linkType stays as declared).
 */
export const updateTeamLink = (link: TeamLink, input: { linkType?: TeamLinkType; trainingIntensity?: TeamLinkIntensity }): Promise<TeamLink> =>
  api
    .put(`team_links/${link.id}`, {
      json: {
        teamAId: link.teamAId,
        teamBId: link.teamBId,
        linkType: input.linkType ?? link.linkType,
        trainingIntensity: input.trainingIntensity ?? link.trainingIntensity,
      },
    })
    .json<TeamLink>();

export const deleteTeamLink = (id: string): Promise<void> => api.delete(`team_links/${id}`).then(() => undefined);

// ── Auto-placement (P1-4 PR D) ───────────────────────────────────────────────

export type UnplacedReason = "no_access_window" | "no_league_intersection" | "venue_unavailable" | "venue_full";

export interface PlaceMatchesResult {
  placed: number;
  /** Placements refused at write time — a manual gesture won during the solve. */
  skipped: number;
  unplaced: { matchId: string; reason: UnplacedReason; message: string }[];
  diagnostics: { type: string; severity: string; message: string }[];
}

/** Synchronous solve: the engine places every placeable home match (seconds).
 * A non-placeable match is NOT an error — it comes back named in `unplaced`. */
export const placeMatches = (): Promise<PlaceMatchesResult> => api.post("fixtures/place").json<PlaceMatchesResult>();

/** One division group of the analyzed file, resolved (or not) against the
 * persisted Division↔team mapping. `fbiTeamLabel` is only set when TWO club
 * teams share the division (the FBI suffix disambiguates them). */
export interface ImportAnalysisDivision {
  name: string;
  fbiTeamLabel: string | null;
  rowCount: number;
  teamId: string | null;
  competitionId: string | null;
  /** P1-4 PR F2 (6.3) — unmapped division whose label matches a paired
   * competition's canonical FFBB name: a suggestion, never a resolution. */
  suggestedTeamId: string | null;
  suggestedCompetitionId: string | null;
  /** P1-4 PR F2 (6.1) — blocking poule mismatch: the division will be SKIPPED. */
  pouleError: string | null;
  /** Non-blocking mismatch (≤ 50 % unknown opponents). */
  pouleUnknownOpponents: string[];
}

/** RMM-4 — the reconciliation perimeter: the three home fields that become a
 * CHOICE when the file diverges from an already-placed match. */
export type DeviationField = "date" | "kickoff" | "venue";

/** Per-field écart: the app value VS the file value (either side may be null —
 * e.g. an app kickoff not yet posed). */
export interface DeviationFieldValues {
  app: string | null;
  file: string | null;
}

/**
 * RMM-4 — one home match already placed whose file (FBI) values differ from the
 * app: an « état app VS état fichier » card the manager decides per field. Shape
 * mirrored from `FbiFixtureImporter::groupDeviations` (analyze + import report).
 * `persisting: true` = the écart was already pending on the previous deposit —
 * the promised FBI correction was NOT made.
 */
export interface Deviation {
  fixtureId: string;
  externalRef: string;
  division: string;
  teamId: string;
  status: FixtureStatus;
  persisting: boolean;
  /** Only the divergent fields are present. */
  fields: Partial<Record<DeviationField, DeviationFieldValues>>;
}

/** The manager's verdict on ONE écart field (RMM-4). `keep_app` writes nothing
 * (a trace is kept); `take_file` adopts the file value (date/venue un-place the
 * match, kickoff keeps the slot). */
export interface DeviationDecision {
  fixtureId: string;
  field: DeviationField;
  choice: "keep_app" | "take_file";
}

export interface ImportFbiAnalysis {
  divisions: ImportAnalysisDivision[];
  totalRows: number;
  exempted: number;
  errors: string[];
  /** RMM-4 — home matches already placed whose date/heure/salle differ from the
   * file, each to be decided per écart before the import writes anything. */
  deviations: Deviation[];
}

export interface ImportFbiWarning {
  type: "RESCHEDULED" | "SWITCHED" | "POULE_MISMATCH";
  division: string;
  externalRef: string;
  message: string;
}

export interface ImportFbiResult {
  message: string;
  created: number;
  updated: number;
  unchanged: number;
  exempted: number;
  errors: string[];
  warnings: ImportFbiWarning[];
  unmappedDivisions: { name: string; fbiTeamLabel: string | null; rowCount: number }[];
  /** P1-4 PR F2 (6.2) — paired competitions still short of their expectation. */
  completeness: { competitionId: string; name: string; imported: number; expected: number }[];
  /** RMM-4 — perimeter écarts that had NO decision: left INTACT, never
   * overwritten. Reported so the manager can re-deposit to re-present them. */
  unresolvedDeviations: Deviation[];
  /** RMM-4 — this deposit is dated (freshness feed). */
  depositedAt: string;
}

/** A manager's mapping choice for one division group of the analyze step. */
export interface FbiMapping {
  division: string;
  fbiTeamLabel: string | null;
  teamId: string;
  /** Rides along when the FFBB suggestion is accepted untouched: the pairing
   * (refs, expectation, poule) is REUSED server-side, never duplicated. */
  competitionId?: string | null;
}

/** Dry-run: parse the club-wide FBI export and return its mapping table. */
export const analyzeFbiFixtures = (file: File): Promise<ImportFbiAnalysis> => {
  const form = new FormData();
  form.append("file", file);
  return api.post("fixtures/import/analyze", { body: form }).json<ImportFbiAnalysis>();
};

/** One-pass import: the SAME file + the completed mappings + the per-écart
 * decisions (RMM-4, all multipart). An empty `decisions` means every perimeter
 * écart stays unresolved and is reported — nothing is overwritten by default. */
export const importFbiFixtures = (file: File, mappings: FbiMapping[], decisions: DeviationDecision[] = []): Promise<ImportFbiResult> => {
  const form = new FormData();
  form.append("file", file);
  if (mappings.length > 0) {
    form.append("mappings", JSON.stringify(mappings));
  }
  if (decisions.length > 0) {
    form.append("decisions", JSON.stringify(decisions));
  }
  return api.post("fixtures/import", { body: form }).json<ImportFbiResult>();
};

/** RMM-4 — the freshness feed: the club/season's last FBI deposit (or null when
 * none yet). Open to any member (no management gate). */
export interface FbiIngestionLatest {
  depositedAt: string;
  source: "FBI_XLSX" | "FFBB_API";
  created: number;
  updated: number;
  unchanged: number;
  deviationsCount: number;
}

export const getLatestFbiIngestion = (): Promise<{ latest: FbiIngestionLatest | null }> =>
  api.get("fbi-ingestions/latest").json<{ latest: FbiIngestionLatest | null }>();

export const createFixture = (input: CreateFixtureInput): Promise<Fixture> =>
  api.post("fixtures", { json: { competitionId: null, ...input } }).json<Fixture>();

/**
 * Place a home fixture. PUT is a full replace in this API, so the whole fixture
 * body is resent — only venue/kickoff/status change; identity + opponent +
 * competition are echoed so they are not wiped.
 */
export const placeFixture = (fixture: Fixture, input: PlaceFixtureInput): Promise<Fixture> =>
  api
    .put(`fixtures/${fixture.id}`, {
      json: {
        teamId: fixture.teamId,
        matchDate: fixture.matchDate,
        homeAway: fixture.homeAway,
        opponentLabel: fixture.opponentLabel,
        competitionId: fixture.competitionId,
        venueId: input.venueId,
        kickoffTime: input.kickoffTime,
        status: "PLACED",
      },
    })
    .json<Fixture>();

// ── Manual loop (P1-4 PR E1) ─────────────────────────────────────────────────

/** Full-replace echo body — PUT wipes whatever is not resent. */
const fixtureEchoBody = (fixture: Fixture): Record<string, unknown> => ({
  teamId: fixture.teamId,
  matchDate: fixture.matchDate,
  homeAway: fixture.homeAway,
  opponentLabel: fixture.opponentLabel,
  competitionId: fixture.competitionId,
  venueId: fixture.venueId ?? "",
  kickoffTime: fixture.kickoffTime ?? "",
  status: fixture.status,
});

export interface EditFixtureInput {
  matchDate: string;
  homeAway: HomeAway;
  opponentLabel: string;
  competitionId: string | null;
}

/** Edit body rule (pure, unit-tested): a manual date change KEEPS the placement
 * (the manager IS the decision — unlike an FBI re-import, which un-places); the
 * diagnostic flags any problem the new date creates. Switching HOME → AWAY is
 * the one exception: our venue makes no sense for an away game, the slot is
 * freed (same rule as the FBI re-import switch). */
export const editFixtureBody = (fixture: Fixture, input: EditFixtureInput): Record<string, unknown> => {
  const switchedAway = "AWAY" === input.homeAway && "HOME" === fixture.homeAway;
  const unplace = switchedAway ? { status: "UNPLACED", venueId: "", kickoffTime: "" } : {};
  return { ...fixtureEchoBody(fixture), ...input, ...unplace };
};

export const updateFixture = (fixture: Fixture, input: EditFixtureInput): Promise<Fixture> =>
  api.put(`fixtures/${fixture.id}`, { json: editFixtureBody(fixture, input) }).json<Fixture>();

export const deleteFixture = (id: string): Promise<void> => api.delete(`fixtures/${id}`).then(() => undefined);

/** Back to the "à placer" list: placement cleared, placementSource cleared server-side. */
export const unplaceFixture = (fixture: Fixture): Promise<Fixture> =>
  api
    .put(`fixtures/${fixture.id}`, { json: { ...fixtureEchoBody(fixture), status: "UNPLACED", venueId: "", kickoffTime: "" } })
    .json<Fixture>();

/**
 * Ferme la boucle hebdo : le match placé est SAISI DANS FBI (`status: SUBMITTED`).
 * Full-replace comme tous les PUT — on ÉCHO tout le fixture, seul `status` bouge.
 * ⚠ Effet de bord ASSUMÉ (décision fondateur) : côté serveur, toute écriture de
 * statut ≠ UNPLACED tamponne `placementSource = MANUAL`. Marquer « saisi » ANCRE
 * donc le match — c'est voulu : un match déclaré à la fédération ne doit plus
 * bouger au solve.
 */
export const submitFixture = (fixture: Fixture): Promise<Fixture> =>
  api.put(`fixtures/${fixture.id}`, { json: { ...fixtureEchoBody(fixture), status: "SUBMITTED" } }).json<Fixture>();

/**
 * Sortie de SUBMITTED : « Corriger » repasse le match en PLACED (chemin de
 * réparation — gymnase mort, erreur de saisie FBI). Full-replace, seul `status`
 * change. Le match reste MANUAL après ce retour ; « Rendre au système » demeure
 * ensuite possible sur un placement intact.
 */
export const reopenFixture = (fixture: Fixture): Promise<Fixture> =>
  api.put(`fixtures/${fixture.id}`, { json: { ...fixtureEchoBody(fixture), status: "PLACED" } }).json<Fixture>();

/** Move a placed fixture (venue and/or kickoff) — stays a MANUAL anchor. */
export const moveFixture = (fixture: Fixture, input: PlaceFixtureInput): Promise<Fixture> =>
  api
    .put(`fixtures/${fixture.id}`, { json: { ...fixtureEchoBody(fixture), venueId: input.venueId, kickoffTime: input.kickoffTime, status: "PLACED" } })
    .json<Fixture>();

/** Padlock: an echo PUT stamps MANUAL server-side — the solver never moves it again. */
export const lockFixture = (fixture: Fixture): Promise<Fixture> =>
  api.put(`fixtures/${fixture.id}`, { json: fixtureEchoBody(fixture) }).json<Fixture>();

/** Hand back to the solver — accepted by the server ONLY on an untouched placement (422 otherwise). */
export const unlockFixture = (fixture: Fixture): Promise<Fixture> =>
  api.put(`fixtures/${fixture.id}`, { json: { ...fixtureEchoBody(fixture), placementSource: "SOLVER" } }).json<Fixture>();

/**
 * Swap the placements (venue + kickoff, NEVER the dates — the league owns them)
 * of two placed fixtures. Two sequential PUTs, no server transaction: nothing is
 * blocking, so a network failure mid-swap leaves a visible, recoverable state.
 */
export const swapFixtures = async (a: Fixture, b: Fixture): Promise<void> => {
  await moveFixture(a, { venueId: b.venueId ?? "", kickoffTime: b.kickoffTime ?? "" });
  await moveFixture(b, { venueId: a.venueId ?? "", kickoffTime: a.kickoffTime ?? "" });
};
