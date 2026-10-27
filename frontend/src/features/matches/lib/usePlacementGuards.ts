import { useMemo } from "react";

import type { ReadState } from "@/shared/lib/readState";
import { readFailed, readLoading } from "@/shared/lib/readState";

import type { VenueClosure, VenueMatchWindow, VenueUnavailability } from "../api";
import type { PlacementGuards } from "../PlacementPanel";

/** Une lecture qui GARDE le geste de placement : seules comptent la présence de sa donnée,
 *  son drapeau d'erreur et son `refetch` (jamais le contenu de la ligue). */
interface GuardRead<T> {
  data: T[] | undefined;
  isError: boolean;
  refetch: () => unknown;
}

/**
 * D2 — les CINQ lectures du club sur lesquelles s'appuie le GESTE de placement
 * (accès match, indisponibilités, fermetures du calendrier — P4-300 —, enveloppe
 * ligue, et — PR2 — les jours fériés du plan/saison, feed GET /api/public-holidays).
 * Le panneau suspend le geste tant qu'elles ne sont pas prêtes : un échec de première
 * lecture ne doit jamais se lire « aucune restriction » (ni « aucun férié ») et laisser
 * poser un match dans un gymnase restreint. Le reste du panneau (dé-placer,
 * verrouiller…) reste actif.
 */
export function usePlacementGuards(
  matchWindows: GuardRead<VenueMatchWindow>,
  unavailabilities: GuardRead<VenueUnavailability>,
  closures: GuardRead<VenueClosure>,
  leagueWindows: { data: unknown; isError: boolean; refetch: () => unknown },
  holidays: GuardRead<string>,
): PlacementGuards {
  return useMemo<PlacementGuards>(() => {
    const state: ReadState =
      readFailed(matchWindows) || readFailed(unavailabilities) || readFailed(closures) || readFailed(leagueWindows) || readFailed(holidays)
        ? "failed"
        : readLoading(matchWindows) || readLoading(unavailabilities) || readLoading(closures) || readLoading(leagueWindows) || readLoading(holidays)
          ? "loading"
          : "ready";
    return {
      state,
      matchWindows: matchWindows.data ?? [],
      unavailabilities: unavailabilities.data ?? [],
      closures: closures.data ?? [],
      holidays: holidays.data ?? [],
      retry: () => {
        void matchWindows.refetch();
        void unavailabilities.refetch();
        void closures.refetch();
        void leagueWindows.refetch();
        void holidays.refetch();
      },
    };
  }, [matchWindows, unavailabilities, closures, leagueWindows, holidays]);
}
