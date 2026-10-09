import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import * as mutualizationApi from "./mutualizationApi";
import type { CoachWishMutualizationPayload } from "./mutualizationApi";

/** Les demandes de mutualisation d'une période, clé sur l'entrée MÈRE des vacances. */
export function useCoachWishMutualizations(calendarEntryId: string | null) {
  return useQuery({
    queryKey: ["coach-wish-mutualizations", calendarEntryId],
    queryFn: () => mutualizationApi.listCoachWishMutualizations(calendarEntryId as string),
    enabled: null !== calendarEntryId,
    staleTime: 30_000,
  });
}

export function useCreateCoachWishMutualization() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: CoachWishMutualizationPayload) => mutualizationApi.createCoachWishMutualization(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["coach-wish-mutualizations"] }),
  });
}

export function useUpdateCoachWishMutualization() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: CoachWishMutualizationPayload }) => mutualizationApi.updateCoachWishMutualization(id, body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["coach-wish-mutualizations"] }),
  });
}

export function useDeleteCoachWishMutualization() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => mutualizationApi.deleteCoachWishMutualization(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["coach-wish-mutualizations"] }),
  });
}
