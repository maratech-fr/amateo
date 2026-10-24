import { api } from "@/shared/api/client";

/**
 * Canal de signalement (P5-6) — POST /api/feedback. Types alignés sur le contrat du
 * backend (validation inline `FeedbackController`) : un topic parmi trois, un message
 * obligatoire, un contexte optionnel copié tel quel (le serveur ne garde que les clés
 * connues et enrichit lui-même le lourd — snapshot/diagnostics — si `scheduleId` valide).
 */
export type FeedbackTopic = "bug" | "missing_constraint" | "idea";

export interface FeedbackContext {
  screen?: string;
  url?: string;
  requestId?: string;
  userAgent?: string;
  scheduleId?: string;
  // Chantier F PR2 — repères que SEUL le front connaît, joints par la porte CONTEXTUELLE :
  // le libellé d'écran lisible, les filtres/plage affichés, le dernier geste accessible et
  // le trio complet du dernier incident serveur (statut + URL + code).
  screenLabel?: string;
  activeFilters?: string;
  displayedRange?: string;
  lastClick?: string;
  incidentStatus?: number;
  incidentUrl?: string;
  incidentCode?: string;
}

export interface FeedbackPayload {
  topic: FeedbackTopic;
  message: string;
  context?: FeedbackContext;
}

export interface FeedbackCreated {
  id: string;
}

export const submitFeedback = (body: FeedbackPayload): Promise<FeedbackCreated> => api.post("feedback", { json: body }).json();
