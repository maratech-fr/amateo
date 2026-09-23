import { api } from "@/shared/api/client";
import type { DeviationField, FixtureReviewState, FixtureStatus, PendingDeviation } from "./fixtures";

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
