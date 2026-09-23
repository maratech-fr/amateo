/**
 * Matches read/write API (module matchs, palier A PR-3). Tenant (club) + active
 * season are resolved server-side from the JWT — no header is sent. Consumes only
 * the endpoints delivered by PR-1/PR-2; this PR adds no backend.
 */

import { api } from "@/shared/api/client";
import type { HomeAway } from "./fixtures";
import type { Deviation, DeviationDecision } from "./fbi";

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
export * from "./fbi";
