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
  // P4-355 — version du bundle front (foyer `shared/lib/appVersion.ts`), jointe au
  // contexte de BASE des deux portes ; bornée serveur à 40 caractères.
  appVersion?: string;
}

export interface FeedbackPayload {
  topic: FeedbackTopic;
  message: string;
  context?: FeedbackContext;
  // Chantier F PR3 — capture d'écran optionnelle. Présente → l'envoi passe en multipart
  // (le JSON va dans le champ `payload`, l'image dans le fichier `screenshot`).
  screenshot?: Blob;
}

export interface FeedbackCreated {
  id: string;
}

export const submitFeedback = (body: FeedbackPayload): Promise<FeedbackCreated> => {
  if (undefined !== body.screenshot) {
    const { screenshot, ...rest } = body;
    const form = new FormData();
    form.append("payload", JSON.stringify(rest));
    // Le serveur SONDE les octets (type/dimensions) et ignore le nom de fichier.
    form.append("screenshot", screenshot, "capture.webp");
    return api.post("feedback", { body: form }).json();
  }
  return api.post("feedback", { json: body }).json();
};
