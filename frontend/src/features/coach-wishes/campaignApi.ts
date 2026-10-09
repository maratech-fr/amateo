import { api } from "@/shared/api/client";
import { collectionAll } from "@/shared/api/collection";

import type { PublicWishContext } from "./publicApi";

/**
 * Une campagne de collecte des doléances (feature #10, lot C2) : une par période de
 * vacances (ancrée à l'entrée MÈRE), modifiable (semaines/équipes/deadline). Le back
 * expose un token EN CLAIR par coach du périmètre courant ; le front construit le lien
 * `${origin}/doleances/${token}` que le gestionnaire copie dans WhatsApp (pas d'email en C2).
 */
export interface CampaignCoach {
  coachId: string;
  firstName: string;
  lastName: string;
  /** Préparé pour C3 (envoi email) — aucun effet en C2. */
  email: string | null;
  /** Secret 64 hex, sert à bâtir le lien personnel. */
  token: string;
  /** ISO 8601, ou null si le coach n'a pas encore répondu. */
  respondedAt: string | null;
  /** Dernier envoi du lien par email (C3) — null = jamais envoyé. */
  sentAt: string | null;
}

export interface CoachWishCampaign {
  id: string;
  calendarEntryId: string;
  /** Y-m-d — après cette date, les liens sont expirés (410). */
  deadline: string;
  /** Lundis Y-m-d des semaines retenues. */
  weeks: string[];
  teamIds: string[];
  /** Radar : coachs du périmètre courant / répondants / doléances à traiter. */
  totalCoachCount: number;
  respondedCoachCount: number;
  openWishCount: number;
  /** Dernière relance manuelle (C3) — le bouton se bloque le reste de la journée. */
  lastReminderAt: string | null;
  coaches: CampaignCoach[];
}

export interface CoachWishCampaignPayload {
  calendarEntryId: string;
  deadline: string;
  weeks: string[];
  teamIds: string[];
}

export const listCoachWishCampaigns = (): Promise<CoachWishCampaign[]> => collectionAll<CoachWishCampaign>("coach_wish_campaigns", {});

export const createCoachWishCampaign = (body: CoachWishCampaignPayload): Promise<CoachWishCampaign> => api.post("coach_wish_campaigns", { json: body }).json();

export const updateCoachWishCampaign = (id: string, body: CoachWishCampaignPayload): Promise<CoachWishCampaign> => api.put(`coach_wish_campaigns/${id}`, { json: body }).json();

/** Construit le lien public personnel d'un coach à partir de son token. */
export const doleancesLink = (token: string): string => `${window.location.origin}/doleances/${token}`;

/** Réponse des actions d'envoi (C3) : nombre d'emails partis + la campagne re-projetée. */
export interface CampaignActionResult {
  sent: number;
  campaign: CoachWishCampaign;
}

/** Envoie les liens par email — sans coachIds : tous les coachs à email pas encore servis. */
export const sendCampaignLinks = (id: string, coachIds?: string[]): Promise<CampaignActionResult> =>
  api.post(`coach_wish_campaigns/${id}/send-links`, { json: coachIds ? { coachIds } : {} }).json();

/** Relance les silencieux (1×/jour — 422 si déjà relancé aujourd'hui). */
export const remindCampaignSilent = (id: string): Promise<CampaignActionResult> => api.post(`coach_wish_campaigns/${id}/remind`, { json: {} }).json();

/**
 * Aperçu de l'e-mail du lien coach (D1) : l'e-mail EXACT de l'envoi initial, bâti côté serveur
 * avec un jeton FACTICE (jamais un vrai lien personnel) et un prénom de coach d'exemple. Le HTML
 * est prêt à afficher dans une iframe sandboxée (logos en data: URI).
 */
export interface CoachWishEmailPreview {
  subject: string;
  /** Expéditeur affiché, p. ex. « Prénom (Club) via Amateo <no-reply@amateo.app> ». */
  from: string;
  /** Corps HTML rendu, sûr à injecter dans une iframe `sandbox=""`. */
  html: string;
}

/** Récupère l'aperçu de l'e-mail du lien coach d'une campagne (gestionnaire seulement). */
export const getCoachWishEmailPreview = (id: string): Promise<CoachWishEmailPreview> => api.get(`coach_wish_campaigns/${id}/email-preview`).json();

/**
 * Aperçu gestionnaire (D2) : la VRAIE page telle que le coach `coachId` la verra, en lecture
 * seule. Authentifié (cookie JWT), jamais de jeton forgé. Même forme que le GET public, vidée
 * des données du coach (wishes=[], mutualizations=[], respondedAt=null).
 */
export const getCampaignPreview = (campaignId: string, coachId: string): Promise<PublicWishContext> =>
  api.get(`coach_wish_campaigns/${campaignId}/preview`, { searchParams: { coachId } }).json();
