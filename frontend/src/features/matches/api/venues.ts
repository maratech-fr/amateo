import { api } from "@/shared/api/client";
import { collectionAll } from "@/shared/api/collection";
import { sortByName } from "@/shared/lib/nameOrder";
import type { FixtureStatus } from "./fixtures";

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
