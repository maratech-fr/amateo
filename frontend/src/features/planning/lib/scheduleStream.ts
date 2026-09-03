import type { ScheduleStatus } from "@/shared/lib/scheduleStatus";
import { isTerminalStatus } from "@/shared/lib/scheduleStatus";
import type { QueryClient, QueryKey } from "@tanstack/react-query";
import { useQueryClient } from "@tanstack/react-query";
import { useEffect, useSyncExternalStore } from "react";

import { api } from "@/shared/api/client";

/**
 * FRT-04 — la consommation Mercure côté front, en UN seul endroit.
 *
 * Le temps réel appartient au PLANNING — remonté de `shared/` le 2026-08-22 (P4-123) :
 * seuls planning et wizard l'écoutent, ce n'est pas du socle partagé.
 *
 * Le backend publie l'avancement des générations sur `club:{clubId}:schedule:{id}`
 * (topics privés, SEC-05/06) ; jusqu'ici personne n'écoutait — le front pollait à
 * 2,5 s. Ce module ouvre UN EventSource par session, abonné au TEMPLATE du club
 * (`club:X:schedule:{id}` tel quel — le hub matche chaque topic exact contre lui,
 * délivrance prouvée) : toutes les générations du club arrivent sur la même
 * connexion, sans connaître leurs ids à l'avance.
 *
 * L'authentification est un cookie httpOnly posé par `GET /api/mercure/auth`
 * (path `/.well-known/mercure`, même origine via les proxys vite/nginx) : le JS
 * ne voit jamais le jeton hub. La réponse porte aussi `topicTemplate` — le front
 * ne connaît pas son clubId (tenant résolu serveur), c'est sa seule source.
 *
 * Mercure reste BEST-EFFORT (le publieur avale ses échecs) : à réception on
 * INVALIDE les caches react-query — le serveur reste la source de vérité, on ne
 * recopie jamais le payload dans le cache — et le polling ne meurt pas, il
 * ralentit (fallback) tant que le flux est connecté.
 */

const RETRY_MS = 10_000;

export type ScheduleStreamEvent = {
  scheduleId: string | null;
  status: string | null;
  /** COMPLETED / FAILED / failed / … — tout sauf les deux statuts en vol. */
  terminal: boolean;
};

/** Parse un événement du hub — `null` pour tout ce qui n'est pas un objet JSON. */
export function parseScheduleEvent(raw: string): ScheduleStreamEvent | null {
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
  const scheduleId = "string" === typeof record.scheduleId ? record.scheduleId : null;
  const status = "string" === typeof record.status ? record.status : null;

  // D-31 : la négation inline des statuts en vol était une 5e copie de la liste.
  return { scheduleId, status, terminal: isTerminalStatus(status as ScheduleStatus | null) };
}

/**
 * Les caches à invalider pour un événement. Toujours la liste des plannings et le
 * statut suivi par le wizard ; un statut TERMINAL rend aussi périmés les créneaux
 * et diagnostics de CE planning (le résultat vient d'être importé). Sans
 * scheduleId (défense : vieux payload), on élargit au préfixe — trop large vaut
 * mieux qu'un écran figé.
 */
export function invalidationKeysFor(event: ScheduleStreamEvent): QueryKey[] {
  const keys: QueryKey[] = [
    ["schedules"],
    null !== event.scheduleId ? ["wizard", "schedule_status", event.scheduleId] : ["wizard", "schedule_status"],
  ];
  if (event.terminal) {
    keys.push(
      null !== event.scheduleId ? ["slots", event.scheduleId] : ["slots"],
      null !== event.scheduleId ? ["diagnostics", event.scheduleId] : ["diagnostics"],
    );
  }

  return keys;
}

// --- gestionnaire singleton (ref-compté : plusieurs écrans, une connexion) -----

let refs = 0;
let source: EventSource | null = null;
let retryTimer: ReturnType<typeof setTimeout> | null = null;
let connected = false;
const connectedListeners = new Set<() => void>();

/**
 * P4-168 — diagnostic OBSERVABLE du flux, pour qu'un témoin (DOM + e2e) prouve que le
 * planning est livré par SSE et non par le polling de secours.
 *
 * `eventsReceived` est un compteur MONOTONE des événements Mercure reçus. Il ne se remet
 * jamais à zéro — surtout pas à la fermeture du flux : quand la génération se termine, le
 * flux se relâche (plus rien « en vol », `useScheduleStream(false)`), donc `connected`
 * retombe AVANT que l'écran n'affiche le planning. Seul un compteur qui SURVIT à cette
 * fermeture peut encore témoigner, à ce moment-là, qu'un événement SSE a bien été délivré.
 * C'est lui le témoin robuste : `eventsReceived >= 1` ⇔ livré par SSE ; `0` ⇔ livré par le
 * repli polling (hub muet). `connected` seul serait un faux négatif post-livraison.
 */
export type ScheduleStreamDiagnostics = { connected: boolean; eventsReceived: number };

let eventsReceived = 0;
// Snapshot STABLE (même référence entre deux changements) : requis par `useSyncExternalStore`,
// qui compare par `Object.is` — un objet neuf à chaque lecture bouclerait.
let diagnostics: ScheduleStreamDiagnostics = { connected, eventsReceived };
const EMPTY_DIAGNOSTICS: ScheduleStreamDiagnostics = { connected: false, eventsReceived: 0 };
const diagnosticsListeners = new Set<() => void>();

function publishDiagnostics(): void {
  diagnostics = { connected, eventsReceived };
  for (const listener of diagnosticsListeners) {
    listener();
  }
}

function setConnected(value: boolean): void {
  if (connected !== value) {
    connected = value;
    for (const listener of connectedListeners) {
      listener();
    }
    publishDiagnostics();
  }
}

/** Lu par les `refetchInterval` : flux connecté → le polling passe en fallback lent. */
export function isScheduleStreamConnected(): boolean {
  return connected;
}

/** Diagnostic courant du flux (état pur côté client — aucune règle métier). */
export function getScheduleStreamDiagnostics(): ScheduleStreamDiagnostics {
  return diagnostics;
}

function subscribeScheduleStreamDiagnostics(listener: () => void): () => void {
  diagnosticsListeners.add(listener);
  return () => diagnosticsListeners.delete(listener);
}

/** Abonnement React au diagnostic du flux (pour le témoin DOM). */
export function useScheduleStreamDiagnostics(): ScheduleStreamDiagnostics {
  return useSyncExternalStore(subscribeScheduleStreamDiagnostics, getScheduleStreamDiagnostics, () => EMPTY_DIAGNOSTICS);
}

function subscribeConnected(listener: () => void): () => void {
  connectedListeners.add(listener);
  return () => connectedListeners.delete(listener);
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

async function open(queryClient: QueryClient): Promise<void> {
  try {
    // D'abord le cookie (TTL 1 h) — CHAQUE (ré)ouverture ré-authentifie : c'est ce
    // qui rattrape un cookie expiré, EventSource seul rejouerait l'ancien à vie.
    const { topicTemplate } = await api.get("mercure/auth").json<{ topicTemplate: string }>();
    if (0 === refs || null !== source) {
      return; // relâché (ou rouvert) pendant l'aller-retour d'auth
    }
    const stream = new EventSource(`/.well-known/mercure?topic=${encodeURIComponent(topicTemplate)}`);
    source = stream;
    stream.onopen = () => setConnected(true);
    stream.onmessage = (event: MessageEvent<string>) => {
      const parsed = parseScheduleEvent(event.data);
      if (null !== parsed) {
        // P4-168 — un événement d'avancement REÇU : le témoin monte. Un payload illisible
        // (parsed === null) n'est pas un événement du hub, il ne compte pas.
        eventsReceived += 1;
        publishDiagnostics();
        for (const queryKey of invalidationKeysFor(parsed)) {
          void queryClient.invalidateQueries({ queryKey });
        }
      }
    };
    // Erreur = on reprend la main (pas la reconnexion native : elle ne ré-authentifie
    // pas). Fermer déclenche le fallback polling à 2,5 s, puis on retentera.
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
export function acquireScheduleStream(queryClient: QueryClient): () => void {
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
 * Tient le flux ouvert tant que `active` (une génération en vol quelque part) et
 * rend `connected` — les sites de polling s'en servent pour ralentir leur cadence.
 */
export function useScheduleStream(active: boolean): boolean {
  const queryClient = useQueryClient();
  useEffect(() => {
    if (!active) {
      return;
    }

    return acquireScheduleStream(queryClient);
  }, [active, queryClient]);

  return useSyncExternalStore(subscribeConnected, isScheduleStreamConnected, () => false);
}
