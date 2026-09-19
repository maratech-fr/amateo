import type { QueryClient } from "@tanstack/react-query";
import { useQueryClient } from "@tanstack/react-query";
import { useEffect, useSyncExternalStore } from "react";

import { api } from "@/shared/api/client";

/**
 * C6 — la consommation Mercure du CALCUL DES TRAJETS (adversaires + matrice de gymnases),
 * en UN seul endroit. Patron exact de `features/planning/lib/scheduleStream.ts`, mais placé
 * dans `shared/` : deux features l'écoutent (matchs · wizard).
 *
 * Le backend calcule les trajets dans le WORKER (rafale IGN pacée > plafond HTTP) et publie
 * l'avancement `{scope, done, total, terminal, verdict?}` sur le topic PRIVÉ FIXE
 * `club:{clubId}:travel`. Auth : le cookie httpOnly de `GET /api/mercure/auth`, dont la
 * réponse porte `travelTopic` (le front ne connaît pas son clubId — tenant résolu serveur).
 *
 * Mercure reste BEST-EFFORT : à réception, on INVALIDE (débounce ~500 ms) les caches
 * react-query — le GET reste la vérité, `travelStatus` par ligne — et on expose le DERNIER
 * événement pour les jauges de progression. Repli sans flux : les mutations invalident déjà à
 * l'ouverture ; sans SSE le refetch se fait à la prochaine interaction.
 */

const RETRY_MS = 10_000;
const INVALIDATE_DEBOUNCE_MS = 500;

export type TravelScope = "OPPONENTS" | "VENUE_MATRIX";

export interface TravelVerdict {
  filled: number;
  unresolved: unknown[];
}

export interface TravelStreamEvent {
  scope: TravelScope | null;
  done: number;
  total: number;
  terminal: boolean;
  verdict: TravelVerdict | null;
}

/** Parse un événement du hub — `null` pour tout ce qui n'est pas un objet JSON de trajet. */
export function parseTravelEvent(raw: string): TravelStreamEvent | null {
  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    return null;
  }
  if (null === parsed || "object" !== typeof parsed || Array.isArray(parsed)) {
    return null;
  }
  const record = parsed as Record<string, unknown>;
  const scope = "OPPONENTS" === record.scope || "VENUE_MATRIX" === record.scope ? record.scope : null;
  const verdict =
    null !== record.verdict && "object" === typeof record.verdict && !Array.isArray(record.verdict)
      ? {
          filled: "number" === typeof (record.verdict as Record<string, unknown>).filled ? ((record.verdict as Record<string, unknown>).filled as number) : 0,
          unresolved: Array.isArray((record.verdict as Record<string, unknown>).unresolved) ? ((record.verdict as Record<string, unknown>).unresolved as unknown[]) : [],
        }
      : null;

  return {
    scope,
    done: "number" === typeof record.done ? record.done : 0,
    total: "number" === typeof record.total ? record.total : 0,
    terminal: true === record.terminal,
    verdict,
  };
}

/** Les caches périmés par un calcul de trajets : adversaires, radar de conflits, matrice. */
export const TRAVEL_INVALIDATION_KEYS = [["opponents"], ["fixtures", "conflicts"], ["wizard", "venue_travel_times"]] as const;

// --- gestionnaire singleton (ref-compté : plusieurs écrans, une connexion) -----

let refs = 0;
let source: EventSource | null = null;
let retryTimer: ReturnType<typeof setTimeout> | null = null;
let invalidateTimer: ReturnType<typeof setTimeout> | null = null;
let connected = false;
let latest: TravelStreamEvent | null = null;
const listeners = new Set<() => void>();

export interface TravelStreamState {
  connected: boolean;
  latest: TravelStreamEvent | null;
}

let state: TravelStreamState = { connected, latest };
const EMPTY_STATE: TravelStreamState = { connected: false, latest: null };

function publish(): void {
  state = { connected, latest };
  for (const listener of listeners) {
    listener();
  }
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

function getState(): TravelStreamState {
  return state;
}

function setConnected(value: boolean): void {
  if (connected !== value) {
    connected = value;
    publish();
  }
}

function teardown(): void {
  if (null !== retryTimer) {
    clearTimeout(retryTimer);
    retryTimer = null;
  }
  source?.close();
  source = null;
  setConnected(false);
}

function scheduleRetry(queryClient: QueryClient): void {
  if (null !== retryTimer) {
    return;
  }
  retryTimer = setTimeout(() => {
    retryTimer = null;
    if (refs > 0) {
      void open(queryClient);
    }
  }, RETRY_MS);
}

/** Invalidation DÉBOUNCÉE : une rafale d'événements (paliers de 5) ne déclenche qu'un refetch. */
function scheduleInvalidate(queryClient: QueryClient): void {
  if (null !== invalidateTimer) {
    return;
  }
  invalidateTimer = setTimeout(() => {
    invalidateTimer = null;
    for (const queryKey of TRAVEL_INVALIDATION_KEYS) {
      void queryClient.invalidateQueries({ queryKey: [...queryKey] });
    }
  }, INVALIDATE_DEBOUNCE_MS);
}

async function open(queryClient: QueryClient): Promise<void> {
  try {
    const { travelTopic } = await api.get("mercure/auth").json<{ travelTopic: string }>();
    if (0 === refs || null !== source) {
      return; // relâché (ou rouvert) pendant l'aller-retour d'auth
    }
    const stream = new EventSource(`/.well-known/mercure?topic=${encodeURIComponent(travelTopic)}`);
    source = stream;
    stream.onopen = () => setConnected(true);
    stream.onmessage = (event: MessageEvent<string>) => {
      const parsed = parseTravelEvent(event.data);
      if (null !== parsed) {
        latest = parsed;
        publish();
        scheduleInvalidate(queryClient);
      }
    };
    stream.onerror = () => {
      teardown();
      scheduleRetry(queryClient);
    };
  } catch {
    setConnected(false);
    scheduleRetry(queryClient);
  }
}

/** Prend une référence sur le flux (l'ouvre au premier preneur) ; rend le release. */
export function acquireTravelStream(queryClient: QueryClient): () => void {
  refs += 1;
  if (1 === refs) {
    void open(queryClient);
  }
  let released = false;

  return () => {
    if (released) {
      return;
    }
    released = true;
    refs -= 1;
    if (0 === refs) {
      teardown();
    }
  };
}

/**
 * Tient le flux ouvert tant que `active` (un calcul de trajets en cours quelque part) et rend
 * son état `{connected, latest}` — la jauge et le verdict de la modale/de l'écran s'en servent.
 */
export function useTravelStream(active: boolean): TravelStreamState {
  const queryClient = useQueryClient();
  useEffect(() => {
    if (!active) {
      return;
    }

    return acquireTravelStream(queryClient);
  }, [active, queryClient]);

  return useSyncExternalStore(subscribe, getState, () => EMPTY_STATE);
}
