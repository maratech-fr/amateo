import { useQuery } from "@tanstack/react-query";

import { useAuthStore } from "@/shared/stores/authStore";

import { getDeploymentNotice } from "./api";

/**
 * L'annonce de mise en production, lue par le bandeau global. Enabled sur l'auth (pas d'appel
 * sur les écrans publics). `retry: false` : une lecture en échec laisse `data` indéfini → le
 * bandeau ne rend rien plutôt que de clignoter sur un réseau instable. Sondé toutes les 5 min :
 * une programmation peut naître ou être annulée pendant qu'un club travaille.
 */
export function useDeploymentNotice() {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated);
  return useQuery({
    queryKey: ["deployment-notice"],
    queryFn: getDeploymentNotice,
    enabled: isAuthenticated,
    retry: false,
    refetchInterval: 5 * 60_000,
  });
}
