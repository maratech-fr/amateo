import type { QueryClient } from "@tanstack/react-query";
import { useQueryClient } from "@tanstack/react-query";
import { useEffect } from "react";

import { api } from "@/shared/api/client";

/**
 * La consommation Mercure du PLACEMENT ASYNCHRONE des matchs, en UN seul endroit. Patron exact de
 * `features/planning/lib/scheduleStream.ts` / `shared/lib/travelStream.ts`.
 *
 * Le worker ({@link PlaceMatchesHandler}, backend) solve dans un conteneur à part puis publie la
 * bascule TERMINALE `{runId, status}` (COMPLETED / FAILED) sur le topic PRIVÉ FIXE
 * `club:{clubId}:placement`. Auth : le cookie httpOnly de `GET /api/mercure/auth`, dont la réponse
 * porte `placementTopic` (le front ne connaît pas son clubId — tenant résolu serveur).
 *
 * Mercure reste BEST-EFFORT : à réception on INVALIDE les caches react-query — le GET
 * `placement-run` reste la vérité, relu derrière l'invalidation. Repli sans flux : l'écran relit
 * aussi le GET au retour sur l'onglet / à la reconnexion (cf. CalendarPage), jamais un polling
 * permanent.
 */

const RETRY_MS = 10_000;

export interface PlacementStreamEvent {
  runId: string | null;
  status: string | null;
  /** COMPLETED / FAILED — le publieur ne pousse que des bascules terminales. */
  terminal: boolean;
}

/** Parse un événement du hub — `null` pour tout ce qui n'est pas un objet JSON de placement. */
export function parsePlacementEvent(raw: string): PlacementStreamEvent | null {
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
  const status = "string" === typeof record.status ? record.status : null;

  return {
    runId: "string" === typeof record.runId ? record.runId : null,
    status,
    terminal: "COMPLETED" === status || "FAILED" === status,
  };
}

/** Les caches périmés par la fin d'un run : le run lui-même (relu pour son résultat) et les
 * rencontres (les placements viennent d'être appliqués). */
export const PLACEMENT_INVALIDATION_KEYS = [["fixtures", "placement-run"], ["fixtures"]] as const;

// --- gestionnaire singleton (ref-compté : plusieurs écrans, une connexion) -----

let refs = 0;
let source: EventSource | null = null;
let retryTimer: ReturnType<typeof setTimeout> | null = null;

function teardown(): void {
  if (null !== retryTimer) {
    clearTimeout(retryTimer);
    retryTimer = null;
  }
  source?.close();
  source = null;
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

function invalidateAll(queryClient: QueryClient): void {
  for (const queryKey of PLACEMENT_INVALIDATION_KEYS) {
    void queryClient.invalidateQueries({ queryKey: [...queryKey] });
  }
}

async function open(queryClient: QueryClient): Promise<void> {
  try {
    const { placementTopic } = await api.get("mercure/auth").json<{ placementTopic: string }>();
    if (0 === refs || null !== source) {
      return; // relâché (ou rouvert) pendant l'aller-retour d'auth
    }
    const stream = new EventSource(`/.well-known/mercure?topic=${encodeURIComponent(placementTopic)}`);
    source = stream;
    stream.onmessage = (event: MessageEvent<string>) => {
      // Tout événement du hub (terminal, le seul publié) rend le run et les rencontres périmés :
      // l'écran relit le GET derrière l'invalidation et affiche le résultat.
      if (null !== parsePlacementEvent(event.data)) {
        invalidateAll(queryClient);
      }
    };
    stream.onerror = () => {
      teardown();
      scheduleRetry(queryClient);
    };
  } catch {
    scheduleRetry(queryClient);
  }
}

/** Prend une référence sur le flux (l'ouvre au premier preneur) ; rend le release. */
export function acquirePlacementStream(queryClient: QueryClient): () => void {
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

/** Tient le flux ouvert tant que `active` (un run de placement en cours). */
export function usePlacementStream(active: boolean): void {
  const queryClient = useQueryClient();
  useEffect(() => {
    if (!active) {
      return;
    }

    return acquirePlacementStream(queryClient);
  }, [active, queryClient]);
}
