import { api } from "@/shared/api/client";
import { collection } from "@/shared/api/collection";
import { downloadBlob } from "@/shared/lib/download";

export interface AppearancePayload {
  accentColor?: string | null;
  accentColorDark?: string | null;
  accentPalette?: string[] | null;
}

export interface AppearanceResult {
  accentColor: string | null;
  accentColorDark: string | null;
  accentPalette: string[] | null;
}

/** Partial update of the club identity (accent), scoped server-side to the JWT club. */
export const updateAppearance = (body: AppearancePayload): Promise<AppearanceResult> => api.patch("club/appearance", { json: body }).json();

export interface SiegeResult {
  address: string | null;
  postalCode: string | null;
  city: string | null;
  geolocated: boolean;
}

/**
 * Pose le SIÈGE du club depuis un LIBELLÉ d'adresse (management). Le serveur RE-géocode via la
 * BAN et écrit adresse/CP/ville + coordonnées depuis SON hit — jamais les coordonnées du client
 * (patron SEC-15). 422 « adresse introuvable », 502 BAN muet.
 */
export const updateSiege = (address: string): Promise<SiegeResult> => api.patch("club/siege", { json: { address } }).json();

// La fiche club n'a plus AUCUN champ saisissable (décision fondateur 2026-08-04) :
// la FFBB fait autorité, le geste de correction est le ré-import ci-dessous.
// L'ancien PATCH /api/club/info a été supprimé avec ses champs.
export interface FfbbImportResult {
  populated: boolean;
  error?: string;
}

/** Re-import institutionnel depuis la FFBB (management) — écrase les champs dont la fédération fait autorité. */
export const ffbbImport = (): Promise<FfbbImportResult> => api.post("club/ffbb-import").json();

/** Upload the club logo (multipart); returns its public URL. */
export const uploadLogo = (file: File): Promise<{ logoUrl: string }> => {
  const form = new FormData();
  form.append("file", file);
  return api.post("club/logo", { body: form }).json();
};

export const deleteLogo = (): Promise<{ logoUrl: null }> => api.delete("club/logo").json();

export interface ResetClubResult {
  status: string;
  deleted: number;
}

/**
 * Wipe every piece of data entered for the current club/season (teams, venues,
 * coaches, constraints, schedules) to start over. The season is resolved
 * server-side (TenantFilterListener sets _season_id from the active season).
 */
export const resetClub = (): Promise<ResetClubResult> => api.delete("reset-season").json();

/**
 * RGPD portabilité : télécharge l'export JSON complet du workspace du club
 * (management). timeout désactivé : le backend construit tout le JSON avant de
 * répondre (le défaut ky de 10 s couperait les gros clubs).
 */
export async function downloadClubExport(): Promise<void> {
  const blob = await api.get("club/export", { timeout: false }).blob();
  downloadBlob(blob, "donnees-club.json");
}

/**
 * P1-3 §4bis pt 5 — catalogue public des offres (`GET /api/subscription_plans`).
 * SANS montant (décision fondateur : « sur demande » partout), la bêta ABSENTE
 * (masquée côté provider). Convention `maxTeams === 0` = illimité.
 */
export interface SubscriptionPlan {
  id: string;
  code: string;
  name: string;
  maxTeams: number;
  maxVenues: number;
  maxGenerations: number;
}

export const listSubscriptionPlans = (): Promise<SubscriptionPlan[]> => collection<SubscriptionPlan>("subscription_plans");

/**
 * P3-22 — stats d'utilisation des gymnases (`GET /api/venue-usage-stats`). TOUT
 * est calculé serveur (heures par jour, Réalisé/À venir, ventilation par niveau,
 * libellés compris) : le front ne fait qu'AFFICHER. Sans from/to, le backend
 * borne à la saison entière.
 */
export interface UsageDayHours {
  day: number;
  real: number;
  projected: number;
  total: number;
}

export interface UsageRow {
  /** venueId (par gymnase) ou level enum|null (par niveau). */
  venueId?: string;
  level?: string | null;
  name?: string;
  label?: string;
  byDay: UsageDayHours[];
  real: number;
  projected: number;
  total: number;
}

export interface VenueUsageStats {
  range: { from: string; to: string; today: string };
  zone: string | null;
  venues: (UsageRow & { venueId: string; name: string })[];
  totalByDay: UsageDayHours[];
  byLevel: (UsageRow & { level: string | null; label: string })[];
  grandTotal: { real: number; projected: number; total: number };
}

export const getVenueUsageStats = (from?: string, to?: string): Promise<VenueUsageStats> =>
  api.get("venue-usage-stats", from && to ? { searchParams: { from, to } } : undefined).json();
