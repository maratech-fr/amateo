import { api } from "@/shared/api/client";
import { collection, collectionAll } from "@/shared/api/collection";
import { sortByName } from "@/shared/lib/nameOrder";
import type { Gender, TeamLevel } from "@/shared/lib/teamIdentity";

/**
 * Matches read/write API (module matchs, palier A PR-3). Tenant (club) + active
 * season are resolved server-side from the JWT — no header is sent. Consumes only
 * the endpoints delivered by PR-1/PR-2; this PR adds no backend.
 */

export type HomeAway = "HOME" | "AWAY";
export type FixtureStatus = "UNPLACED" | "PLACED" | "SUBMITTED" | "VALIDATED";

/**
 * PR-3a — l'axe TRAITEMENT d'une rencontre (a-t-elle été EXAMINÉE par le
 * gestionnaire), distinct de `status` (le placement). NEW = jamais examinée,
 * OUT_OF_SYNC = déphasée (un écart pendant subsiste), REVIEWED = traitée.
 */
export type FixtureReviewState = "NEW" | "OUT_OF_SYNC" | "REVIEWED";

/**
 * PR-3a — un écart encore ouvert sur une rencontre, un par champ du périmètre
 * (`date`/`kickoff`/`venue`). `channel` = la source qui l'a fait apparaître ;
 * `autoApplied` = une valeur imposée hors périmètre pendant que le match était
 * traité (il retombe OUT_OF_SYNC, la valeur app a déjà été déplacée).
 */
export interface PendingDeviation {
  field: DeviationField;
  appValue: string | null;
  sourceValue: string | null;
  channel: "FBI_XLSX" | "FFBB_API";
  seenAt: string;
  autoApplied: boolean;
}

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
  /**
   * P2-52 — persistent reason it went back to « à placer »: `venue_lost` (its venue is no
   * longer affiliated to the club), else null. Distinct from the volatile auto-placement reason.
   */
  unplacedReason: "venue_lost" | null;
  /** PR-3a — l'état de TRAITEMENT (a-t-elle été examinée), distinct de `status`. */
  reviewState: FixtureReviewState;
  /** PR-3a — dernier traitement (ISO), null = jamais examinée. */
  reviewedAt: string | null;
  /** PR-3a — les écarts encore ouverts, un par champ ; [] quand en phase. */
  pendingDeviations: PendingDeviation[];
  /** PR-3a — la rencontre FFBB appariée (canal API), null sinon. */
  ffbbRencontreId: string | null;
  /**
   * P4-187b — proposition FLOUE de gymnase (lecture seule) : servie pour un HOME
   * SANS `venueId` portant un `fbiVenueLabel` dont le nom/alias matche un gymnase
   * actif du club. Ambiguïté (≥ 2 candidats) → null ; jamais un placement, juste un
   * pré-remplissage suggéré dans le geste « Rattacher ». Le front l'AFFICHE.
   */
  suggestedVenueId: string | null;
  /**
   * P2-54 « adversaire multi-gymnases » — le code FFBB de l'organisme adverse, stampé
   * best-effort par le serveur sur les AWAY, null quand l'adversaire n'a pas pu être
   * résolu. Read-only. Clé de jointure vers le trajet adverse (avec `opponentTeamKey`).
   */
  opponentOrganismeCode: string | null;
  /**
   * P2-54 « adversaire multi-gymnases » — le libellé adverse NORMALISÉ côté serveur : le
   * grain d'une surcharge de trajet PAR ÉQUIPE (une même organisation joue parfois dans
   * plusieurs gymnases selon son équipe). Null quand le libellé est vide. Read-only. Le
   * front joint le trajet par `(opponentOrganismeCode, opponentTeamKey)` sans re-dériver.
   */
  opponentTeamKey: string | null;
  /**
   * Mémo « FBI affiche encore … » d'un domicile rétrogradé « à saisir » par une heure
   * prise du fichier : `{field, value, at}` ou null. Sert la mention de la ligne « à
   * saisir » de la liste « FBI — à faire ». Servi, jamais recalculé côté front.
   * Optionnel côté TS (nullable, pas `required` côté OpenAPI) ; `normalizeFixture` le
   * matérialise TOUJOURS à `null` quand la source ne l'envoie pas.
   */
  fbiEcho?: FbiEcho | null;
  /**
   * Le trajet d'une rencontre EXTÉRIEURE, DÉRIVÉ de la rencontre (jamais du club), calculé
   * SERVEUR : le lieu de l'adversaire + l'aller simple depuis le siège. null pour un domicile
   * ou si rien de connu. Optionnel côté TS (nullable, pas `required` côté OpenAPI, patron
   * `fbiEcho`) ; `normalizeFixture` le matérialise TOUJOURS à null quand absent.
   */
  awayTravel?: AwayTravel | null;
}

/** Mémo `Fixture.fbiEcho` — ce que FBI affiche encore pour un champ, sur un domicile « à saisir ». */
export interface FbiEcho {
  field: string;
  value: string;
  /** ISO. */
  at: string;
}

/**
 * `Fixture.awayTravel` — le trajet d'une rencontre extérieure, tout SERVEUR (le front n'en
 * dérive RIEN, il affiche). `basis` porte la CAUSE : `linked` (salle appariée, exact),
 * `most_frequent` (repli gymnase le plus fréquent → « gymnase supposé »), `city` (coordonnées
 * de ville → « ville seule »). `approximated` = trajet approché (repli). `oneWayMinutes` = aller
 * simple, null si pas encore calculé (le lieu peut être connu sans les minutes).
 */
export interface AwayTravel {
  venueLabel: string | null;
  city: string | null;
  precision: OpponentLocationPrecision | null;
  oneWayMinutes: number | null;
  approximated: boolean;
  basis: "linked" | "most_frequent" | "city";
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
  /**
   * RMM-6 — entry-deadline projection, read-only (written by the bulk endpoint
   * below, never the CRUD). `entryDeadline` is the club's OWN value; the backend
   * serves the rule « club wins, else community default » in `effectiveEntryDeadline`
   * and names its origin in `deadlineSource`. The front NEVER recomputes the rule.
   */
  entryDeadline?: string | null;
  effectiveEntryDeadline?: string | null;
  deadlineSource?: "club" | "community" | null;
}

// ── FFBB pairing (P1-4 PR F) ─────────────────────────────────────────────────

export interface FfbbEngagement {
  ffbbCompetitionId: string;
  ffbbCompetitionCode: string;
  competitionName: string;
  ffbbPouleId: string;
  pouleName: string;
  category: string | null;
  /**
   * ⚠ Le libellé FFBB BRUT (« Régional », « Pré régionale »), PAS l'enum `TeamLevel`.
   * C'est ce que l'API fédérale sert, affiché tel quel dans le sous-titre du dialogue ;
   * la correspondance vers `TeamLevel` se fait à l'appariement, pas ici (P4-148 : ce champ
   * avait été resserré à tort, la CI l'a attrapé sur « Régional »).
   */
  level: string | null;
  gender: string | null;
  pouleSize: number;
  pouleOpponents: string[];
  /**
   * D'où vient le pré-remplissage (C1) : `pairing` = appariement déjà confirmé et reconduit à
   * la phase suivante ; `canonical` = correspondance de référence FFBB ; `fbi` = déduit d'un
   * import FBI (une HYPOTHÈSE, signalée par une chip). `null` = aucune suggestion.
   */
  suggestionSource: "pairing" | "canonical" | "fbi" | null;
  /** Pre-fill: the team already paired to this competition (or its next phase). */
  suggestedTeamId: string | null;
  suggestedCompetitionId: string | null;
}

export const getFfbbEngagements = (): Promise<{ engagements: FfbbEngagement[] }> =>
  api.get("ffbb/engagements").json<{ engagements: FfbbEngagement[] }>();

export const confirmFfbbPairings = (pairings: { ffbbCompetitionId: string; teamId: string; competitionId?: string }[]): Promise<void> =>
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
   * (`left`/`right`). Absent sur les familles gymnase/passerelle, qui partagent
   * cette vue mais ne portent aucune personne. */
  role?: ConflictSideRole;
  windowStart: string;
  windowEnd: string;
  /**
   * Détail par côté (MATCH_MATCH / MATCH_TRAINING) — champs ADDITIFS servis par le
   * backend pour rendre une ligne par côté. Optionnels : les familles gymnase/passerelle
   * partagent cette vue mais le front ne les lit pas pour elles.
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
  | "TEAM_LINK_OVERLAP"
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
export type ConflictResolutionStatus = "DEROGATION_REQUESTED" | "RESOLVED_INTERNALLY" | "NO_SOLUTION_YET" | "COACHES_NOT_PLAYING" | "PLAYS_NOT_COACHING";

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
  /** TEAM_LINK_OVERLAP only. */
  teamLinkId?: string;
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

/** Team reference row — carries the axes the league envelope maps on. */
export interface Team {
  id: string;
  name: string;
  sportCategoryId: string;
  level: TeamLevel | null;
  gender: Gender | null;
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
  /**
   * P4-187b — libellés FBI/FFBB confirmés qui désignent ce gymnase (lecture seule,
   * jamais écrit par un PUT ; normalisé/dédupliqué côté serveur). L'API l'omet quand
   * vide → `normalizeVenue` le ramène à `[]`.
   */
  externalLabels: string[];
}

export interface Category {
  id: string;
  name: string;
}

/**
 * P2-54 RMM-9 — la durée de match d'une catégorie. `matchMinutes`/`warmupMinutes`
 * sont l'override propre (null = héritée) ; `defaultMatchMinutes`/`defaultWarmupMinutes`
 * sont le défaut de FAMILLE, RÉSOLU par le serveur (MatchDurationResolver) — le front
 * les AFFICHE (placeholder, en-tête de groupe), il ne les recalcule jamais
 * (🔴 `.claude/rules/frontend.md`).
 */
export interface SportCategoryDuration {
  id: string;
  sportId: string;
  name: string;
  matchMinutes: number | null;
  warmupMinutes: number | null;
  defaultMatchMinutes: number;
  defaultWarmupMinutes: number;
}

export interface SportCategoryDurationInput {
  matchMinutes: number | null;
  warmupMinutes: number | null;
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
    unplacedReason: raw.unplacedReason ?? null,
    pendingDeviations: raw.pendingDeviations ?? [],
    reviewedAt: raw.reviewedAt ?? null,
    ffbbRencontreId: raw.ffbbRencontreId ?? null,
    suggestedVenueId: raw.suggestedVenueId ?? null,
    opponentOrganismeCode: raw.opponentOrganismeCode ?? null,
    opponentTeamKey: raw.opponentTeamKey ?? null,
    fbiEcho: raw.fbiEcho ?? null,
    awayTravel: raw.awayTravel ?? null,
  };
}

export const getFixtures = async (): Promise<Fixture[]> => (await collectionAll<Fixture>("fixtures")).map(normalizeFixture);
export const getCompetitions = (): Promise<Competition[]> => collectionAll<Competition>("competitions");

/**
 * RMM-6 — pose (ou EFFACE) UNE échéance de saisie sur un lot de compétitions, en un
 * seul geste (une transaction backend). `deadline: null` EXPLICITE = effacer ; le
 * backend rejette (422) une clé absente pour ne jamais essuyer les échéances en
 * silence. Un id inconnu/étranger → 422, rien écrit. Management-gated.
 */
export const setEntryDeadlines = (competitionIds: string[], deadline: string | null): Promise<{ updated: string[]; deadline: string | null }> =>
  api.post("competitions/entry-deadlines", { json: { competitionIds, deadline } }).json<{ updated: string[]; deadline: string | null }>();
export const getTeams = (): Promise<Team[]> => collectionAll<Team>("teams");
// Tiers are a tiny fixed set (S/A/B/C/D) and their id is numeric, so use the
// unpaginated `collection` (collectionAll constrains T to a string id).
export const getPriorityTiers = (): Promise<PriorityTier[]> => collection<PriorityTier>("priority_tiers");
/** The API omits `externalLabels` when empty → coerce it back to `[]` so consumers
 * never see `undefined`. */
function normalizeVenue(raw: Venue): Venue {
  return { ...raw, externalLabels: raw.externalLabels ?? [] };
}

export const getVenues = async (): Promise<Venue[]> => sortByName((await collectionAll<Venue>("venues")).map(normalizeVenue));

export interface AttachVenueLabelInput {
  venueId: string;
  /** Le libellé BRUT (`fbiVenueLabel`) — le serveur NORMALISE (translit/casse/espaces). */
  label: string;
  /**
   * E1/E2 — retirer l'alias de son porteur actuel et re-pointer les domiciles NON
   * PLACÉS du même libellé sur `venueId` (les placés gardent leur salle). Défaut
   * `false` = rattachement idempotent (422 si le libellé est déjà porté ailleurs).
   */
  reassign?: boolean;
}

export interface AttachVenueLabelResult {
  venueId: string;
  label: string;
  /** Domiciles nouvellement rattachés (backfill sans `reassign`, re-pointés avec) — 0 si idempotent. */
  attached: number;
  /** Ré-affectation seulement : domiciles PLACÉS qui gardent leur salle. Absent sans `reassign`. */
  kept?: number;
  /** Ré-affectation seulement : le gymnase qui portait l'alias avant (null si aucun). Absent sans `reassign`. */
  previousVenueId?: string | null;
}

/**
 * P4-187b — rattache (ou, avec `reassign`, ré-affecte) un libellé FBI/FFBB à un
 * gymnase (alias confirmé) PUIS backfille tous les domiciles du club+saison encore
 * sans salle au même libellé (toutes équipes). On envoie le libellé BRUT, le serveur
 * le normalise. Sans `reassign` : 422 nommé si le libellé est déjà porté par un AUTRE
 * gymnase (« retirez-le d'abord »), vide, trop long, ou au-delà de 30 alias. Avec
 * `reassign: true` (E1/E2) : l'alias quitte son ancien porteur (`previousVenueId`) et
 * les domiciles NON PLACÉS basculent (`attached`), les placés restant (`kept`).
 * Management-gated, 409 saison archivée. Le message serveur est affiché tel quel par
 * `onError` (`errorMessage`).
 */
export const attachVenueLabel = ({ venueId, label, reassign }: AttachVenueLabelInput): Promise<AttachVenueLabelResult> =>
  api.post(`venues/${venueId}/external-labels`, { json: true === reassign ? { label, reassign: true } : { label } }).json<AttachVenueLabelResult>();

/**
 * E2 — une ligne de l'inventaire agrégé des libellés de salle FBI/FFBB de la saison
 * courante (`GET /api/venues/fbi-labels`), un objet par libellé NORMALISÉ. Lecture
 * ouverte à tout membre (c'est un ÉTAT, pas un geste). Le front COMPTE ce que le
 * serveur a agrégé ; il ne redérive jamais la normalisation (clé = `labelKey` servi,
 * 🔴 `.claude/rules/frontend.md`).
 */
export interface VenueLabelInventoryRow {
  /** Le libellé normalisé — clé de regroupement ET libellé BRUT à renvoyer à l'attache. */
  labelKey: string;
  /** La graphie brute la plus fréquente (affichée). */
  displayLabel: string;
  /** L'alias CONFIRMÉ (le gymnase rattaché), null si aucun. */
  venueId: string | null;
  /** Le gymnase UNANIME des domiciles placés (null si divergent, ou déjà égal à `venueId`). */
  suggestedVenueId: string | null;
  homeCount: number;
  placedCount: number;
  unplacedCount: number;
}

export const getVenueLabelInventory = (): Promise<VenueLabelInventoryRow[]> =>
  api.get("venues/fbi-labels").json<{ labels: VenueLabelInventoryRow[] }>().then((r) => r.labels);

export interface DetachVenueLabelInput {
  venueId: string;
  /** L'alias NORMALISÉ stocké (`Venue.externalLabels`) — il contient des espaces, d'où l'encodage du chemin. */
  label: string;
}

/**
 * P4-196 — retire un alias FBI/FFBB d'un gymnase. Ne touche AUCUNE rencontre déjà
 * rattachée (le `venueId` posé reste, seul l'alias qui l'a produit part) ; 204,
 * idempotent. Le libellé est l'alias NORMALISÉ (avec espaces) → encodage du chemin
 * obligatoire. 404 gymnase étranger, 409 saison archivée ; management-gated.
 */
export const detachVenueLabel = ({ venueId, label }: DetachVenueLabelInput): Promise<void> =>
  api.delete(`venues/${venueId}/external-labels/${encodeURIComponent(label)}`).then(() => undefined);
export const getCategories = (): Promise<Category[]> => collectionAll<Category>("sport_categories");
export const getCoaches = (): Promise<Coach[]> => collectionAll<Coach>("coaches");

export const getSportCategoryDurations = (): Promise<SportCategoryDuration[]> => collectionAll<SportCategoryDuration>("sport_categories");

/**
 * PUT re-sends sportId + name (tous deux NotBlank côté serveur) avec les deux durées.
 * NULL = « revient au défaut de famille » — le serveur vide la colonne (jamais 0).
 */
export const updateSportCategoryDuration = (category: SportCategoryDuration, input: SportCategoryDurationInput): Promise<SportCategoryDuration> =>
  api
    .put(`sport_categories/${category.id}`, { json: { sportId: category.sportId, name: category.name, matchMinutes: input.matchMinutes, warmupMinutes: input.warmupMinutes } })
    .json<SportCategoryDuration>();

/** The league match-kickoff windows inherited by the club (envelope, AURA default). */
export const getLeagueWindows = (): Promise<LeagueWindowsResponse> =>
  api.get("league-match-windows").json<LeagueWindowsResponse>();

/** Same-coach conflict radar, recomputed server-side on every call. */
export const getConflicts = (): Promise<ConflictsResponse> => api.get("fixtures/conflicts").json<ConflictsResponse>();

/**
 * P4-207 — pose (ou REMPLACE) la résolution d'un conflit, adressée par son EMPREINTE
 * (jamais un id). Le PUT est un remplacement plein : `note` absente ⇒ note vidée côté
 * serveur — l'appelant qui ne veut CHANGER que le statut resservit donc la note
 * existante. Management-gated (403 membre) ; 422 statut inconnu / note > 500 /
 * empreinte disparue du flux. Rend l'état à jour `{fingerprint, resolution}`.
 */
export const putConflictResolution = (fingerprint: string, input: { status: ConflictResolutionStatus; note?: string }): Promise<{ fingerprint: string; resolution: ConflictResolution }> =>
  api.put(`fixtures/conflicts/${fingerprint}/resolution`, { json: input }).json<{ fingerprint: string; resolution: ConflictResolution }>();

/**
 * P4-207 — remet un conflit « à traiter » (efface sa résolution). 204 idempotent —
 * même sans ligne existante. Management-gated.
 */
export const deleteConflictResolution = (fingerprint: string): Promise<void> =>
  api.delete(`fixtures/conflicts/${fingerprint}/resolution`).then(() => undefined);

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

// ── Échéances de saisie : l'outlook J-7 du cockpit (RMM-6 PR-3) ───────────────

/**
 * Une échéance EFFECTIVE encore due, groupée (date, source) par le backend. La
 * règle J-7 (`withinWindow`) est calculée SERVEUR — la maison unique
 * `EntryDeadlineOutlook` : le front n'invente aucune fenêtre (🔴 `.claude/rules/frontend.md`).
 */
export interface DeadlineOutlookWindow {
  /** Y-m-d */
  deadline: string;
  /** 'club' (valeur du club) | 'community' (défaut communautaire proposé). */
  source: "club" | "community";
  competitionNames: string[];
  /** Domiciles pas encore saisis dans FBI (UNPLACED compris). */
  toEnterCount: number;
  /** Vraie dans les sept jours de l'échéance (dépassée comprise) — calcul BACKEND. */
  withinWindow: boolean;
}

/**
 * Le delta du « gardien » joint à l'outlook QUAND une fenêtre est ouverte et que
 * l'utilisateur a déjà une référence de visite — lu SANS stamper (RMM-6 PR-1).
 * Sous-ensemble de `ModuleVisitDelta` (ni `firstVisit` ni `referenceTakenAt`).
 */
export interface DeadlineGuardianDelta {
  newFixturesCount: number;
  newConflictFingerprints: string[];
  planningChanged: boolean;
}

export interface DeadlineOutlook {
  windows: DeadlineOutlookWindow[];
  /**
   * Le « à faire dans FBI » GLOBAL (toutes semaines), servi pour que le cockpit ET la
   * barre des compteurs n'aient jamais à charger les fixtures : à saisir = domiciles
   * PLACED, à corriger = entrées ouvertes du registre.
   * Optionnel côté TS (le backend le sert toujours ; l'absence retombe sur 0 à l'usage).
   */
  fbiTodo?: FbiTodo;
  /** Absent quand aucune fenêtre n'est ouverte OU sans référence de visite. */
  guardianDelta?: DeadlineGuardianDelta;
}

export interface FbiTodo {
  toEnter: number;
  toCorrect: number;
}

/** L'outlook J-7 des échéances de saisie (lecture seule, ouvert au Membre). */
export const getDeadlineOutlook = (): Promise<DeadlineOutlook> => api.get("matches/deadline-outlook").json<DeadlineOutlook>();

// ── Registre « à corriger dans FBI » ──────────────────────────────────────────

/**
 * Une entrée OUVERTE du registre « à corriger dans FBI » : sur une rencontre et un
 * champ, l'appli fait foi (`appValue` = à taper dans FBI) et FBI est en retard
 * (`fbiValue` = ce que FBI affiche encore). Servie par le serveur — le front l'AFFICHE.
 */
export interface FbiCorrection {
  id: string;
  fixtureId: string;
  field: DeviationField;
  appValue: string | null;
  fbiValue: string | null;
  /** L'alias FBI du gymnase de l'appli, quand connu — ce qu'il faut sélectionner dans FBI. */
  venueFbiLabel: string | null;
  /** ISO. */
  decidedAt: string;
  /** ISO ou null — dernière fois qu'un dépôt a re-vu cet écart dans FBI. */
  lastSeenInFbiAt: string | null;
}

/** Les entrées OUVERTES du registre du club+saison. */
export const getFbiCorrections = (): Promise<FbiCorrection[]> =>
  api.get("fixtures/fbi-corrections").json<{ corrections: FbiCorrection[] }>().then((r) => r.corrections);

/** Coche « corrigé dans FBI » (fermeture manuelle). */
export const closeFbiCorrection = (id: string): Promise<FbiCorrection> =>
  api.post(`fixtures/fbi-corrections/${id}/close`).json<FbiCorrection>();

/** Annule un « corrigé dans FBI » manuel récent (< 24 h). */
export const reopenFbiCorrection = (id: string): Promise<FbiCorrection> =>
  api.post(`fixtures/fbi-corrections/${id}/reopen`).json<FbiCorrection>();

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

// ── Rotation A/B — shared match slots (RMM-5) ────────────────────────────────

/**
 * A shared match slot (venue + day + kickoff) and its ORDERED teams, alternating
 * A/B/C. `teamIds` order is FICTIONAL — it draws the alternation on screen and
 * drives no calendar (founder decision, spec §8). Read open to Member, write
 * management-gated (backend rail default).
 */
export interface MatchSlotRotation {
  id: string;
  venueId: string;
  /** ISO 1..7 */
  dayOfWeek: number;
  /** HH:MM */
  kickoffTime: string;
  /** Ordered members (position ASC) — the order IS the A/B/C drawing, nothing more. */
  teamIds: string[];
}

export interface MatchSlotRotationInput {
  venueId: string;
  dayOfWeek: number;
  kickoffTime: string;
  teamIds: string[];
}

export const getMatchSlotRotations = (): Promise<MatchSlotRotation[]> => collectionAll<MatchSlotRotation>("match_slot_rotations");

export const createMatchSlotRotation = (input: MatchSlotRotationInput): Promise<MatchSlotRotation> =>
  api.post("match_slot_rotations", { json: input }).json<MatchSlotRotation>();

/** PUT is a full replace: the whole slot + ordered roster is re-sent (backend rewrites members). */
export const updateMatchSlotRotation = (id: string, input: MatchSlotRotationInput): Promise<MatchSlotRotation> =>
  api.put(`match_slot_rotations/${id}`, { json: input }).json<MatchSlotRotation>();

export const deleteMatchSlotRotation = (id: string): Promise<void> => api.delete(`match_slot_rotations/${id}`).then(() => undefined);

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

// ── FFBB-API reconciliation channel (RMM-4 PR-3) ─────────────────────────────
// FBI (the xlsx) stays the truth; the API is a convenience that ADDS the amicaux
// the xlsx never lists. Same `Deviation`/`DeviationDecision` shapes as the import.

/** A FFBB-published rencontre absent of the app, proposed for creation. The
 * manager picks a team per line (none = not created); `suggestedTeamId`
 * pre-fills it when a paired competition resolved the team. */
export interface RencontreCreatable {
  rencontreId: string;
  competitionNom: string;
  date: string;
  kickoff: string | null;
  homeAway: HomeAway;
  opponentLabel: string;
  venueLabel: string | null;
  numeroJournee: string | null;
  suggestedTeamId: string | null;
}

/** GET /api/ffbb/rencontres — the club's FFBB-published rencontres crossed with
 * the app: the diff (deviations, same shape as the xlsx analyze) + the
 * rencontres with no matching fixture (creatable). Never a promise of coverage. */
export interface FfbbRencontresResult {
  deviations: Deviation[];
  creatable: RencontreCreatable[];
  fetchedAt: string;
}

/** POST /api/ffbb/rencontres/apply — the per-écart decisions applied via the
 * SAME engine as the xlsx import + the chosen rencontres created (idempotent). */
export interface ApplyRencontresResult {
  created: number;
  updated: number;
  unresolvedDeviations: Deviation[];
  depositedAt: string;
}

/** One chosen creation: a rencontre + the team it is created for. */
export interface RencontreCreation {
  rencontreId: string;
  teamId: string;
}

export const getFfbbRencontres = (): Promise<FfbbRencontresResult> =>
  api.get("ffbb/rencontres").json<FfbbRencontresResult>();

export const applyFfbbRencontres = (decisions: DeviationDecision[], creations: RencontreCreation[]): Promise<ApplyRencontresResult> =>
  api.post("ffbb/rencontres/apply", { json: { decisions, creations } }).json<ApplyRencontresResult>();

// ── Traitement des rencontres (PR-3a — la file « Importer ») ─────────────────
// L'axe TRAITEMENT (`reviewState`) est écrit par deux gestes seulement ; un PUT
// n'y touche jamais. Les compteurs par équipe sont dérivés côté client.

/**
 * PR-3a — le geste de traitement, EXACTEMENT un des deux : `{fixtureIds}` (geste
 * LIGNE — chaque rencontre listée traitée, ses écarts pendants vidés, « garder
 * l'app » implicite) OU `{teamId}` (geste MASSE — toutes les rencontres de
 * l'équipe traitées SAUF celles à écart pendant, sautées et nommées dans
 * `skipped`, jamais tranchées en masse).
 */
export type ReviewFixturesBody = { fixtureIds: string[] } | { teamId: string };

export interface ReviewFixturesResult {
  reviewed: number;
  skipped: { fixtureId: string; reason: string }[];
}

export const reviewFixtures = (body: ReviewFixturesBody): Promise<ReviewFixturesResult> =>
  api.post("fixtures/review", { json: body }).json<ReviewFixturesResult>();

/**
 * PR-3a — tranche UN écart pendant d'une rencontre. `take_source` REJOUE le
 * moteur partagé côté serveur à partir de la valeur PERSISTÉE de l'écart (jamais
 * une valeur du client) : date/salle dé-placent le match (UNPLACED), heure reste
 * en place. `keep_app` retire l'écart sans écrire. Dernier écart retiré →
 * REVIEWED + horodatée.
 */
export interface ResolveDeviationInput {
  fixtureId: string;
  field: DeviationField;
  choice: "keep_app" | "take_source";
}

export interface ResolveDeviationResult {
  fixtureId: string;
  reviewState: FixtureReviewState;
  reviewedAt: string | null;
  pendingDeviations: PendingDeviation[];
}

export const resolveFixtureDeviation = (input: ResolveDeviationInput): Promise<ResolveDeviationResult> =>
  api.post("fixtures/review/deviations", { json: input }).json<ResolveDeviationResult>();

/**
 * Lot L — « validé ligue » en lot. Un club qui démarre EN COURS de saison importe des
 * domiciles déjà datés côté fédération (date + heure + gymnase) : plutôt que confirmer
 * chaque placement, un geste chiffré les bascule d'un coup. Le backend est la SEULE
 * maison du prédicat d'éligibilité — le front AFFICHE le compte servi, il ne le
 * redérive JAMAIS (🔴 `.claude/rules/frontend.md`). `count` = combien sont validables ;
 * `confirmed` = combien ont basculé (VALIDATED + source MANUAL). Rejouable : un second
 * appel rend 0.
 */
export interface LeagueValidationCount {
  count: number;
}

export interface LeagueValidationResult {
  confirmed: number;
}

export const getLeagueValidationCount = (): Promise<LeagueValidationCount> =>
  api.get("fixtures/league-validation").json<LeagueValidationCount>();

export const confirmLeagueValidatedFixtures = (): Promise<LeagueValidationResult> =>
  api.post("fixtures/league-validation").json<LeagueValidationResult>();

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

/** Une salle FFBB proposée par le proxy — le patron combobox de VenuesStep. */
export interface FfbbSalle {
  name: string;
  address: string | null;
  city: string | null;
  externalRef: string | null;
  latitude: string | null;
  longitude: string | null;
}

export const listFfbbSalles = (postalCode: string): Promise<{ postalCode: string | null; salles: FfbbSalle[] }> =>
  api.get("ffbb/salles", { searchParams: { postalCode } }).json();

/** Recherche des salles FFBB par NOM (plein-texte, ≥ 3 caractères) — alternative au code postal. */
export const listFfbbSallesByName = (name: string): Promise<{ postalCode: string | null; salles: FfbbSalle[] }> =>
  api.get("ffbb/salles", { searchParams: { q: name } }).json();

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
