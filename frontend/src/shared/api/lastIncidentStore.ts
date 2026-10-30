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
  // D17 — repères de l'erreur API, captés par `client.ts` pour l'anneau `recentErrors` du signalement.
  method?: string;
  route?: string;
  errors?: string[];
  at: number;
}

const FRESHNESS_WINDOW_MS = 10 * 60 * 1000;

// D17 — l'anneau des 5 dernières erreurs API (≥ 400), joint au contexte d'un signalement. DISTINCT du
// dernier incident ≥ 500 (`lastIncident`, bloc « Détails techniques dev ») : les deux cohabitent.
const RECENT_RING_SIZE = 5;

let lastIncident: LastIncident | null = null;
let recentIncidents: LastIncident[] = [];

/** Empile une entrée dans l'anneau (la plus récente en tête, plafonné à 5). */
function pushRecent(entry: LastIncident): void {
  recentIncidents = [entry, ...recentIncidents].slice(0, RECENT_RING_SIZE);
}

export function recordIncident(incident: Omit<LastIncident, "at">, at: number = Date.now()): void {
  const entry = { ...incident, at };
  lastIncident = entry;
  pushRecent(entry);
}

/**
 * D17 — enregistre une erreur API dans l'ANNEAU SEUL, sans toucher `lastIncident` : `client.ts`
 * l'appelle pour les 4xx (le bloc dev ne retient que le dernier ≥ 500, cf. `readRecentIncident`).
 */
export function recordRecentIncident(incident: Omit<LastIncident, "at">, at: number = Date.now()): void {
  pushRecent({ ...incident, at });
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

/**
 * D17 — les erreurs API récentes (< 10 min), la plus RÉCENTE en tête, au plus 5. L'anneau nourrit le
 * champ `recentErrors` du signalement contextuel.
 */
export function readRecentIncidents(now: number = Date.now()): LastIncident[] {
  return recentIncidents.filter((incident) => now - incident.at < FRESHNESS_WINDOW_MS);
}

export function clearLastIncident(): void {
  lastIncident = null;
  recentIncidents = [];
}
