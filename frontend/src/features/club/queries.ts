import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { toast } from "@/shared/stores/toastStore";

import type { AppearancePayload } from "./api";
import * as clubApi from "./api";

/** P1-3 §4bis pt 5 — catalogue des offres (sans montant, bêta absente). Quasi-statique. */
export function useSubscriptionPlans() {
  return useQuery({ queryKey: ["subscription_plans"], queryFn: clubApi.listSubscriptionPlans, staleTime: 300_000 });
}

/**
 * P3-22 — stats d'utilisation des gymnases sur [from, to] (défaut = saison). Le
 * calcul est SERVEUR ; on ne requête que si un planning est en vigueur (`enabled`).
 */
export function useVenueUsageStats(from?: string, to?: string, enabled = true) {
  return useQuery({
    queryKey: ["venue-usage-stats", from ?? null, to ?? null],
    queryFn: () => clubApi.getVenueUsageStats(from, to),
    enabled,
    staleTime: 60_000,
  });
}

/** Save the club accent; refetch /me so the theme re-applies live. */
export function useUpdateAppearance() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: AppearancePayload) => clubApi.updateAppearance(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["me"] }),
  });
}

/**
 * Pose le siège du club depuis un libellé d'adresse ; refetch /me (le siège + ses coordonnées
 * vivent sur `me.club`). Le voile global d'enregistrement couvre l'attente — aucun spinner ajouté.
 */
export function useUpdateSiege() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (address: string) => clubApi.updateSiege(address),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["me"] }),
  });
}

/** Ré-import FFBB (management) : la fédération fait autorité sur les champs qu'elle fournit. */
export function useFfbbImport() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => clubApi.ffbbImport(),
    onSuccess: (result) => {
      if (result.populated) {
        toast.success("Informations actualisées depuis la FFBB.");
      } else {
        // 200 avec populated=false : la FFBB a répondu mais n'a rien trouvé pour ce code.
        toast.error("La FFBB n'a rien renvoyé pour ce club.");
      }
      void queryClient.invalidateQueries({ queryKey: ["me"] });
    },
    onError: () => toast.error("FFBB indisponible, réessayez plus tard."),
  });
}

export function useUploadLogo() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (file: File) => clubApi.uploadLogo(file),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["me"] }),
  });
}

export function useDeleteLogo() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => clubApi.deleteLogo(),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["me"] }),
  });
}

/** Wipe all club data; invalidate every query so the emptied state reloads. */
export function useResetClub() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => clubApi.resetClub(),
    onSuccess: (result) => {
      const s = result.deleted > 1 ? "s" : "";
      toast.success(`Club réinitialisé (${result.deleted} élément${s} supprimé${s}).`);
      void queryClient.invalidateQueries();
    },
  });
}

/** RGPD portabilité — export JSON du workspace du club (management). */
export function useDownloadClubExport() {
  return useMutation({
    mutationFn: () => clubApi.downloadClubExport(),
    onSuccess: () => toast.success("Export téléchargé."),
  });
}
