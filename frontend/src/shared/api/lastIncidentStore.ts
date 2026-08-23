/**
 * P5-6 → P4-129 — mémoire d'un seul incident serveur (statut ≥ 500), écrite par le
 * hook `afterResponse` de `client.ts`. Deux consommateurs, deux besoins :
 *   - la modale de signalement contextuel (`FeedbackDialog`, P5-6) veut le seul
 *     `request_id` ré-émis par le backend, pour corréler front↔logs (P5-11) ;
 *   - le bloc « Détails techniques (dev) » (P4-129) veut TOUT ce qu'on sait de
 *     l'incident : statut HTTP réel, URL appelée, code d'erreur machine, request-id,
 *     horodatage.
 *
 * P4-129 — pourquoi l'extension : l'ancien rail ne retenait un incident QUE lorsqu'un
 * `X-Request-Id` était présent. Or le 502 nginx observé sur :5173 arrivait SANS ce
 * header — l'incident même qui a motivé P4-129 n'était alors PAS capturé, ni pour le
 * signalement ni pour le debug. On retient désormais l'incident même sans request-id ;
 * statut + URL suffisent à savoir qu'il s'est passé quelque chose et où.
 *
 * Présentation pure, aucune règle métier : on retient le dernier incident et sa date,
 * et on ne le rend que tant qu'il est frais (< 10 min) — au-delà, il ne colle plus au
 * geste de l'utilisateur qui ouvre le signalement ou regarde l'écran.
 */
export interface LastIncident {
  status: number;
  url: string;
  code?: string;
  requestId?: string;
  at: number;
}

const FRESHNESS_WINDOW_MS = 10 * 60 * 1000;

let lastIncident: LastIncident | null = null;

export function recordIncident(incident: Omit<LastIncident, "at">, at: number = Date.now()): void {
  lastIncident = { ...incident, at };
}

/** Le dernier incident serveur s'il date de moins de 10 min, sinon null. */
export function readRecentIncident(now: number = Date.now()): LastIncident | null {
  if (null === lastIncident) {
    return null;
  }
  if (now - lastIncident.at >= FRESHNESS_WINDOW_MS) {
    return null;
  }
  return lastIncident;
}

/**
 * Le request-id du dernier incident frais, sinon null. Wrapper mince PRÉSERVÉ pour
 * `FeedbackDialog` (P5-6) : rend null si l'incident n'a pas de request-id (502 nginx).
 */
export function readRecentIncidentRequestId(now: number = Date.now()): string | null {
  return readRecentIncident(now)?.requestId ?? null;
}

export function clearLastIncident(): void {
  lastIncident = null;
}
