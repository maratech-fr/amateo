import { useMemo } from "react";

import type { ReadState } from "@/shared/lib/readState";
import { readFailed, readLoading } from "@/shared/lib/readState";

import type { VenueMatchWindow, VenueUnavailability } from "../api";
import type { PlacementGuards } from "../PlacementPanel";

/** Une lecture qui GARDE le geste de placement : seules comptent la présence de sa donnée,
 *  son drapeau d'erreur et son `refetch` (jamais le contenu de la ligue). */
interface GuardRead<T> {
  data: T[] | undefined;
  isError: boolean;
  refetch: () => unknown;
}

/**
 * D2 — les trois lectures du club sur lesquelles s'appuie le GESTE de placement
 * (accès match, indisponibilités, enveloppe ligue). Le panneau suspend le geste
 * tant qu'elles ne sont pas prêtes : un échec de première lecture ne doit jamais
 * se lire « aucune restriction » et laisser poser un match dans un gymnase
 * restreint. Le reste du panneau (dé-placer, verrouiller…) reste actif.
 */
export function usePlacementGuards(
  matchWindows: GuardRead<VenueMatchWindow>,
  unavailabilities: GuardRead<VenueUnavailability>,
  leagueWindows: { data: unknown; isError: boolean; refetch: () => unknown },
): PlacementGuards {
  return useMemo<PlacementGuards>(() => {
    const state: ReadState =
      readFailed(matchWindows) || readFailed(unavailabilities) || readFailed(leagueWindows)
        ? "failed"
        : readLoading(matchWindows) || readLoading(unavailabilities) || readLoading(leagueWindows)
          ? "loading"
          : "ready";
    return {
      state,
      matchWindows: matchWindows.data ?? [],
      unavailabilities: unavailabilities.data ?? [],
      retry: () => {
        void matchWindows.refetch();
        void unavailabilities.refetch();
        void leagueWindows.refetch();
      },
    };
  }, [matchWindows, unavailabilities, leagueWindows]);
}
