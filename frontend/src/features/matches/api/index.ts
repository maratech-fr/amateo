/**
 * Matches read/write API (module matchs, palier A PR-3). Tenant (club) + active
 * season are resolved server-side from the JWT — no header is sent. Consumes only
 * the endpoints delivered by PR-1/PR-2; this PR adds no backend.
 */

import { api } from "@/shared/api/client";
import type { DeviationField, FixtureReviewState, FixtureStatus, HomeAway, PendingDeviation } from "./fixtures";

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

export * from "./opponents";
export * from "./fixtures";
export * from "./conflicts";
export * from "./venues";
export * from "./teams";
export * from "./competitions";
