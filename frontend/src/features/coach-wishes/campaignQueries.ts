import { useMutation, useQuery, useQueryClient, type UseMutationResult } from "@tanstack/react-query";

import { toast } from "@/shared/stores/toastStore";

import * as campaignApi from "./campaignApi";
import type { CoachWishCampaign, CoachWishCampaignPayload } from "./campaignApi";
import { COACH_WISHES_MANAGER_TEXTS } from "./texts/manager";

/**
 * Toutes les campagnes de collecte du club (une par période) — le radar y lit les
 * compteurs de réponse et retrouve la campagne d'une carte par son calendarEntryId.
 * Une seule requête pour tout le radar (pas un hook par carte : règles des hooks).
 */
export function useCoachWishCampaigns() {
  return useQuery({
    queryKey: ["coach-wish-campaigns"],
    queryFn: () => campaignApi.listCoachWishCampaigns(),
    staleTime: 30_000,
  });
}

export function useCreateCoachWishCampaign() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: CoachWishCampaignPayload) => campaignApi.createCoachWishCampaign(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["coach-wish-campaigns"] }),
  });
}

export function useUpdateCoachWishCampaign() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: CoachWishCampaignPayload }) => campaignApi.updateCoachWishCampaign(id, body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["coach-wish-campaigns"] }),
  });
}

/** C3 — envoi des liens par email (global sans coachIds, ciblé avec). */
export function useSendCampaignLinks() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, coachIds }: { id: string; coachIds?: string[] }) => campaignApi.sendCampaignLinks(id, coachIds),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["coach-wish-campaigns"] }),
  });
}

/** Choisir qui répond pour une équipe. L'échec se dit ici : le geste n'a pas d'autre retour à l'écran. */
export function useSetCampaignRecipient(options?: { onSuccess?: (result: { campaign: CoachWishCampaign }) => void }): UseMutationResult<{ campaign: CoachWishCampaign }, unknown, { id: string; teamId: string; coachId: string }> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, teamId, coachId }: { id: string; teamId: string; coachId: string }) => campaignApi.setCampaignRecipient(id, teamId, coachId),
    onSuccess: (result) => {
      options?.onSuccess?.(result);
      return queryClient.invalidateQueries({ queryKey: ["coach-wish-campaigns"] });
    },
    onError: () => toast.error(COACH_WISHES_MANAGER_TEXTS.recipientSaveFailed),
  });
}

/** C3 — relance des silencieux (bloquée le reste de la journée après un clic). */
export function useRemindCampaignSilent() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => campaignApi.remindCampaignSilent(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["coach-wish-campaigns"] }),
  });
}

/**
 * D1 — aperçu de l'e-mail du lien coach, chargé À LA DEMANDE (`enabled` = fenêtre d'aperçu
 * ouverte). On ne précharge pas : c'est un rendu serveur, inutile tant que le gestionnaire ne le
 * demande pas.
 */
export function useCoachWishEmailPreview(id: string, enabled: boolean) {
  return useQuery({
    queryKey: ["coach-wish-campaign-email-preview", id],
    queryFn: () => campaignApi.getCoachWishEmailPreview(id),
    enabled,
    staleTime: 60_000,
  });
}
