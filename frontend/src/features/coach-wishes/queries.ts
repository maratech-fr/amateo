import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import * as coachWishApi from "./api";
import type { CoachWishPayload } from "./api";

/** La todo-list des doléances d'une période, clé sur l'entrée MÈRE des vacances. */
export function useCoachWishes(calendarEntryId: string | null) {
  return useQuery({
    queryKey: ["coach-wishes", calendarEntryId],
    queryFn: () => coachWishApi.listCoachWishes(calendarEntryId as string),
    enabled: null !== calendarEntryId,
    staleTime: 30_000,
  });
}

export function useCreateCoachWish() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: CoachWishPayload) => coachWishApi.createCoachWish(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["coach-wishes"] }),
  });
}

export function useUpdateCoachWish() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: CoachWishPayload }) => coachWishApi.updateCoachWish(id, body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["coach-wishes"] }),
  });
}

export function useDeleteCoachWish() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => coachWishApi.deleteCoachWish(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["coach-wishes"] }),
  });
}

/**
 * P2-63 PR 5 — « Transférer au planning » : convertit les doléances cochées en réglages de
 * plan. Invalide les doléances (les converties passent « traité ») ET les objets de plan que
 * le transfert écrit (overrides d'équipe, contraintes) pour que le wizard les reflète sans
 * rechargement. Le toast de RÉCAP est posé par l'appelant en `onSuccess` ; les erreurs (dont le
 * 409 nommé) restent au filet global des mutations (règle FRT-38 : pas de toast d'erreur ici).
 */
export function useTransferCoachWishes() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (wishIds: string[]) => coachWishApi.transferCoachWishes(wishIds),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["coach-wishes"] });
      // Les objets de plan que le transfert écrit vivent sous la clé "wizard"
      // (team_period_overrides + constraints d'une période) : on invalide le sous-arbre.
      void queryClient.invalidateQueries({ queryKey: ["wizard"] });
    },
  });
}
