import { MutationCache, QueryCache, QueryClient } from "@tanstack/react-query";
import { HTTPError } from "ky";

import { errorMessage } from "@/shared/lib/errorMessage";
import { toast } from "@/shared/stores/toastStore";

/**
 * Surface every failure to the user (FRT-01/02). Mutations always toast on
 * error; queries toast only on a *first* load failure (a failed background
 * refetch that still has cached data to show stays silent).
 *
 * TanStack Query calls these cache-level onError handlers ONCE per error, after
 * all retries are exhausted (not per attempt) — so `retry: 1` yields a single
 * toast, not two.
 */
function report(error: unknown): void {
  // 401 is handled by the ky client (session cleared + redirect to /login).
  if (error instanceof HTTPError && error.response.status === 401) {
    return;
  }
  void errorMessage(error).then((message) => toast.error(message));
}

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, staleTime: 30_000, refetchOnWindowFocus: false },
  },
  mutationCache: new MutationCache({
    // A useMutation with a hook-level onError OWNS its error feedback (it must
    // toast or dialog itself — hook-level callbacks survive unmount, unlike
    // mutate()-level ones). The global net catches everything else, so a failure
    // never toasts twice and never stays silent.
    onError: (error, _variables, _context, mutation) => {
      if (mutation.options.onError) {
        return;
      }
      report(error);
    },
  }),
  queryCache: new QueryCache({
    onError: (error, query) => {
      if (query.state.data !== undefined) {
        return; // stale data is still on screen — don't nag on a background refetch
      }
      // Queries flagged meta.silent404 handle a 404 themselves (deleted calendar
      // entry → clean period-mode exit, not a raw error toast).
      if (query.meta?.silent404 === true && error instanceof HTTPError && error.response.status === 404) {
        return;
      }
      // Queries flagged meta.ownsError SAY their failure themselves (with a retry) — the generic
      // toast would only double it (aperçu d'une fermeture de gymnase).
      if (query.meta?.ownsError === true) {
        return;
      }
      // Queries flagged meta.silentFailure are BACKGROUND reads whose failure stays silent (no
      // retry to offer, nothing to say — the deployment notice banner simply doesn't show).
      if (query.meta?.silentFailure === true) {
        return;
      }
      report(error);
    },
  }),
});
